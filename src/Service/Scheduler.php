<?php

namespace TSF\Service;

use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Kernel\CrashDump;
use TSF\Kernel\Logger;
use TSF\Metrics\Metrics;
use TSF\Timer\Cron;
use TSF\Timer\Timers;

/**
 * 调度器：注册表 + 信箱集合 + 单轮驱动。
 *
 * 模型（照搬 Skynet 的思路，但只做单进程版）：
 *
 *   业务代码 ──send──> 目标信箱 ──tick──> Service::onMessage()
 *
 * 为什么不让服务之间直接 call：
 *   直接调用会形成隐式依赖图（A 调 B、B 又回调 A 就死锁），而且没法把一个服务
 *   挪到别的进程。消息模型把依赖变成显式的地址，迁移时只改地址分配，不改代码。
 *
 * 为什么没有 `while(true)` 死循环在 tick 里：
 *   tick 只处理"当前就绪"的服务，处理过程中新投递的消息会在同一轮里被继续处理
 *   （就绪队列会追加）。没有活就立刻返回，由调用方决定睡还是继续——
 *   这样既能嵌进网络事件循环（每收到一批帧 drain 一次，延迟≈0），
 *   也能用 run() 独立跑。
 */
final class Scheduler
{
    private static ?Scheduler $self = null;

    /** 追踪 id 的进程内序号 */
    private static int $traceSeq = 0;

    /** @var array<int, Service> id => 服务实例 */
    private array $services = [];

    /** @var array<int, Mailbox> id => 信箱 */
    private array $boxes = [];

    /** @var array<string, \Closure> 名字 => 工厂（返回 Service） */
    private array $factories = [];

    /**
     * 受监督的服务：name => 配置。
     *
     * 只支持**单实例**语义（一个 name 一个实例）—— 适合世界、邮件、排行榜这类单例。
     * 房间那种"一房一实例"的不该用监督：房间崩了就该销毁，玩家重连新建，
     * 重启一个"同号房间"反而会把脏状态带回来。
     *
     * @var array<string, array{max:int, within:int, addr:int, count:int, since:int}>
     */
    private array $supervised = [];

    /** @var array<int, int> 就绪队列（服务 id），FIFO */
    private array $ready = [];

    private int $readyHead = 0;

    /**
     * 本地服务 id 分配器：单调递增、到顶回绕、带最小宽限期。
     *
     * 原来是一个只增不收的计数器——16,777,215 次 spawn 之后这个进程永久不能再建服务。
     * 为什么用回绕而不是空闲表，见 IdAllocator 的类注释。
     */
    private IdAllocator $ids;

    /** id 回绕后的最小宽限期（毫秒），见 IdAllocator */
    private const ID_GRACE_MS = 5000;

    /** 已经打过日志的回绕次数（每回绕一次只记一行） */
    private int $idWrapsSeen = 0;

    /**
     * 单个服务在一轮里最多处理多少条。
     *
     * 这是"公平性 vs 吞吐"的旋钮：调大吞吐略高但会拉长其他服务的等待；
     * 调小更公平但调度开销占比上升。200 在两者间比较平衡（实测见 bench/service）。
     */
    private int $budget = 200;

    /**
     * 崩溃取证：给每个服务留最近 N 条消息摘要。
     *
     * 默认**关闭**（热路径每批只多一次布尔判断）。打开后每条消息多付约 100ns，
     * 换的是崩溃时能看出"挂之前在处理什么"——没有它，日志里只有一行 msgId，
     * "玩家说卡了一下"这类问题只能靠时间戳猜。
     */
    private bool $traceOn = false;

    private int $traceCap = 16;

    /** 崩溃现场落盘目录。空串表示不落盘（仅日志） */
    private string $crashDir = "";

    /** 每个信箱的积压上限；0 表示不限制（不推荐，见 Mailbox::$limit 注释） */
    private int $boxLimit = 262144;

    /**
     * 慢消息阈值（纳秒）；0 = 关闭看门狗。
     *
     * 为什么是"检测"而不是"中断"：实测（bench/probe-preempt）Swoole 的
     * `Coroutine::cancel()` 对一个正在跑纯 CPU 循环的协程返回 false、打不断它，
     * `isCanceled()` 也是 false。抢占式调度只保证**别的协程能跑**，不能杀当前协程。
     * 所以这里只能事后记账 + 告警 + 可选销毁服务，做不到"掐断正在执行的函数"。
     */
    private int $watchdogNs = 0;

    /** 超时后的动作：log（只告警）/ kill（销毁服务，后续消息不再处理） */
    private string $watchdogAction = "log";

    /**
     * 单服务单次调度的时间片（纳秒）；0 = 关闭。
     *
     * 与 `$watchdogNs` 的区别见 `setSlice()`：这个在消息边界主动让出，
     * 而 watchdog 是事后记账。两者共用同一对 `hrtime`，同时开不额外付费。
     */
    private int $sliceNs = 0;

    /** 单次 `tick()` 的总预算（纳秒）；0 = 关闭。见 `setTickBudget()` */
    private int $tickBudgetNs = 0;

    /**
     * 单批消息允许的内存净增长（字节）；0 = 关闭。见 `setMemGuard()`。
     *
     * 为什么只做"归因 + 杀"，不做"隔离"：所有 Service 共享 PHP 堆，
     * 想做到 Erlang 的 per-process heap 得换运行时。能做的只有
     * "在 tick 边界量出差值，定位到是谁，然后把它干掉"。
     */
    private int $memMsgBytes = 0;

    /** 单服务累计净增长的配额（字节）；0 = 不限制 */
    private int $memServiceBytes = 0;

    /** 超限动作：log / kill */
    private string $memAction = "log";

    /** @var array<int, int> addr id => 累计净增长（只累加正增长） */
    private array $memSpent = [];

    /** @var array<int, int> 已经报过"配额超限"的服务，防止逐批刷日志 */
    private array $memWarned = [];

    /** 当前正在处理哪个服务（用于给发出的消息填 from） */
    private int $current = 0;

    /**
     * 当前链路追踪 id：**正在处理的消息属于哪条链路**。
     *
     * 它由 `deliver()` 自动写进消息信封，所以服务里 `send()` 出去的每一条都自然继承——
     * 业务代码完全不用管。这正是追踪能落地的关键：不用记得带，才有人用。
     *
     * 只在**入口**生成（网络层收到客户端包时）。定时器/心跳这类没有外部来源的消息
     * traceId 为 0，不生成——否则每条内部消息都要付一次生成成本。
     */
    private int $trace = 0;

    /**
     * 一轮调度结束时的回调。
     *
     * 用来做"批尾"动作，典型是**批量发送**：服务在 `onMessage` 里发出的回包
     * 如果都单独 `send`，一次 tick 处理 500 条消息就是 500 次 syscall。
     * 注册一个 flush 回调，让传输层在 tick 结束时按连接合并写出。
     *
     * 用闭包注入而不是让 Scheduler 认识 Transport：分层方向不能反
     * （Service 不依赖 Transport）。
     */
    private ?\Closure $onBatchEnd = null;

    /** 对应 `onBatchEnd` 的开启钩子（批窗口开/关必须成对） */
    private ?\Closure $onBatchBegin = null;

