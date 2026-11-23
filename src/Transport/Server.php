<?php

namespace TSF\Transport;

use TSF\Kernel\Logger;
use TSF\Service\Scheduler;
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
    /**
     * 帧回调。**类型必须是 `\Closure` 而不是无类型/callable**。
     *
     * AOT 下无类型属性是"任意值"，每次调用都要走通用 callable 解析（装箱参数、
     * 查可调用性）；声明成 Closure 后编译器能直接调 `__invoke`。
     * 这是每帧都要跑一次的路径，差别在微秒级。
     */
    protected ?\Closure $onFrame = null;

    /**
     * 批量发送：把一次 `feed()` 内产生的回包按连接合并，最后一次性写出。
     *
     * 为什么需要：pipeline 下客户端一次灌 64 帧，服务端逐帧回调、逐帧 `send`，
     * 就是 64 次 syscall + 64 次内核缓冲进出。而 Swoole 原生 echo 能跑到 196 万/s、
     * TSF 只有 15 万/s，差距不在计算，在"每帧一次写"这个模型上。
     *
     * 合并之后：一次 feed 内同一个 fd 的所有回包拼成一个 buffer，一次 `send` 写出。
     * 批内延迟最多多等"这一批处理完"（微秒级），换来 syscall 数量按批摊薄。
     *
     * 默认关：closed-loop 场景（一问一答）本来就只有一帧，开了没有收益，
     * 反而多一次数组拼接。
     */
    protected bool $batchSend = false;

    /** @var array<int, string> fd => 本批待发字节 */
    protected array $pending = [];

    /**
     * 是否正在 `feed()` 内。
     *
     * 批量模式**只在 feed 内生效**：feed 之外（定时器广播、onConnect 回包、
     * 跨服务转发）没有"批"的概念，如果也进 pending 就永远等不到 flush，
     * 消息会**静默不发**——这是比慢更严重的问题，所以必须显式区分。
     */
    protected bool $inFeed = false;

    /**
     * 是否为每个客户端帧建立一条链路（默认关）。
     *
     * 默认关的理由：这是**开销换可观测**的开关，不该替使用者决定。
     * 心跳 10Hz × 数千连接时，每帧建一个 id 是纯浪费；而"一次点击换一局结算"
     * 这种包才是链路追踪真正要覆盖的。
     *
     * 打开后：`feed()` 每切出一帧就 `beginTrace()`，之后业务 deliver 出去的消息
     * 都自然带上这条链路，一路到 eventlog 记账——不需要改任何业务代码。
     */
    protected bool $traceFrames = false;

    /**
     * 分帧采样计时（默认关）。
     *
     * 为什么要这个：pipeline 下 TSF 每帧 6.5 µs，Swoole 原生 echo 0.51 µs。
     * 差值里能算出来的只有 decode + encode 约 0.8 µs，剩下 ~4 µs 不知道在哪。
     * **在不知道分布之前选优化方案就是赌**，所以先把每段的真实占比测出来。
     *
     * 关闭时只有一次静态布尔判断（约 1 ns），不影响生产。
     * 开启时每帧多两次 `hrtime`（约 100 ns），只用于定位，不用于发布。
     */
    protected static bool $prof = false;

    protected static float $profDecodeNs = 0.0;

    protected static float $profCallNs = 0.0;

    protected static float $profSubstrNs = 0.0;

    protected static float $profLoopNs = 0.0;

    protected static int $profFrames = 0;

    public static function setProfile(bool $on): void
    {
        self::$prof = $on;
        self::$profDecodeNs = 0.0;
        self::$profCallNs = 0.0;
        self::$profSubstrNs = 0.0;
        self::$profLoopNs = 0.0;
        self::$profFrames = 0;
    }

    /**
     * 取一份快照并清零（按窗口统计，避免把启动阶段算进去）。
     *
     * @return array{n: int, decode_ns: float, call_ns: float, total_ns: float}
     */
    public static function profileSnapshot(): array
    {
        $n = self::$profFrames;
        $out = [
            "n" => $n,
            "decode_ns" => $n > 0 ? self::$profDecodeNs / $n : 0.0,
            "substr_ns" => $n > 0 ? self::$profSubstrNs / $n : 0.0,
            "call_ns" => $n > 0 ? self::$profCallNs / $n : 0.0,
            "total_ns" => $n > 0 ? self::$profLoopNs / $n : 0.0,
        ];
        self::$profDecodeNs = 0.0;
        self::$profSubstrNs = 0.0;
        self::$profCallNs = 0.0;
        self::$profLoopNs = 0.0;
        self::$profFrames = 0;
        return $out;
    }

    /** @var callable|null */
    protected $onClose = null;

    /** worker 间消息钩子；见 onPipeMessage() */
    protected ?\Closure $onPipe = null;

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

    /** 打开"每帧一条链路"。见 $traceFrames 注释里的成本说明 */
    public function traceFrames(bool $on = true): void
    {
        $this->traceFrames = $on;
    }

    public function onFrame(\Closure $cb): void
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

    /**
     * worker 间消息钩子：`$cb(string $data, int $fromWorkerId): void`。
     *
     * 存在的理由：多 worker 时进程内存不共享，玩家归属、跨 worker 推送
     * 都必须有一条显式的进程间通道。用 `?\Closure` 而不是 `callable`——
     * AOT 下 callable 是联合类型，赋给属性会被转成 stdClass，运行时调用报
     * `stdClass->{closure}()`（Gateway::onDisconnect 踩过这个坑）。
     */
    public function onPipeMessage(\Closure $cb): void
    {
        $this->onPipe = $cb;
    }

    /** 子类在 Swoole 的 pipeMessage 回调里调（TcpServer 已接好） */
    protected function firePipeMessage(string $data, int $fromWorkerId): void
    {
        $cb = $this->onPipe;
        if ($cb !== null) {
            $cb($data, $fromWorkerId);
        }
    }

    /**
     * 发给指定 worker（进程间）。
     *
     * 默认返回 false 表示"这个传输没有跨 worker 能力"。这是刻意的：
     * 单进程部署时调用方应当能区分"没发出去"和"发了但没回"。
     */
    public function pipeSend(int $workerId, string $data): bool
    {
        return false;
    }

    /** 当前传输是否支持跨 worker 投递 */
    public function pipeSupported(): bool
    {
        return false;
    }

    /** worker 数量；子类按 Swoole 配置返回，默认单 worker */
    public function workerNum(): int
    {
        return 1;
    }

    /**
     * 当前 worker 编号。
     *
     * 必须在 worker 进程内调用才有效（master 进程里是 0 或 -1）。
     * 用它来判断"这个玩家/这条连接归不归我管"。
     */
    public function workerId(): int
    {
        return 0;
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
     * 让服务器**优雅退出**：停止 accept，worker 处理完当前事件后退出。
     *
     * 存在的理由见 `demo/duel` 的 drain 流程——节点退役不是"能关就关"，
     * 要等房间打完（否则玩家的对局全靠快照恢复，白丢一局）。
     * 默认什么也不做，是为了让"没有实现关闭能力"的传输在调用处暴露成
     * "关不掉"而不是"看起来关了"。
     */
    public function shutdown(): void
    {
    }

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

    /** 打开批量发送（见 $batchSend 注释） */
    public function enableBatchSend(bool $on = true): void
    {
        $this->batchSend = $on;
    }

    /**
     * 编码并发送一帧。
     *
     * 批量模式下只累积不发送，由 `feed()` 结束前的 `flushBatch()` 统一写出。
     * 调用方语义不变（仍返回 bool），所以业务代码零改动。
     */
    public function sendFrame(int $fd, int $msgId, string $body, int $flags = 0): bool
    {
        $frame = $this->frame->encode($msgId, $body, $flags);
        if ($this->batchSend && $this->inFeed) {
            if (isset($this->pending[$fd])) {
                $this->pending[$fd] .= $frame;
            } else {
                $this->pending[$fd] = $frame;
            }
            return true;
        }
        return $this->sendRaw($fd, $frame);
    }

    /**
     * 打开批量窗口，供"一轮调度"使用。
     *
     * `feed()` 自己会开/关；这里给 `tick()` 用：服务在 `onMessage` 里发出的回包
     * 发生在 feed 之外，如果 `inFeed` 只覆盖 feed，**真实业务路径完全享受不到合并**。
     *
     * 用法（由应用在启动时注册）：
     * ```php
     * Scheduler::instance()->onBatchEnd(function () use ($server): void {
     *     $server->flushPending();
     * });
     * ```
     */
    public function beginBatch(): void
    {
        $this->inFeed = true;
    }

    public function endBatch(): void
    {
        $this->inFeed = false;
        if ($this->batchSend) {
            $this->flushBatch();
        }
    }

    public function flushPending(): void
    {
        if ($this->batchSend && !$this->inFeed) {
            $this->flushBatch();
        }
    }

    /** 把累积的回包按连接一次写出 */
    protected function flushBatch(): void
    {
        if ($this->pending === []) {
            return;
        }
        // 不复制数组再清空：那次复制在大批处理时是实打实的开销
        foreach ($this->pending as $fd => $buf) {
            // 写失败不回退：TCP 流已经不可靠，回退会让调用方以为没发过，
            // 而实际上可能有半帧落地。丢连接由上层 close 处理。
            $this->sendRaw((int) $fd, $buf);
        }
        $this->pending = [];
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
        $prof = self::$prof;
        $tFeed = $prof ? hrtime(true) : 0;

        // 自愈：上一次 `onFrame` 抛异常会让 inFeed 停在 true。
        // 如果不管，**之后所有 sendFrame 都进 pending 且永不 flush**——
        // 现象是"服务还在跑，但再也发不出消息"，比崩溃难查得多。
        //
        // 为什么不在热路径包 try/catch：实测每次进 try 装异常上下文约 300ns/帧，
        // 在这个函数里是 4–5% 的吞吐。改成"下一次进来先收拾上一次"既为零成本，
        // 窗口也只有一次 tick 那么长。
        if ($this->inFeed) {
            $this->inFeed = false;
            if ($this->batchSend) {
                $this->flushBatch();
            }
        }

        $buf = ($this->buffers[$fd] ?? "") . $data;

        $this->inFeed = true;
        $guard = 0;
        while (true) {
            $frame = null;
            $tDecode = $prof ? hrtime(true) : 0;
            try {
                // FrameCodec::decode 返回 DecodedFrame，数据不足返回 null。
                // 消费长度由返回值携带，不用引用参数——AOT 下闭包/动态调用的引用参数
                // 要求显式 toRef()，漏写会静默不写回（曾导致同一帧被反复解析上千次）。
                $frame = $this->frame->decode($buf);
            } catch (\RuntimeException $e) {
                Logger::warn("bad frame, closing conn", ["fd" => $fd, "err" => $e->getMessage()]);
                $this->buffers[$fd] = "";
                $this->inFeed = false;
                if ($this->batchSend) {
                    $this->flushBatch();   // 已产生的回包别丢
                }
                $this->closeConn($fd);
                return;
            }

            if ($frame === null) {
                break;
            }
            if ($prof) {
                $now = hrtime(true);
                self::$profDecodeNs += $now - $tDecode;
                $tDecode = $now;
            }
            $tSub = $prof ? hrtime(true) : 0;
            $buf = substr($buf, $frame->consumed);
            if ($prof) {
                $now = hrtime(true);
                self::$profSubstrNs += $now - $tSub;
                $tDecode = $now;      // 后面 call 段从 substr 结束开始算
            }

            if ($this->onFrame !== null) {
                if ($this->traceFrames) {
                    // 帧边界 = 一次客户端操作的边界。在这里建链路，
                    // 后面从业务处理到 eventlog 记账都自动继承
                    Scheduler::instance()->beginTrace();
                }
                $cb = $this->onFrame;
                $cb($fd, $frame->msgId, $frame->body, $frame->flags);
            }
            if ($prof) {
                $now = hrtime(true);
                self::$profCallNs += $now - $tDecode;
                self::$profFrames++;
                self::$profLoopNs += $now - $tFeed;
                $tFeed = $now;
            }

            $guard++;
            if ($guard > 1000) {
                // 防止单个连接一次塞几千帧把其他连接饿死
                break;
            }
        }
        $this->buffers[$fd] = $buf;
        $this->inFeed = false;
        if ($this->batchSend) {
            $this->flushBatch();
        }
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
