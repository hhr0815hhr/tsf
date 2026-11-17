<?php

namespace TSF\Service;

use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Kernel\Logger;
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

    /** @var array<int, Service> id => 服务实例 */
    private array $services = [];

    /** @var array<int, Mailbox> id => 信箱 */
    private array $boxes = [];

    /** @var array<string, \Closure> 名字 => 工厂（返回 Service） */
    private array $factories = [];

    /** @var array<int, int> 就绪队列（服务 id），FIFO */
    private array $ready = [];

    private int $readyHead = 0;

    private int $nextId = 1;

    /**
     * 单个服务在一轮里最多处理多少条。
     *
     * 这是"公平性 vs 吞吐"的旋钮：调大吞吐略高但会拉长其他服务的等待；
     * 调小更公平但调度开销占比上升。200 在两者间比较平衡（实测见 bench/service）。
     */
    private int $budget = 200;

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

    /** 当前正在处理哪个服务（用于给发出的消息填 from） */
    private int $current = 0;

    /**
     * 协程模式开关（默认关）。
     *
     * 关闭时 `currentOf()` 直接读属性，和原来等价（多一次方法调用，约 50ns）。
     * 开启后每条消息跑在协程里、`current` 存进**协程上下文** —— 否则多个协程
     * 会互相覆盖这个全局属性，发出的消息 from 全乱（症状极难查：
     * 「服务收到了消息但不知道谁发的」）。
     */
    private bool $coroutine = false;

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
    private function currentOf(): int
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

    private function setCurrentOf(int $addr): void
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
    ];

    public static function instance(): Scheduler
    {
        if (self::$self === null) {
            self::$self = new Scheduler();
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
        if ($this->nextId > Addr::MAX_ID) {
            throw new \RuntimeException("service: 本地服务 id 用尽");
        }

        $id = $this->nextId;
        $this->nextId++;

        $svc = $factory();
        if (!($svc instanceof Service)) {
            throw new \RuntimeException("service: 工厂必须返回 Service，返回了 " . gettype($svc));
        }

        $svc->bindAddr($id);
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
        $svc->onStop();
        return true;
    }

    /** 投递消息。跨节点地址交给 Cluster；无 Cluster 时返回 false */
    public function deliver(int $to, int $msgId, string|array|object $data = "", int $session = 0): bool
    {
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
            if ($this->cluster === null) {
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
        if ($box === null) {
            $this->stat["undelivered"]++;
            return false;
        }

        // 每消息的 sent 计数不进热路径：一次数组自增约 174 ns。
        // 吞吐类指标由压测/监控层按批次统计，框架只记低频事件（spawn/kill/error）。
        if (!$box->push(new Message($this->currentOf(), $msgId, $data, $session))) {
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
        if ($this->timers->size() > 0) {
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

        while ($handled < $limit && $this->readyHead < count($this->ready)) {
            $id = $this->ready[$this->readyHead];
            $this->readyHead++;

            if (!isset($this->services[$id])) {
                // 在处理别的服务时被 kill 掉了
                continue;
            }

            $svc = $this->services[$id];
            $box = $this->boxes[$id];
            $box->queued = false;

            // current 按批设置而不是按条：同一批消息都由同一个服务处理，
            // 每条都存/恢复要两次属性写（约 300 ns），按批做只付一次。
            $prev = $this->currentOf();
            $this->setCurrentOf($id);

            $n = 0;
            // budget 提到局部变量：内层每轮读一次属性约 150 ns，按条累加很可观
            $budget = $this->budget;
            // 看门狗阈值也提到局部：关闭时只多一次整数比较
            $wdNs = $this->watchdogNs;
            $m = null;
            // try/catch 放在批外而不是每条外面：AOT 下每进一次 try 都要装一次
            // 异常跳转上下文（实测差 300+ ns/条）。代价是出错时无法精确定位到某一条，
            // 但语义上本来就是"服务出错即销毁"，剩下的积压消息随服务一起丢弃。
            try {
                while ($n < $budget) {
                    $m = $box->pop();
                    if ($m === null) {
                        break;
                    }
                    if ($wdNs === 0) {
                        $svc->onMessage($m);
                    } else {
                        $t0 = hrtime(true);
                        $svc->onMessage($m);
                        $dur = hrtime(true) - $t0;
                        if ($dur > $wdNs) {
                            // 只能事后记账：实测无法打断正在执行的 CPU 循环（见 watchdogNs 注释）
                            if ($this->onSlow($id, $m, $dur)) {
                                break;
                            }
                        }
                    }
                    $handled++;
                    $n++;
                }
            } catch (\Throwable $e) {
                // 单个服务出错不该拖垮整个进程：记录后销毁它（状态可能已不一致）
                $this->stat["errors"]++;
                Logger::error("service error, killing", [
                    "svc" => Addr::label($id),
                    "msgId" => $m === null ? 0 : $m->msgId,
                    "err" => $e->getMessage(),
                ]);
                $this->setCurrentOf($prev);
                $this->kill($id);
            }
            $this->setCurrentOf($prev);
            // 每条都做 $stat["handled"]++ 要 174 ns；按批累加等价，代价可忽略
            $this->stat["handled"] += $n;

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

        return $handled;
    }

    /**
     * 独立运行：处理完就绪消息，没活就睡一会儿。
     *
     * 用在"纯服务进程"里（没有网络事件驱动）。如果是跑在 Swoole 服务器里，
     * 应该由网络回调调用 tick()，不要开 run()，否则会和事件循环抢控制权。
     */
    public function run(): void
    {
        while (true) {
            // 单轮限量：让循环有机会回到这里（将来接上退出信号/统计刷新）
            if ($this->tick(2000) === 0) {
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
        if ($ms < 0) {
            $ms = 0;
        }
        $now = self::nowMs();
        return $this->timers->add($now, $addr, $now + $ms, $msgId, $data);
    }

    /** 周期定时：每 $ms 投一条，返回定时器 id（cancel 时用同一个 id） */
    public function interval(int $addr, int $ms, int $msgId, string|array|object $data = ""): int
    {
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
    private function onSlow(int $id, Message $m, int $durNs): bool
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

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["services"] = count($this->services);
        $out["ready"] = count($this->ready) - $this->readyHead;
        $out["timers"] = $this->timers->size();
        return $out;
    }
}