    public function onBatchBegin(\Closure $fn): void
    {
        $this->onBatchBegin = $fn;
    }

    public function onBatchEnd(\Closure $fn): void
    {
        $this->onBatchEnd = $fn;
    }

    /** 生成一个新的追踪 id：42 位毫秒时间 + 20 位进程内序号 */
    public function newTrace(): int
    {
        self::$traceSeq++;
        return (intdiv(hrtime(true), 1000000) << 20) | (self::$traceSeq & 0xFFFFF);
    }

    /**
     * 读当前 trace。
     *
     * 协程模式下必须从**协程上下文**取，和 `currentOf()` 同理：多个协程并发时，
     * 用一个全局属性会让 A 链路的 trace 粘到 B 链路的日志上——排障时最致命的
     * 就是"看起来有链路，其实串错了"。
     */
    /**
     * 网络入口用：生成一条新链路并把它设为当前。
     *
     * ```php
     * // WS/TCP 收到客户端包时
     * $s = Scheduler::instance();
     * $prev = $s->currentTrace();
     * $s->beginTrace();
     * $s->deliver($roomAddr, MSG_MOVE, $payload);
     * $s->setTrace($prev);          // 用完恢复，别让 trace 漏到别的链路
     * ```
     *
     * 不放在传输层自动做，是因为"一个客户端包 = 一条链路"这个语义只有业务知道：
     * 有的包是心跳（不值得建链路），有的包会扇出成几十条消息（更该建）。
     */
    public function beginTrace(): int
    {
        $t = $this->newTrace();
        $this->setTrace($t);
        return $t;
    }

    public function currentTrace(): int
    {
        if (!$this->coroutine) {
            return $this->trace;
        }
        $ctx = \Swoole\Coroutine::getContext();
        if (!is_object($ctx)) {
            return $this->trace;
        }
        $v = $ctx["sched_trace"] ?? 0;
        return is_int($v) ? $v : 0;
    }

    public function setTrace(int $trace): void
    {
        if (!$this->coroutine) {
            $this->trace = $trace;
            return;
        }
        $ctx = \Swoole\Coroutine::getContext();
        if (is_object($ctx)) {
            $ctx["sched_trace"] = $trace;
        }
        // 同时更新属性：给协程外部读（日志收尾、统计），不会造成错乱
        $this->trace = $trace;
    }


    /**
     * 协程模式开关（默认关）。
     *
     * 关闭时 `currentOf()` 直接读属性，和原来等价（多一次方法调用，约 50ns）。
     * 开启后每条消息跑在协程里、`current` 存进**协程上下文** —— 否则多个协程
     * 会互相覆盖这个全局属性，发出的消息 from 全乱（症状极难查：
     * 「服务收到了消息但不知道谁发的」）。
     */
    private bool $coroutine = false;

    /** @var array<int, bool> 协程模式下：哪些服务正有协程在处理（保证同一服务串行） */
    private array $busy = [];

    /** 开启协程调度。需要配合 Swoole\Runtime::enableCoroutine 才有异步 I/O 效果 */
    public function setCoroutine(bool $on): void
    {
        $this->coroutine = $on;
    }

    public function coroutineEnabled(): bool
    {
        return $this->coroutine;
    }

    /**
     * 读「当前服务」。
     *
     * 协程模式下从协程上下文取 —— 每个协程有自己的一份，互不干扰。
     * 非协程模式直接返回属性，是热路径（deliver 每条消息都要读）。
     */
    public function currentOf(): int
    {
        if (!$this->coroutine) {
            return $this->current;
        }
        $ctx = \Swoole\Coroutine::getContext();
        if (!is_object($ctx)) {
            return $this->current;
        }
        $v = $ctx["sched_cur"] ?? 0;
        return is_int($v) ? $v : 0;
    }

    public function setCurrentOf(int $addr): void
    {
        if (!$this->coroutine) {
            $this->current = $addr;
            return;
        }
        $ctx = \Swoole\Coroutine::getContext();
        if (is_object($ctx)) {
            $ctx["sched_cur"] = $addr;
        }
        // 同时更新属性：给"协程外部"（比如 tick 的收尾）读，不会造成错乱
        $this->current = $addr;
    }

    private Timers $timers;

    /** 跨节点通道；为 null 时远程地址一律投递失败 */
    private ?Cluster $cluster = null;

    /**
     * 本机节点号。地址里的节点位等于它才算本地。
     *
     * 不能简单用"节点位 != 0"判断：本机节点号是 1 时，发给自己的地址也带节点位 1，
     * 那样会把自己的消息当远程再发回去（踩过：包收到了、日志显示 box 存在，
     * 但服务一条都没处理）。
     */
    private int $localNode = 0;

    public function __construct()
    {
        $this->timers = new Timers();
        $this->ids = new IdAllocator(Addr::MAX_ID, self::ID_GRACE_MS);
    }

    /**
     * 设置 id 回绕后的最小宽限期（毫秒）。默认 5 秒。
     *
     * 只在极端高频的 spawn/kill 下才起作用（回绕间隔本身通常以小时计），
     * 见 IdAllocator。压测或测试里想让回绕快一点可以调小；设 0 = 不设宽限期。
     */
    public function setIdGraceMs(int $ms): void
    {
        $this->ids->setGraceMs($ms);
    }

    public function setCluster(Cluster $c): void
    {
        $this->cluster = $c;
        $this->localNode = $c->nodeId();
    }

    public function cluster(): ?Cluster
    {
        return $this->cluster;
    }

    /** @var array<string, int> */
    private array $stat = [
        "spawned" => 0,
        "killed" => 0,
        "handled" => 0,
        "undelivered" => 0,
        "overflow" => 0,
        "errors" => 0,
        "timers_cancelled" => 0,
        "slow" => 0,
        "max_msg_us" => 0,
        /** 两次 tick 之间的最大间隔（µs）。协程模式下这才是"真阻塞"的信号 */
        "max_tick_gap_us" => 0,
        "slow_tick" => 0,
        /** 停机时因排空预算用尽而丢弃的消息数（见 shutdown） */
        "shutdown_dropped" => 0,
        /** 服务 id 回绕次数（> 0 说明这个进程已经用完过一整轮地址空间） */
        "id_wraps" => 0,
        /** 因单服务时间片用尽而被让出的次数（见 setSlice） */
        "slice_hit" => 0,
        /** 因单次 tick 总预算用尽而提前返回的次数（见 setTickBudget） */
        "tick_over" => 0,
        /** 单批消息内存增长超过 setMemGuard 阈值的次数 */
        "mem_spike" => 0,
        /** 因累计内存超配额被销毁的服务数（只有 action=kill 时才增长） */
        "mem_kill" => 0,
        /** 配额超限告警次数。**每个服务最多 1 次**，逐批报会把磁盘写满 */
        "mem_warn" => 0,
    ];

    /** 已进入停机流程（`shutdown()` 调用之后一直为 true） */
    private bool $stopping = false;

    /**
     * 服务已全部停止：此后的投递一律拒绝。
     *
     * 和 `$stopping` 分开：排空期间服务之间还要互相发消息，那时不能拒。
     */
    private bool $stopped = false;

