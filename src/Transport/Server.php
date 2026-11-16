<?php

namespace TSF\Transport;

use TSF\Kernel\Logger;
use TSF\Protocol\Contract\FrameCodec;

/**
 * 传输基类：连接生命周期 + 拆包 + 派发。
 *
 * 职责边界：
 *   - 传输层只负责"收发字节"和"连接生命周期"；
 *   - 帧怎么切由注入的 FrameCodec 决定（TCP/WS 共用同一套拆包循环，逻辑只有一份）；
 *   - 帧切出来后交给 onFrame 回调，传输层不理解 msgId 的含义。
 *
 * 子类只需实现三个原语：
 *   start()                 启动监听
 *   sendRaw(int, string)    发送一个已编码的帧
 *   closeConn(int)          关闭连接
 */
abstract class Server
{
    protected string $host;

    protected int $port;

    /** @var array<string, string|int|float|bool|array> */
    protected array $options;

    /** 帧格式。构造时必传——不存在"默认实现"的第二条分支。 */
    protected FrameCodec $frame;

    /** @var array<int, string> 每连接的半包缓冲 */
    protected array $buffers = [];

    /** @var callable|null */
    protected $onConnect = null;

    /** @var callable|null */
    protected $onFrame = null;

    /** @var callable|null */
    protected $onClose = null;

    /**
     * worker 启动钩子。**是列表，不是单个槽位**。
     *
     * 曾经是单个槽位，结果 `Gateway::boot()` 注册自己的钩子时把业务方
     * 先注册的那个静默覆盖掉了——建表没执行、日志里只有一句 "no such table"，
     * 排查了很久。凡是「多人都可能想挂一个」的生命周期点，都必须能累加。
     *
     * @var array<int, callable>
     */
    protected array $onWorkerStart = [];

    /** @param array<string, string|int|float|bool|array> $options */
    public function __construct(string $host, int $port, FrameCodec $frame, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->frame = $frame;
        $this->options = $options;
    }

    public function onConnect(callable $cb): void
    {
        $this->onConnect = $cb;
    }

    public function onFrame(callable $cb): void
    {
        $this->onFrame = $cb;
    }

    public function onClose(callable $cb): void
    {
        $this->onClose = $cb;
    }

    public function onWorkerStart(callable $cb): void
    {
        $this->onWorkerStart[] = $cb;
    }

    /** 把已注册的 worker 启动钩子依次跑一遍（子类在 Swoole 的 workerStart 里调） */
    protected function fireWorkerStart(int $workerId): void
    {
        foreach ($this->onWorkerStart as $cb) {
            if (is_callable($cb)) {
                $cb($workerId);
            }
        }
    }

    abstract public function start(): void;

    /**
     * 按 max_conn 抬高 PHP 的 memory_limit。
     *
     * Swoole 的 reactor 线程会为每个连接预留一块缓冲，并计入 memory_limit；
     * 而 php85 是 ZTS 版本——zend_mm_heap 与 memory_limit 都是 **per-thread** 的。
     *
     * 实测每连接约 64 KB 记账，但真实 RSS 只有约 5 KB（预留的页未被触碰）。
     * 于是默认 memory_limit=512M 时，单 reactor 到约 8,000 连接就报
     * "Allowed memory size ... exhausted"，而进程 RSS 才 80 MB——
     * **看起来像内存泄漏，实际是记账口径**。裸 Swoole 同样复现，与本框架无关。
     *
     * 对策：96 KB/连接（64 KB + 50% 余量）+ 256 MB 基础。
     * 必须在 fork 之前调用，子进程才会继承。
     *
     * @param int $maxConn 服务端配置的最大连接数
     */
    protected function ensureMemoryLimit(int $maxConn): void
    {
        $cur = (string) ini_get("memory_limit");
        if ($cur === "") {
            return;
        }
        $have = $this->parseBytes($cur);
        if ($have < 0) {
            return; // -1 表示不限，无需处理
        }

        $need = $maxConn * 98304 + 268435456;
        if ($have >= $need) {
            return;
        }

        $mb = intdiv($need, 1048576) + 1;
        ini_set("memory_limit", (string) $mb . "M");
        Logger::warn("memory_limit 不足，已按 max_conn 自动抬高", [
            "from" => $cur,
            "to" => (string) $mb . "M",
            "max_conn" => $maxConn,
        ]);
    }

