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

        $server = new \Swoole\WebSocket\Server($this->host, $this->port);
        $server->set([
            "worker_num" => isset($this->options["worker_num"]) ? (int) $this->options["worker_num"] : 1,
            "reactor_num" => isset($this->options["reactor_num"]) ? (int) $this->options["reactor_num"] : 1,
            "max_conn" => $maxConn,
            // 同 TcpServer：默认 512 的 backlog 撑不住开服/重连风暴
            "backlog" => isset($this->options["backlog"]) ? (int) $this->options["backlog"] : 8192,
            "open_cpu_affinity" => false,
            "open_tcp_nodelay" => !isset($this->options["nodelay"]) || (bool) $this->options["nodelay"],
            // 单条消息上限，防止恶意大帧打爆内存
            "package_max_length" => isset($this->options["max_frame"]) ? (int) $this->options["max_frame"] : 1024 * 1024,
            "log_level" => 4,
        ]);
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
        if ($this->server !== null) {
            $this->server->close($fd);
        }
    }

    public function server(): ?\Swoole\WebSocket\Server
    {
        return $this->server;
    }
}
