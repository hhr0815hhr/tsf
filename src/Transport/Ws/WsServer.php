<?php

namespace TSF\Transport\Ws;

use TSF\Kernel\Logger;
use TSF\Service\Scheduler;
use TSF\Transport\Server;

/**
 * WebSocket 传输。
 *
 * 设计要点：**WS 只当传输**，payload 仍然是 TSF 的二进制帧（8 字节头 + body）。
 * 好处是同一套 Gateway / 业务处理器 / 压测端都能复用，协议不需要写两份；
 * 浏览器端用二进制帧（ArrayBuffer）收发即可。
 *
 * 如果将来要支持"浏览器直接发 JSON"，应该加一个可选的 JSON 编解码层，
 * 而不是在传输层做分支——否则会分裂出两套派发逻辑。
 */
final class WsServer extends Server
{
    private ?\Swoole\WebSocket\Server $server = null;

    public function start(): void
    {
        $maxConn = isset($this->options["max_conn"]) ? (int) $this->options["max_conn"] : 10000;
        $this->ensureFdLimit($maxConn);
        $this->ensureMemoryLimit($maxConn);

        // WSS：两个 ssl 选项都给了就是 wss://（见 Server::sslEnabled）
        $ssl = $this->sslEnabled();
        $server = new \Swoole\WebSocket\Server(
            $this->host,
            $this->port,
            SWOOLE_PROCESS,
            $ssl ? (SWOOLE_SOCK_TCP | SWOOLE_SSL) : SWOOLE_SOCK_TCP
        );
        $server->set($this->sslOptions() + [
            "worker_num" => isset($this->options["worker_num"]) ? (int) $this->options["worker_num"] : 1,
            "reactor_num" => isset($this->options["reactor_num"]) ? (int) $this->options["reactor_num"] : 1,
            "max_conn" => $maxConn,
            // 同 TcpServer：默认 512 的 backlog 撑不住开服/重连风暴
            "backlog" => isset($this->options["backlog"]) ? (int) $this->options["backlog"] : 8192,
            "open_cpu_affinity" => false,
            "open_tcp_nodelay" => !isset($this->options["nodelay"]) || (bool) $this->options["nodelay"],
            // 单条消息上限，防止恶意大帧打爆内存
            "package_max_length" => isset($this->options["max_frame"]) ? (int) $this->options["max_frame"] : 1024 * 1024,
            // 下面两项和 TcpServer 对齐（2026-11-25 补）。之前 WS 这边漏了：
            // Swoole 默认每连接输出缓冲 2MB、每个回调开一个 2MB 栈的协程，
            // 连接数上千后内存按连接数膨胀，而 TcpServer 那边早就因为同样的问题 OOM 过。
            "buffer_output_size" => isset($this->options["buffer_output_size"])
                ? (int) $this->options["buffer_output_size"] : 65536,
            "enable_coroutine" => isset($this->options["enable_coroutine"])
                ? (bool) $this->options["enable_coroutine"] : false,
            // 停机排空窗口（秒）。语义和取舍见 Server::maxWaitTime()
            "max_wait_time" => $this->maxWaitTime(),
            "log_level" => 4,
        ]);
        if ($ssl) {
            Logger::info("tls enabled", ["proto" => "ws", "cert" => $this->options["ssl_cert_file"]]);
        }
        $this->server = $server;

        $server->on("open", function ($serv, $request) {
            $this->attached((int) $request->fd);
        });

        $server->on("message", function ($serv, $frame) {
            // WS 的 payload 就是 TSF 帧（可能是多帧拼接），交给同一个拆包循环
            $this->feed((int) $frame->fd, (string) $frame->data);
        });

        $server->on("close", function ($serv, int $fd, int $reactorId) {
            $this->detached($fd);
        });

        $server->on("shutdown", function ($serv) {
            Logger::info("server shutdown");
        });

        $server->on("workerStart", function ($serv, int $workerId) {
            // 同 TcpServer：用定时器推动 Service 调度器，否则信箱永远不被处理
            \Swoole\Timer::tick(1, function (int $timerId): void {
                Scheduler::instance()->tick(2000);
            });

            Logger::info("worker start", ["worker" => $workerId]);
            $this->fireWorkerStart($workerId);
        });

        // worker 退出前：停机钩子 → 服务排空 → 逐个 onStop（同 TcpServer）
        $server->on("workerStop", function ($serv, int $workerId) {
            $this->fireWorkerStop($workerId);
        });

        Logger::info("server listen", ["proto" => "ws", "host" => $this->host, "port" => $this->port]);
        $server->start();
    }

    protected function sendRaw(int $fd, string $frame): bool
    {
        if ($this->server === null) {
            return false;
        }
        return (bool) $this->server->push($fd, $frame, WEBSOCKET_OPCODE_BINARY);
    }

    protected function closeConn(int $fd): void
    {
        // 关连接前把本批还没写出的回包冲掉：
        // 业务常见写法是"回一个错误包然后断开"，批量模式下那个包还在 pending 里，
        // 直接关就等于**最后一条消息永远收不到**。
        if ($this->batchSend && isset($this->pending[$fd])) {
            $buf = $this->pending[$fd];
            unset($this->pending[$fd]);
            $this->sendRaw($fd, $buf);
        }
        if ($this->server !== null) {
            $this->server->close($fd);
        }
    }

    public function peerOf(int $fd): string
    {
        if ($this->server === null) {
            return "";
        }
        $info = $this->server->getClientInfo($fd);
        if (!is_array($info)) {
            return "";
        }
        $ip = $info["remote_ip"] ?? "";
        return is_string($ip) ? $ip : "";
    }

    public function server(): ?\Swoole\WebSocket\Server
    {
        return $this->server;
    }

    /**
     * 优雅退出：Swoole 会先停止 accept，等当前事件处理完再退出。
     * 调用方负责先停止接新业务（见 demo/duel 的 drain 流程），这里只负责"关"。
     */
    public function shutdown(): void
    {
        if ($this->server !== null) {
            $this->server->shutdown();
        }
    }

}
