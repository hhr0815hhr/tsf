<?php

namespace TSF\Transport\Tcp;

use TSF\Kernel\Logger;
use TSF\Service\Scheduler;
use TSF\Transport\Server;

/**
 * TCP 传输：Swoole\Server 的字节流 → 完整帧。
 */
final class TcpServer extends Server
{
    private ?\Swoole\Server $server = null;

    public function start(): void
    {
        $maxConn = isset($this->options["max_conn"]) ? (int) $this->options["max_conn"] : 10000;
        $this->ensureFdLimit($maxConn);
        $this->ensureMemoryLimit($maxConn);

        $server = new \Swoole\Server($this->host, $this->port);
        $server->set([
            "worker_num" => isset($this->options["worker_num"]) ? (int) $this->options["worker_num"] : 1,
            "reactor_num" => isset($this->options["reactor_num"]) ? (int) $this->options["reactor_num"] : 1,
            "max_conn" => $maxConn,
            // Swoole 默认 backlog 只有 512。1 万连接同时建连时（开服/重连风暴）
            // accept 队列会溢出，内核直接回 RST，客户端看到 EPIPE——现象是
            // "连上了但发不出数据"，很容易被误判成吞吐不足。8k 对单机足够。
            "backlog" => isset($this->options["backlog"]) ? (int) $this->options["backlog"] : 8192,
            // Swoole 默认每连接输出缓冲 2MB。对有输出的连接（回包/推送），
            // 1 万连接就是 20GB 的上限，实际表现为"连接一多就 OOM"——
            // 实测 1 万连接时进程尝试分配 2MB 失败、内存超 512MB 退出。
            // 64KB 对协议帧来说足够（单帧上限本身就是 1MB，但队列深度不需要那么深）。
            "buffer_output_size" => isset($this->options["buffer_output_size"])
                ? (int) $this->options["buffer_output_size"] : 65536,
            // Swoole 默认给每个回调开协程（enable_coroutine=true），协程栈默认 2MB。
            // 连接数上千后内存会按连接数膨胀，实测 8,500 连接时进程因一次 2MB 分配
            // 失败而 OOM 退出。我们的处理逻辑是同步的、不用协程 I/O，关掉即可。
            "enable_coroutine" => isset($this->options["enable_coroutine"])
                ? (bool) $this->options["enable_coroutine"] : false,
            "open_cpu_affinity" => false,
            // 游戏服惯例开 nodelay（服务端主动推送小包时避免合并延迟）。
            // 实测：请求-响应（闭路）场景下开关无差异（p50 均 0.23ms），
            // 推送/广播场景才会显现，故作为默认值保留但允许覆盖。
            "open_tcp_nodelay" => !isset($this->options["nodelay"]) || (bool) $this->options["nodelay"],
            "log_level" => 4,
        ]);
        $this->server = $server;

        $server->on("connect", function ($serv, int $fd, int $reactorId) {
            $this->attached($fd);
        });

        $server->on("receive", function ($serv, int $fd, int $reactorId, string $data) {
            $this->feed($fd, $data);
        });

        $server->on("close", function ($serv, int $fd, int $reactorId) {
            $this->detached($fd);
        });

        $server->on("shutdown", function ($serv) {
            Logger::info("server shutdown");
        });

        // worker 间消息：多 worker 时玩家归属、跨 worker 推送的唯一通道。
        // 不注册这个回调时，sendMessage 的消息会被静默丢弃——
        // 表现为"转发写了但对面没反应"，且没有任何日志。
        $server->on("pipeMessage", function ($serv, int $srcWorkerId, $data) {
            $this->firePipeMessage((string) $data, $srcWorkerId);
        });

        $server->on("workerStart", function ($serv, int $workerId) {
            // Swoole 的事件循环是进程里唯一的循环，Scheduler 没有自己的线程，
            // 所以只能靠一个高频定时器把它"推"着走。缺了这一步，Service 的
            // 信箱永远不会被处理——表现为"消息发出去了但业务没反应"。
            //
            // 1 ms：Service 消息最多多 1 ms 延迟；空转成本约 0.5% 单核。
            // 单轮处理上限 2000 条，避免一个满载的 tick 长时间占住事件循环。
            \Swoole\Timer::tick(1, function (int $timerId): void {
                Scheduler::instance()->tick(2000);
            });

            Logger::info("worker start", ["worker" => $workerId]);
            $this->fireWorkerStart($workerId);
        });

        Logger::info("server listen", ["proto" => "tcp", "host" => $this->host, "port" => $this->port]);
        $server->start();
    }

    protected function sendRaw(int $fd, string $frame): bool
    {
        if ($this->server === null) {
            return false;
        }
        return (bool) $this->server->send($fd, $frame);
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

    public function server(): ?\Swoole\Server
    {
        return $this->server;
    }

    /**
     * 跨 worker 投递（进程间消息，走 Swoole 的 unix socket 管道）。
     *
     * 限制：只能在 worker 进程里调用。master 进程（start 之前）调会返回 false。
     */
    public function pipeSend(int $workerId, string $data): bool
    {
        if ($this->server === null) {
            return false;
        }
        return $this->server->sendMessage($data, $workerId);
    }

    public function pipeSupported(): bool
    {
        return true;
    }

    public function workerNum(): int
    {
        return isset($this->options["worker_num"]) ? (int) $this->options["worker_num"] : 1;
    }

    public function workerId(): int
    {
        if ($this->server === null) {
            return 0;
        }
        // 在 master 进程里是 -1；夹到 0 避免调用方拿到负数做取模
        $id = (int) $this->server->worker_id;
        return $id < 0 ? 0 : $id;
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