    /** @var array<int, \Closure> */
    private array $shutdownHooks = [];

    /** @var array<string, int>|null 停机结果；没停过时为 null */
    private ?array $shutdownResult = null;

    public static function instance(): Scheduler
    {
        if (self::$self === null) {
            self::$self = new Scheduler();
            // 让日志自动带链路 id。注册一次即可（Logger 是静态全局的）。
            Logger::setTraceProvider(function (): int {
                return Scheduler::instance()->currentTrace();
            });
            // 运行时指标：导出时现取（见 Metrics::$providers 的注释）。闭包每次都走 instance()，
            // 所以 `Scheduler::reset()` 之后取到的是新实例，不会把旧实例的数加进来
            Metrics::provide("tsf_scheduler", function (): array {
                return Scheduler::instance()->stats();
            }, [
                "handled",
                "spawned",
                "killed",
                "undelivered",
                "overflow",
                "errors",
                "timers_cancelled",
                "slow",
                "slow_tick",
                "shutdown_dropped",
                "id_wraps",
                "slice_hit",
                "tick_over",
                "mem_spike",
                "mem_kill",
            ]);
        }
        return self::$self;
    }

    /** 测试用：清掉全部状态 */
    public static function reset(): void
    {
        self::$self = null;
    }

    public function setBudget(int $n): void
    {
        $this->budget = $n > 0 ? $n : 1;
    }

    /** 设置每个信箱的积压上限（对已存在的信箱和后续新建的都生效） */
    public function setMailboxLimit(int $n): void
    {
        $this->boxLimit = $n > 0 ? $n : 0;
        foreach ($this->boxes as $box) {
            $box->setLimit($this->boxLimit);
        }
    }

    /**
     * 开启慢消息看门狗。
     *
     * 代价（实测 bench/guard-test）：每条消息多两次 hrtime，约 +259 ns（−11.5% 吞吐）。
     * 所以默认关闭——只在排障或压测时打开。关闭时只多一次整数比较。
     *
     * @param int $ms     阈值（毫秒）；<=0 表示关闭
     * @param string $action log | kill
     */
    public function setWatchdog(int $ms, string $action = "log"): void
    {
        $this->watchdogNs = $ms > 0 ? $ms * 1000000 : 0;
        if ($action !== "log" && $action !== "kill") {
            throw new \RuntimeException("watchdog: action 只能是 log 或 kill");
        }
        $this->watchdogAction = $action;
    }

    public function watchdogThresholdMs(): int
    {
        return intdiv($this->watchdogNs, 1000000);
    }

    /**
     * 单服务单次调度的时间片（毫秒）。0 = 关闭（默认）。
     *
     * 与 `setWatchdog()` 的分工：
     *   - watchdog 是**单条消息事后**记账：已经卡了才记/杀
     *   - slice 是**本次调度事前**让出：跑到预算就把剩余消息排到队尾，让别人先跑
     *
     * **只在消息边界生效**。一条消息自己跑 100 ms，slice 设 2 ms 也拦不住它——
     * PHP 无法中断正在执行的代码。这条边界必须知道，否则会误以为它能防死循环。
     *
     * **只在同步模式下生效**。协程模式里墙钟包含挂起等 I/O 的时间，拿它做 CPU 预算
     * 会把"等 redis 30 ms 的服务"误判成慢——协程模式该用 `setBudget` 限条数。
     *
     * 代价：需要计时，与 watchdog 共用同一对 `hrtime`（约 +259 ns/条，实测见
     * `bench/guard-test`）。两个都关时只多一次布尔判断。
     */
    public function setSlice(int $ms): void
    {
        $this->sliceNs = $ms > 0 ? $ms * 1000000 : 0;
    }

    public function sliceThresholdMs(): int
    {
        return intdiv($this->sliceNs, 1000000);
    }

    /**
     * 单次 `tick()` 的总预算（毫秒）。0 = 关闭（默认）。
     *
     * 解决的不是"某个服务慢"，而是"这一轮 tick 总体太长"：定时器延迟、IO 回调饥饿、
     * 心跳抖动都来自它。每处理 64 条消息检查一次（逐条检查要 236 ns，占单消息预算的 27%）。
     *
     * 跳出后未处理的消息留在信箱里，下一个 tick 继续——不丢消息。
     */
    public function setTickBudget(int $ms): void
    {
        $this->tickBudgetNs = $ms > 0 ? $ms * 1000000 : 0;
    }

    public function tickBudgetMs(): int
    {
        return intdiv($this->tickBudgetNs, 1000000);
    }

    /**
     * 单服务内存守卫。0 表示关闭对应维度。
     *
     * 共享堆下没有 per-process heap，所以这里做的是**归因**而不是隔离：
     * 在 tick 边界量 `memory_get_usage()` 的差值，归到刚处理完的那个服务上。
     * 抓得到"一条消息吃 20 MB"和"长期只涨不降"，抓不到"每次 1 KB 慢慢涨"
     * ——后者需要逐分配追踪，PHP 做不到。
     *
     * `$perMsgMb`：单批净增长超过它就记 error（通常是"一次查询捞了整张表"）
     * `$perServiceMb`：单服务累计超过它就按 `$action` 处理（通常是"缓存没上限"）
     *
     * 成本：每个服务每批多两次 `memory_get_usage()`（各约 100 ns）。
     * 默认关闭——只在排障或"怀疑内存涨得不正常"时打开。
     *
     * @param string $action log | kill（kill 会走 `onCrashed`，受监督树约束）
     */
    public function setMemGuard(int $perMsgMb, int $perServiceMb, string $action = "log"): void
    {
        if ($action !== "log" && $action !== "kill") {
            throw new \RuntimeException("memGuard: action 只能是 log 或 kill");
        }
        $this->memMsgBytes = $perMsgMb > 0 ? $perMsgMb * 1048576 : 0;
        $this->memServiceBytes = $perServiceMb > 0 ? $perServiceMb * 1048576 : 0;
        $this->memAction = $action;
    }

    public function memGuardEnabled(): bool
    {
        return $this->memMsgBytes > 0 || $this->memServiceBytes > 0;
    }

    /**
     * 每个服务当前的累计净增长（字节），key 是地址的可读形式。
     *
     * 这是**累计值**，不是当前占用——PHP 释放内存时不会回退这个数。
     * 用它判断"谁在涨"，不要用它算"谁占了多少"。
     *
     * @return array<string, int>
     */
    public function memByService(): array
    {
        $out = [];
        foreach ($this->memSpent as $id => $bytes) {
            $out[Addr::label($id)] = $bytes;
        }
        return $out;
    }

    public function budget(): int
    {
        return $this->budget;
    }