    /**
     * 检查 fd 上限够不够 max_conn 用。
     *
     * Swoole 的实际连接上限是 `min(max_conn, SwooleG.max_sockets)`，而 max_sockets
     * 来自进程的 fd 软限制。超限时 Swoole **只打一行 warning 就继续跑**：
     *
     *   max_connection is exceed the maximum value, it's reset to 10240
     *
     * 表现是「配了 2 万，实际只能接 1 万」，服务不报错。本机 ulimit 默认就是 10240，
     * 所以这个坑很容易踩——容量悄悄减半，压测和线上都看不出来。
     *
     * PHP 没有内置 setrlimit，这里只能告警，不能自愈，所以把实际可用连接数算出来
     * 一起打，运维看一眼就知道差多少。
     */
    protected function ensureFdLimit(int $maxConn): void
    {
        $path = "/proc/self/limits";
        if (!is_readable($path)) {
            return;
        }
        $raw = (string) file_get_contents($path);
        if ($raw === "") {
            return;
        }

        $soft = self::parseMaxOpenFiles($raw);
        if ($soft <= 0) {
            return;
        }

        // 预留 128 个 fd：监听 socket、日志、上游连接、标准流
        $need = $maxConn + 128;
        if ($soft >= $need) {
            return;
        }

        $effective = $soft - 128;
        if ($effective < 0) {
            $effective = 0;
        }

        Logger::warn("fd 上限不足，实际连接数会低于 max_conn", [
            "soft_limit" => $soft,
            "max_conn" => $maxConn,
            "effective" => $effective,
            "fix" => "ulimit -n " . (string) $need
                . "；systemd: LimitNOFILE=" . (string) $need
                . "；docker: ulimits.nofile.soft=" . (string) $need,
        ]);
    }

    /**
     * 从 /proc/self/limits 里取 "Max open files" 的软限制。
     *
     * 独立成 static 是为了能单测——真实环境的 ulimit 没法在测试里随意改。
     *
     * @param string $raw /proc/self/limits 的完整内容
     * @return int 软限制；解析不出返回 0
     */
    public static function parseMaxOpenFiles(string $raw): int
    {
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if (strncmp($line, "Max open files", 14) !== 0) {
                continue;
            }
            $rest = trim(substr($line, 14));
            $parts = explode(" ", $rest);
            foreach ($parts as $p) {
                if ($p !== "") {
                    return (int) $p;
                }
            }
        }
        return 0;
    }

    /** 把 "512M" / "1G" / "-1" 解析成字节数；负值返回 -1（表示不限） */
    private function parseBytes(string $v): int
    {
        $s = trim($v);
        if ($s === "") {
            return 0;
        }
        $n = (int) $s;
        if ($n < 0) {
            return -1;
        }
        $unit = strtolower(substr($s, -1));
        if ($unit === "g") {
            return $n * 1073741824;
        }
        if ($unit === "m") {
            return $n * 1048576;
        }
        if ($unit === "k") {
            return $n * 1024;
        }
        return $n;
    }


    abstract protected function sendRaw(int $fd, string $frame): bool;

    abstract protected function closeConn(int $fd): void;

    /** 编码并发送一帧 */
    public function sendFrame(int $fd, int $msgId, string $body, int $flags = 0): bool
    {
        return $this->sendRaw($fd, $this->frame->encode($msgId, $body, $flags));
    }

    public function close(int $fd): void
    {
        $this->closeConn($fd);
    }

    public function frameCodec(): FrameCodec
    {
        return $this->frame;
    }

    /**
     * 把收到的字节喂进拆包循环。
     * TCP 的 onReceive 和 WebSocket 的 onMessage 都走这里，逻辑只有一份。
     */
    protected function feed(int $fd, string $data): void
    {
        $buf = ($this->buffers[$fd] ?? "") . $data;

        $guard = 0;
        while (true) {
            $frame = null;
            try {
                // FrameCodec::decode 返回 DecodedFrame，数据不足返回 null。
                // 消费长度由返回值携带，不用引用参数——AOT 下闭包/动态调用的引用参数
                // 要求显式 toRef()，漏写会静默不写回（曾导致同一帧被反复解析上千次）。
                $frame = $this->frame->decode($buf);
            } catch (\RuntimeException $e) {
                Logger::warn("bad frame, closing conn", ["fd" => $fd, "err" => $e->getMessage()]);
                $this->buffers[$fd] = "";
                $this->closeConn($fd);
                return;
            }

            if ($frame === null) {
                break;
            }
            $buf = substr($buf, $frame->consumed);

            if ($this->onFrame !== null) {
                $cb = $this->onFrame;
                $cb($fd, $frame->msgId, $frame->body, $frame->flags);
            }

            $guard++;
            if ($guard > 1000) {
                // 防止单个连接一次塞几千帧把其他连接饿死
                break;
            }
        }
        $this->buffers[$fd] = $buf;
    }

    protected function attached(int $fd): void
    {
        $this->buffers[$fd] = "";
        if ($this->onConnect !== null) {
            $cb = $this->onConnect;
            $cb($fd);
        }
    }

    protected function detached(int $fd): void
    {
        unset($this->buffers[$fd]);
        if ($this->onClose !== null) {
            $cb = $this->onClose;
            $cb($fd);
        }
    }
}