    /**
     * 注册一种服务。工厂用闭包而不是类名，是因为 AOT 不能用"按名字 new"。
     *
     *   $s->register("room", fn () => new RoomService());
     */
    public function register(string $name, \Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /** 创建服务，返回它的地址 */
    public function spawn(string $name): int
    {
        if (!isset($this->factories[$name])) {
            throw new \RuntimeException("service: 未注册的服务类型 [" . $name . "]");
        }
        return $this->create($this->factories[$name]);
    }

    /**
     * 注册并创建一个**受监督**的服务。
     *
     * ```php
     * $s->supervise("world", fn () => new WorldService(), maxRestarts: 3, withinMs: 5000);
     * ```
     *
     * 服务抛异常时：销毁 → 按 `maxRestarts / withinMs` 决定是否重建。
     * 超过阈值就放弃（记 error 日志，不再重启），避免"崩—重启—崩"的死循环。
     *
     * **状态不保留**：重启是一个全新的实例（和 OTP 的 supervisor 一样，
     * 状态要么在外部存储，要么可以重建）。这一点要在文档里说清楚，
     * 否则用户会以为重启能"恢复现场"。
     */
    public function supervise(
        string $name,
        \Closure $factory,
        int $maxRestarts = 3,
        int $withinMs = 5000,
    ): int {
        $this->register($name, $factory);
        $addr = $this->spawn($name);
        $this->supervised[$name] = [
            "max" => $maxRestarts,
            "within" => $withinMs,
            "addr" => $addr,
            "count" => 0,
            "since" => 0,
        ];
        return $addr;
    }

    /** 该地址当前是否处于受监督状态（测试和观测用） */
    public function isSupervised(int $addr): bool
    {
        foreach ($this->supervised as $cfg) {
            if ($cfg["addr"] === $addr) {
                return true;
            }
        }
        return false;
    }

    /** 受监督服务的当前地址（重启后会变）。没受监督时返回 0 */
    /** 打开崩溃取证（保留最近 `$cap` 条消息摘要）。已存在的服务也会被设置 */
    public function enableTrace(int $cap = 16): void
    {
        $this->traceOn = true;
        $this->traceCap = $cap > 0 ? $cap : 16;
        foreach ($this->services as $svc) {
            if ($svc instanceof Service) {
                $svc->enableTrace($this->traceCap);
            }
        }
    }

    /** 崩溃现场落盘目录（不存在会自动建）。传空串表示只写日志 */
    public function crashDir(string $dir): void
    {
        $this->crashDir = $dir;
    }

    public function supervisedAddr(string $name): int
    {
        if (!isset($this->supervised[$name])) {
            return 0;
        }
        return $this->supervised[$name]["addr"];
    }

    /** @return array<string, int> name => 重启次数 */
    public function restartCounts(): array
    {
        $out = [];
        foreach ($this->supervised as $name => $cfg) {
            $out[$name] = $cfg["count"];
        }
        return $out;
    }

    /**
     * 服务因异常被销毁后的处理：先 kill，再按监督配置决定要不要重建。
     *
     * 和 kill() 的区别：kill() 是**主动**销毁（用户调用，不重启）；
     * onCrashed() 是**异常**导致（可能重启）。
     */
    /**
     * 把一段内存增长归到某个服务上，并按阈值处置。
     *
     * 只在 tick 边界调用。同一 tick 里服务是串行处理的，所以差值不会混进
     * 别的服务的分配——但会混进"框架自己"的分配（日志缓冲、信箱扩容），
     * 所以这条只适合发现**数量级异常**，不适合精确记账。
     */
    private function attributeMemory(int $id, int $delta): void
    {
        if ($delta > 0) {
            $prev = 0;
            if (isset($this->memSpent[$id])) {
                $prev = $this->memSpent[$id];
            }
            $this->memSpent[$id] = $prev + $delta;
        }

        if ($this->memMsgBytes > 0 && $delta > $this->memMsgBytes) {
            $this->stat["mem_spike"]++;
            Logger::error("service memory spike", [
                "svc" => Addr::label($id),
                "delta_mb" => intdiv($delta, 1048576),
                "limit_mb" => intdiv($this->memMsgBytes, 1048576),
            ]);
            if ($this->memAction === "kill") {
                $this->killForMemory($id);
                return;
            }
        }

        $spent = 0;
        if (isset($this->memSpent[$id])) {
            $spent = $this->memSpent[$id];
        }
        if ($this->memServiceBytes > 0 && $spent > $this->memServiceBytes) {
            // 只报一次：配额超了之后每一批都会超，逐批打日志会把磁盘写满，
            // 反而看不到"第一次"——那才是要查的时间点
            if (!isset($this->memWarned[$id])) {
                $this->memWarned[$id] = 1;
                $this->stat["mem_warn"]++;
                Logger::error("service memory quota exceeded", [
                    "svc" => Addr::label($id),
                    "spent_mb" => intdiv($spent, 1048576),
                    "limit_mb" => intdiv($this->memServiceBytes, 1048576),
                ]);
            }
            if ($this->memAction === "kill") {
                $this->killForMemory($id);
            }
        }
    }

    private function killForMemory(int $id): void
    {
        $this->stat["mem_kill"]++;
        unset($this->memWarned[$id]);
        $this->onCrashed($id);
    }

    private function onCrashed(int $addr): void
    {
        $this->kill($addr);

        foreach ($this->supervised as $name => $cfg) {
            if ($cfg["addr"] !== $addr) {
                continue;
            }

            $now = intdiv(hrtime(true), 1000000);
            if ($cfg["since"] === 0 || $now - $cfg["since"] > $cfg["within"]) {
                $cfg["since"] = $now;
                $cfg["count"] = 0;
            }
            $cfg["count"]++;

            if ($cfg["count"] > $cfg["max"]) {
                Logger::error("supervisor gave up (too many restarts)", [
                    "svc" => $name,
                    "restarts" => $cfg["count"],
                    "within_ms" => $cfg["within"],
                ]);
                $this->supervised[$name]["count"] = $cfg["count"];
                $this->supervised[$name]["addr"] = 0;   // 放弃后不再对应任何实例
                return;
            }

            $newAddr = $this->spawn($name);
            $this->supervised[$name]["addr"] = $newAddr;
            $this->supervised[$name]["count"] = $cfg["count"];
            $this->supervised[$name]["since"] = $cfg["since"];
            Logger::info("supervisor restarted", [
                "svc" => $name,
                "attempt" => $cfg["count"],
                "new_addr" => $newAddr,
            ]);
            return;
        }
    }

    /**
     * 用临时工厂创建服务。
     *
     * 注册表里的工厂是无参的（AOT 不能用"按名字 new 带参数"），但房间这类服务
     * 需要按实例配置（房间号、人数上限）。所以业务用一个捕获了配置的闭包来创建。
     */
    public function spawnOf(\Closure $factory): int
    {
        return $this->create($factory);
    }

    private function create(\Closure $factory): int
    {
        if ($this->stopping) {
            throw new \RuntimeException("service: 调度器已停机，不能再创建服务");
        }
        $self = $this;
        $id = $this->ids->acquire(self::nowMs(), function (int $candidate) use ($self): bool {
            return isset($self->services[$candidate]);
        });
        if ($this->ids->wraps() !== $this->idWrapsSeen) {
            // 回绕本身不是错误，但值得知道：从这一刻起 id 开始被复用
            //（每个 id 要隔一整轮地址空间才会轮到第二次）。
            $this->idWrapsSeen = $this->ids->wraps();
            Logger::warn("service id space wrapped: ids are being reused", [
                "wraps" => $this->idWrapsSeen,
                "services" => count($this->services),
            ]);
        }

        $svc = $factory();
        if (!($svc instanceof Service)) {
            throw new \RuntimeException("service: 工厂必须返回 Service，返回了 " . gettype($svc));
        }

        $svc->bindAddr($id);
        if ($this->traceOn) {
            // 取证开关在 spawn 之前就打开时，新服务也要立刻开始记录
            $svc->enableTrace($this->traceCap);
        }
        $this->services[$id] = $svc;
        $box = new Mailbox();
        $box->setLimit($this->boxLimit);
        $this->boxes[$id] = $box;
        $this->stat["spawned"]++;

        // onStart 里也可能发消息（比如启动时给别的服务打个招呼），
        // 所以要让它"以自己身份"发，否则 from 会是 0（系统）。
        $prev = $this->currentOf();
        $this->setCurrentOf($id);
        $svc->onStart();
        $this->setCurrentOf($prev);
        return $id;
    }

    /** 销毁服务：丢弃积压消息、调用 onStop */
    public function kill(int $addr): bool
    {
        $id = Addr::id($addr);
        if (!isset($this->services[$id])) {
            return false;
        }
        $dropped = $this->boxes[$id]->clear();
        $svc = $this->services[$id];
        unset($this->services[$id], $this->boxes[$id]);
        $this->stat["killed"]++;
        $this->ids->release($id, self::nowMs());
        // 必须撤销它名下的定时器：否则到点还会往一个已销毁的服务投消息
        $cancelled = $this->timers->cancelAllOf($id);
        if ($cancelled > 0) {
            $this->stat["timers_cancelled"] += $cancelled;
        }
        if ($dropped > 0) {
            $this->stat["undelivered"] += $dropped;
        }
        if ($this->currentOf() === $id) {
            $this->setCurrentOf(0);
        }
        // 归因表跟着服务一起清：id 会被回收（回绕），留着这笔会让新服务
        // 一出生就顶着前一个的"内存债"，配额直接超限
        unset($this->memSpent[$id], $this->memWarned[$id]);
        $svc->onStop();
        return true;
    }

    /** 投递消息。跨节点地址交给 Cluster；无 Cluster 时返回 false */
    public function deliver(int $to, int $msgId, string|array|object $data = "", int $session = 0): bool
    {
        // 链路 id 在这里统一注入：调用方（业务代码）不需要知道它的存在。
        // 位置放在最前面是为了让下面所有分支（本地/跨节点）都带同一个值。
        $trace = $this->currentTrace();
        // 这里刻意内联 Addr::id 的位运算：热路径上每次静态调用约 250 ns。
        //
        // **节点位为 0 一律视为本地**：`Service::bindAddr()` 注入的是裸 id
        // （node 位为 0），只有跨节点的地址才带节点位。所以不能直接
        // `!== $localNode`——`setCluster()` 之后 localNode 变成 1，
        // 那样所有本地投递都会被判成"跨节点"、发给不存在的节点 0 然后丢弃。
        // 症状是"服务明明被调用了，但回包永远收不到"，且只在接入集群后出现。
        $toNode = $to >> Addr::ID_BITS;
        if ($toNode !== 0 && $toNode !== $this->localNode) {
            // 跨节点：交给 Cluster 攒批发出去
            if ($this->cluster === null || $this->stopped) {
                $this->stat["undelivered"]++;
                return false;
            }
            if ($this->cluster->send($to, $this->currentOf(), $msgId, $data, $session)) {
                return true;
            }
            $this->stat["undelivered"]++;
            return false;
        }

        $id = $to & Addr::ID_MASK;
        $box = $this->boxes[$id] ?? null;
        if ($box === null || $this->stopped) {
            // 服务已停机或不存在：计入 undelivered，而不是悄悄建一个没人读的信箱
            $this->stat["undelivered"]++;
            return false;
        }

        // 每消息的 sent 计数不进热路径：一次数组自增约 174 ns。
        // 吞吐类指标由压测/监控层按批次统计，框架只记低频事件（spawn/kill/error）。
        if (!$box->push(new Message($this->currentOf(), $msgId, $data, $session, $trace))) {
            // 信箱满：丢新的，不让积压无限涨（旧消息通常还有意义）
            $this->stat["overflow"]++;
            return false;
        }

        if (!$box->queued) {
            $box->queued = true;
            $this->ready[] = $id;
        }
        return true;
    }

    /**
     * 以指定服务的身份投递（用于"服务之外"的发送方：main 里手动触发、测试、定时器回调等）。
     *
     * 热路径别用它——`deliver()` 直接用 `$this->current` 才有零额外开销；
     * 这个方法是给"当前没有正在处理的服务"的场景用的。
     */
    public function deliverAs(int $fromAddr, int $to, int $msgId, string|array|object $data = "", int $session = 0): bool
    {
        $prev = $this->currentOf();
        $this->setCurrentOf($fromAddr);
        $ok = $this->deliver($to, $msgId, $data, $session);
        $this->setCurrentOf($prev);
        return $ok;
    }

    /**
     * 处理当前就绪的消息，返回处理条数。
     *
     * $maxMsgs > 0 时限制总量，便于调用方在长任务间隙让出控制权。
     */
    public function tick(int $maxMsgs = 0): int
    {
        if ($this->onBatchBegin !== null) {
            $bb = $this->onBatchBegin;
            $bb();
        }
        // 1) 先收跨节点来的消息
        if ($this->cluster !== null) {
            $packets = $this->cluster->poll();
            foreach ($packets as $p) {
                if (!isset($this->boxes[Addr::id($p->dst)])) {
                    $this->stat["undelivered"]++;
                    continue;
                }
                // 临时把 current 设成远端地址：deliver 会用 current 填 from，
                // 这样服务看到的"谁发的"就是真实的远端地址，且热路径不用多一个参数。
                $prev = $this->currentOf();
                $this->setCurrentOf($p->src);
                $this->deliver($p->dst, $p->msgId, $p->data, $p->session);
                $this->setCurrentOf($prev);
            }
        }

        // 2) 把到期的定时器变成消息投进信箱——它们和普通消息排同一个队，
        // 服务的执行路径仍然只有一条（不在这里直接调回调）。
        // 判据必须是 hasPending() 而不是 size() > 0：size() 只数有效定时器，
        // 服务批量销毁后 live 归零、但已取消的对象还压在时间轮里等翻到。
        // 只看 size() 会让 due() 停摆，游标不再前进，那些死对象永远留着
        // （内存单调增长而 stats()["timers"] 一直是 0，见 bench/lifecycle）。
        if ($this->timers->hasPending()) {
            $due = $this->timers->due(self::nowMs());
            foreach ($due as $t) {
                // 定时器消息的 from 应该是"自己"（自定闹钟），不是 0（系统）
                $prev = $this->currentOf();
                $this->setCurrentOf($t->addr);
                $this->deliver($t->addr, $t->msgId, $t->data);
                $this->setCurrentOf($prev);
            }
        }

        $handled = 0;
        $limit = $maxMsgs > 0 ? $maxMsgs : PHP_INT_MAX;
        // 总预算：只在开启时取一次时钟，关闭时零成本
        $tickStart = $this->tickBudgetNs > 0 ? hrtime(true) : 0;
        // 内存守卫：关闭时不取基线，整个归因分支都不进
        $memOn = $this->memMsgBytes > 0 || $this->memServiceBytes > 0;
        // 预算用尽后要连外层一起停：只 break 内层的话，下一个服务照样跑满一整批
        $stop = false;

        while ($handled < $limit && !$stop && $this->readyHead < count($this->ready)) {
            // 每 64 条检查一次：逐条检查要 236 ns，会吃掉单消息预算的 27%
            if ($tickStart !== 0 && ($handled & 63) === 0
                && hrtime(true) - $tickStart > $this->tickBudgetNs) {
                $this->stat["tick_over"]++;
                $stop = true;
                break;
            }

            $id = $this->ready[$this->readyHead];
            $this->readyHead++;

            if (!isset($this->services[$id])) {
                // 在处理别的服务时被 kill 掉了
                continue;
            }

            $svc = $this->services[$id];
            $box = $this->boxes[$id];
            $box->queued = false;

            // 协程模式：把这一批交给协程，不在这里同步跑。
            // 好处是 onMessage 里的阻塞 I/O（redis/PDO）只挂起自己那个协程，
            // 其他服务照常被调度 —— 前提是开了 Runtime::enableCoroutine。
            if ($this->coroutine) {
                $this->dispatchAsync($svc, $box, $id);
                $handled++;
                continue;
            }

            // current 按批设置而不是按条：同一批消息都由同一个服务处理，
            // 每条都存/恢复要两次属性写（约 300 ns），按批做只付一次。
            $prev = $this->currentOf();
            $this->setCurrentOf($id);
            $prevTrace = $this->currentTrace();

            // 内存归因的基线：只在同步路径取（协程的批次不在这个调用栈里结束）
            $memBefore = $memOn ? memory_get_usage() : 0;

            $n = 0;
            // budget 提到局部变量：内层每轮读一次属性约 150 ns，按条累加很可观
            $budget = $this->budget;
            // 看门狗阈值也提到局部：关闭时只多一次整数比较
            $wdNs = $this->watchdogNs;
            // 时间片：和 watchdog 共用同一对 hrtime，两者都关时走无计时分支
            $sliceNs = $this->sliceNs;
            $needTime = $wdNs > 0 || $sliceNs > 0;
            $sliced = 0;
            $m = null;
            // 取证缓冲：开关提到局部，关闭时只多一次判断
            $traceOn = $this->traceOn;
            $traceNow = $traceOn ? self::nowMs() : 0;
            // try/catch 放在批外而不是每条外面：AOT 下每进一次 try 都要装一次
            // 异常跳转上下文（实测差 300+ ns/条）。代价是出错时无法精确定位到某一条，
            // 但语义上本来就是"服务出错即销毁"，剩下的积压消息随服务一起丢弃。
            try {
                while ($n < $budget) {
                    // tick 总预算：**放在 pop 之前**。取出消息再放弃就是丢消息，
                    // 而没有断言的丢消息正是这个项目最怕的一类 bug。
                    if ($tickStart !== 0 && ($handled & 63) === 0
                        && hrtime(true) - $tickStart > $this->tickBudgetNs) {
                        $this->stat["tick_over"]++;
                        $stop = true;
                        break;
                    }
                    $m = $box->pop();
                    if ($m === null) {
                        break;
                    }
                    if ($traceOn) {
                        $svc->traceRecord($m, $traceNow);
                    }
                    // 继承这条消息的链路：服务里 send 出去的自然带上
                    $this->setTrace($m->traceId);
                    if (!$needTime) {
                        $svc->onMessage($m);
                    } else {
                        $t0 = hrtime(true);
                        $svc->onMessage($m);
                        $dur = hrtime(true) - $t0;
                        if ($wdNs > 0 && $dur > $wdNs) {
                            // 只能事后记账：实测无法打断正在执行的 CPU 循环（见 watchdogNs 注释）
                            if ($this->onSlow($id, $m, $dur)) {
                                break;
                            }
                        }
                        if ($sliceNs > 0) {
                            $sliced += $dur;
                        }
                    }
                    $handled++;
                    $n++;
                    // 时间片用尽：**这条已处理完**，剩余消息由外层重新入队，让别人先跑。
                    // 只能是消息边界——PHP 无法中断正在执行的代码。
                    if ($sliceNs > 0 && $sliced > $sliceNs) {
                        $this->stat["slice_hit"]++;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // 单个服务出错不该拖垮整个进程：记录后销毁它（状态可能已不一致）
                $this->stat["errors"]++;
                $label = Addr::label($id);
                $dumpPath = "";
                if ($this->crashDir !== "") {
                    // 落盘失败不能影响"销毁这个服务"：崩溃路径上不该再抛
                    $dumpPath = CrashDump::save(
                        $this->crashDir,
                        $label,
                        $m === null ? 0 : $m->msgId,
                        $e,
                        $svc->traceRecent(),
                        $svc->dumpState(),
                        ["restarts" => $this->stat["errors"]]
                    );
                }
                Logger::error("service error, killing", [
                    "svc" => $label,
                    "msgId" => $m === null ? 0 : $m->msgId,
                    "err" => $e->getMessage(),
                    "dump" => $dumpPath,
                ]);
                $this->setCurrentOf($prev);
                $this->onCrashed($id);
            }
            $this->setCurrentOf($prev);
            $this->setTrace($prevTrace);
            // 每条都做 $stat["handled"]++ 要 174 ns；按批累加等价，代价可忽略
            $this->stat["handled"] += $n;

            // 内存归因。服务在 catch 里已经被销毁的话跳过——它的对象已经释放，
            // 这时再往 memSpent 里写一笔，会留下一个永远查不到实体的孤儿记录
            if ($memOn && isset($this->services[$id])) {
                $this->attributeMemory($id, memory_get_usage() - $memBefore);
            }

            // 没处理完（撞到 budget）：排到队尾，本轮先让别人跑
            if (isset($this->boxes[$id]) && !$this->boxes[$id]->isEmpty() && !$this->boxes[$id]->queued) {
                $this->boxes[$id]->queued = true;
                $this->ready[] = $id;
            }
        }

        if ($this->readyHead >= count($this->ready)) {
            $this->ready = [];
            $this->readyHead = 0;
        }

        // 3) 一轮结束统一把跨节点写缓冲发出去（攒批：一批消息一次 syscall）
        if ($this->cluster !== null) {
            $this->cluster->flush();
        }

        if ($this->onBatchEnd !== null) {
            $cb = $this->onBatchEnd;
            $cb();
        }
        return $handled;
    }

    /**
     * 独立运行：处理完就绪消息，没活就睡一会儿。
     *
     * 用在"纯服务进程"里（没有网络事件驱动）。如果是跑在 Swoole 服务器里，
     * 应该由网络回调调用 tick()，不要开 run()，否则会和事件循环抢控制权。
     */
    /**
     * 协程模式下处理一个服务的信箱。
     *
     * **同一服务同时只有一个协程**（busy 标志）—— 这是"服务有状态但不必加锁"的前提。
     * 不同服务的协程可以并发：A 卡在 redis 上时，B 照常被调度。
     *
     * 一个协程连着处理 batch 条（而不是每条一个协程）：少创建协程，且天然保序。
     */
    private function dispatchAsync(Service $svc, Mailbox $box, int $id): void
    {
        if (isset($this->busy[$id])) {
            // 已经有协程在处理它：不并发，等它处理完继续 pop（消息本来就在信箱里）
            return;
        }
        $this->busy[$id] = true;

        $budget = $this->budget;
        // 用 $self 捕获而不是直接在闭包里写 $this：AOT 下闭包访问 $this 的属性没先例。
        // 闭包定义在类内、作用域是 Scheduler，所以 private 成员也调得到。
        $self = $this;

        \swoole_coroutine_create(function () use ($self, $svc, $box, $id, $budget): void {
            $n = 0;
            $m = null;
            $prevTrace = $self->currentTrace();
            try {
                $self->setCurrentOf($id);
                $wdNs = $self->watchdogNs;
                while ($n < $budget) {
                    $m = $box->pop();
                    if ($m === null) {
                        break;
                    }
                    $self->setTrace($m->traceId);
                    if ($wdNs === 0) {
                        $svc->onMessage($m);
                    } else {
                        // 协程路径原来**完全没有看门狗**（同步路径有，照搬时漏了）。
                        // 补上，但标记 blocking=false：这里的墙钟包含"挂起等 I/O"，
                        // 真正会卡住别人的是 tick 间隔，见 tick() 开头那段。
                        $t0 = hrtime(true);
                        $svc->onMessage($m);
                        $dur = hrtime(true) - $t0;
                        if ($dur > $wdNs) {
                            if ($self->onSlow($id, $m, $dur, false)) {
                                break;
                            }
                        }
                    }
                    $n++;
                }
            } catch (\Throwable $e) {
                // 和同步路径同语义：单个服务出错就销毁它（状态可能已不一致）
                $self->stat["errors"]++;
                Logger::error("service error (coroutine), killing", [
                    "svc" => Addr::label($id),
                    "msgId" => $m === null ? 0 : $m->msgId,
                    "err" => $e->getMessage(),
                ]);
                $self->onCrashed($id);
            } finally {
                $self->setTrace($prevTrace);
                unset($self->busy[$id]);
                // **一批处理完必须回队**。原来是漏的：预算用尽但邮箱还有积压时，
                // 这个服务要等下一条新消息投递才会被再次调度——突发一批（比如上线
                // 结算、批量发奖）之后，剩下的消息会永远卡在信箱里，现象是"没反应
                // 但日志干净"。同步路径一直有这段回队逻辑，协程路径照搬时漏了。
                if (isset($self->boxes[$id]) && !$self->boxes[$id]->isEmpty()) {
                    if (!$self->boxes[$id]->queued) {
                        $self->boxes[$id]->queued = true;
                        $self->ready[] = $id;
                    }
                }
            }
        });
    }

    public function run(): void
    {
        while (true) {
            // 测"一轮调度花了多久"。
            //
            // 为什么放在这里而不是 tick() 里：tick() 是被外部随时调用的
            // （定时器、测试、HTTP 循环），调用频率由别人决定——在 tick 里测间隔，
            // 量到的是"两次调用的间隔"，而不是"这一轮被谁卡住了"。测试里
            // `Coroutine::sleep(20ms)` 就被误报成"调度延迟 19.58ms"。
            //
            // 放在 run() 自循环里，语义才准确：一轮 = 收消息 + 到期定时器 + 派发，
            // 它变慢只可能是**有协程占着 CPU 不下来**（真阻塞）。协程模式下
            // 等 I/O 会让出，不会拖慢这一轮——这正好对上"阻塞"的定义。
            $t0 = hrtime(true);
            $worked = $this->tick(2000);
            $dtUs = intdiv(hrtime(true) - $t0, 1000);
            if ($dtUs > $this->stat["max_tick_gap_us"]) {
                $this->stat["max_tick_gap_us"] = $dtUs;
            }
            if ($this->watchdogNs > 0 && $dtUs > intdiv($this->watchdogNs, 1000)) {
                $this->stat["slow_tick"]++;
                Logger::warn("scheduler round too long", [
                    "round_us" => $dtUs,
                    "limit_ms" => intdiv($this->watchdogNs, 1000000),
                    "mode" => $this->coroutine ? "coroutine" : "sync",
                ]);
            }
            if ($worked === 0) {
                // 200 µs 的休眠粒度远小于 20 ms 的定时器误差要求；
                // 再精细只会空转烧 CPU，收益为零。
                usleep(200);
            }
        }
    }

    // ---------------- 定时器 ----------------

    /** 单调毫秒。不用 time()：墙钟被 NTP 回拨会让定时器集体错乱 */
    public static function nowMs(): int
    {
        return intdiv(hrtime(true), 1000000);
    }

    /** 一次性定时：$ms 之后给 $addr 投一条 $msgId 消息，返回定时器 id */
    public function timeout(int $addr, int $ms, int $msgId, string|array|object $data = ""): int
    {
        if ($this->stopping) {
            // 已停机：定时器不会再被调度，注册它没有意义（见 shutdown）
            return 0;
        }
        if ($ms < 0) {
            $ms = 0;
        }
        $now = self::nowMs();
        return $this->timers->add($now, $addr, $now + $ms, $msgId, $data);
    }

    /** 周期定时：每 $ms 投一条，返回定时器 id（cancel 时用同一个 id） */
    public function interval(int $addr, int $ms, int $msgId, string|array|object $data = ""): int
    {
        if ($this->stopping) {
            return 0;
        }
        if ($ms <= 0) {
            throw new \RuntimeException("timer: interval 必须 > 0 毫秒");
        }
        $now = self::nowMs();
        return $this->timers->add($now, $addr, $now + $ms, $msgId, $data, $ms);
    }

    /**
     * cron 定时：按墙钟表达式触发（分钟粒度）。
     *
     *   $s->every("0 5 * * *", $addr, Msg::DAILY_RESET);   // 每天 5:00
     *   $s->every("0 0 * * 1", $addr, Msg::WEEKLY_EVENT);  // 每周一 0:00
     */
    public function every(string $cron, int $addr, int $msgId, string|array|object $data = ""): int
    {
        if ($this->stopping) {
            return 0;
        }
        $now = self::nowMs();
        $nextTs = Cron::next($cron, intdiv($now, 1000));
        if ($nextTs <= 0) {
            throw new \RuntimeException("timer: cron 表达式永远不会触发 [" . $cron . "]");
        }
        return $this->timers->add($now, $addr, $nextTs * 1000, $msgId, $data, 0, $cron);
    }

    public function cancelTimer(int $timerId): bool
    {
        return $this->timers->cancel($timerId);
    }

    public function timers(): Timers
    {
        return $this->timers;
    }

    /** 慢消息处理：记账 + 告警，kill 模式返回 true（调用方应停止处理该服务） */
    /**
     * @param bool $blocking 这条消息是否**真的占住了 worker**。
     *                       同步模式恒为 true；协程模式下墙钟包含"等 I/O 被挂起"，
     *                       不能和"阻塞别人"混为一谈，所以标 false 并在日志里区分。
     */
    private function onSlow(int $id, Message $m, int $durNs, bool $blocking = true): bool
    {
        $this->stat["slow"]++;
        $us = intdiv($durNs, 1000);
        if ($us > $this->stat["max_msg_us"]) {
            $this->stat["max_msg_us"] = $us;
        }
        Logger::warn("slow message", [
            "svc" => Addr::label($id),
            "msgId" => $m->msgId,
            "us" => $us,
            "limit_ms" => intdiv($this->watchdogNs, 1000000),
            "blocking" => $blocking,
        ]);
        if ($this->watchdogAction === "kill") {
            $this->kill($id);
            return true;
        }
        return false;
    }

    public function currentAddr(): int
    {
        return $this->current;
    }

    public function has(int $addr): bool
    {
        return isset($this->services[Addr::id($addr)]);
    }

    /** 取服务实例（测试/自省用；业务代码不应该靠它绕过消息） */
    public function serviceOf(int $addr): ?Service
    {
        $id = Addr::id($addr);
        return $this->services[$id] ?? null;
    }

    public function serviceCount(): int
    {
        return count($this->services);
    }

    /**
     * 停机：排空就绪消息 → 逐个 `onStop` → 收尾钩子。**幂等**，重复调用直接返回上次的结果。
     *
     * ## 为什么必须有这一段
     *
     * 进程收到 SIGTERM 时，框架此前什么都没做：`Service::onStop` 只有 `kill()` 会调，
     * 于是 `Discovery\LocalNode` 指望在 `onStop` 里注销、账本指望在这里落最后一次快照——
     * 全都不执行。实测（2026-11-25，tests/shutdown-guard）：空闲进程等满 `max_wait_time`
     * 被强杀（`ERRNO 9101 worker exit timeout, forced termination`），一个 `onStop` 都没跑。
     *
     * ## 顺序及理由
     *
     * 1. **先停定时器**：停机期间不该再有定时器往信箱里投消息，否则排空永远排不完。
     * 2. **排空就绪队列**（有预算）：已经收到的请求尽量处理完再走。之后信箱里还剩的
     *    会被丢弃并计数——这是停机唯一会丢的消息，所以必须可见。
     * 3. **逆着创建顺序逐个 `onStop`**：后创建的服务更可能依赖先创建的（房间依赖世界、
     *    战斗依赖房间），反着停才不会出现"被依赖者先没了"。单个 `onStop` 抛异常不影响其它服务。
     * 4. **收尾钩子**（`onShutdown()`）：所有服务都停了之后做进程级的落盘。
     * 5. **把跨节点写缓冲冲出去**：让对端最后收到的消息尽量完整。
     *
     * ## 能保证什么、不能保证什么
     *
     * 能：`onStop` 和收尾钩子一定被**调用**（单个抛异常也会继续），排空有上限不会卡死。
     * 不能：**打断**一个卡住的 `onStop`——它是同步代码，卡住了整个 worker 就跟着卡住，
     * 只能靠外部（k8s 的 terminationGracePeriod）兜底。所以每个超过预算的 `onStop` 都记 warn。
     *
     * @param int $drainMs 排空预算（毫秒）。0 = 不排空，直接停。
     * @return array<string, int> drained / dropped / stopped / failed / timers / ms
     */
    public function shutdown(int $drainMs = 1000): array
    {
        if ($this->shutdownResult !== null) {
            return $this->shutdownResult;
        }
        $t0 = hrtime(true);
        $this->stopping = true;

        $cancelled = $this->timers->clearAll();
        if ($cancelled > 0) {
            $this->stat["timers_cancelled"] += $cancelled;
        }

        $drained = 0;
        if ($drainMs > 0) {
            $deadline = $t0 + $drainMs * 1000000;
            while ($this->readyHead < count($this->ready) && hrtime(true) < $deadline) {
                $n = $this->tick(2000);
                $drained += $n;
                if ($n === 0) {
                    break;
                }
            }
        }
        $this->stopped = true;
        $dropped = 0;
        foreach ($this->boxes as $box) {
            $dropped += $box->clear();
        }

        // 逆着创建顺序停。先拷一份 id 列表：kill() 会从 services 里删元素
        $ids = array_keys($this->services);
        $stopped = 0;
        $failed = 0;
        $slow = 0;
        for ($i = count($ids) - 1; $i >= 0; $i--) {
            $id = $ids[$i];
            $t1 = hrtime(true);
            try {
                if ($this->kill($id)) {
                    $stopped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Logger::error("service onStop threw during shutdown", [
                    "svc" => Addr::label($id),
                    "err" => $e->getMessage(),
                ]);
            }
            $costMs = intdiv(hrtime(true) - $t1, 1000000);
            if ($costMs >= 1000) {
                $slow++;
                Logger::warn("service onStop is slow during shutdown", [
                    "svc" => Addr::label($id),
                    "ms" => $costMs,
                ]);
            }
        }

        foreach ($this->shutdownHooks as $hook) {
            try {
                $hook();
            } catch (\Throwable $e) {
                $failed++;
                Logger::error("shutdown hook threw", ["err" => $e->getMessage()]);
            }
        }

        if ($this->cluster !== null) {
            $this->cluster->flush();
        }

        $this->stat["shutdown_dropped"] = $dropped;
        $this->shutdownResult = [
            "drained" => $drained,
            "dropped" => $dropped,
            "stopped" => $stopped,
            "failed" => $failed,
            "slow" => $slow,
            "timers" => $cancelled,
            "ms" => intdiv(hrtime(true) - $t0, 1000000),
        ];
        Logger::info("scheduler shutdown", $this->shutdownResult);
        return $this->shutdownResult;
    }

    /**
     * 注册停机收尾钩子：所有服务的 `onStop` 都执行完之后按注册顺序调用。
     *
     * 给"不属于任何一个服务的东西"用——进程级的落盘、关连接池。
     * 属于某个服务的收尾写在它自己的 `onStop()` 里。
     */
    public function onShutdown(\Closure $fn): void
    {
        $this->shutdownHooks[] = $fn;
    }

    /** 是否已进入停机流程（`shutdown()` 调用之后一直为 true） */
    public function stopping(): bool
    {
        return $this->stopping;
    }

    /** @return array<string, int>|null 停机结果；没停过时为 null */
    public function shutdownResult(): ?array
    {
        return $this->shutdownResult;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["services"] = count($this->services);
        $out["ready"] = count($this->ready) - $this->readyHead;
        $out["timers"] = $this->timers->size();
        $out["id_wraps"] = $this->ids->wraps();
        // 还没用过的服务 id。接近 0 就快回绕了——回绕不是错误，但这是观察它的唯一窗口
        $out["ids_left"] = $this->ids->left();
        // 定时器内部结构规模。排障用：服务都销毁了内存不降时，看这两项——
        // timers 为 0 而 timer_wheel_items 不为 0，就是"死对象还在轮里等游标"
        $out["timer_detail"] = $this->timers->debugSizes();
        $out["timer_wheel_items"] = $this->timers->wheelItems();
        return $out;
    }
}
