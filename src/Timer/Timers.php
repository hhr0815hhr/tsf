<?php

namespace TSF\Timer;

/**
 * 定时器集合：**时间轮**（近端） + 小顶堆（远端）。
 *
 * 为什么不是纯最小堆：实测（AOT）10 万定时器时 push 7.2 µs、**pop 32 µs**，
 * 光排空就要 3.2 秒，误差 300+ ms，根本达不到 20 ms。
 * 原因是二叉堆每下沉一层要做约 10 次数组/属性访问，而 AOT 下每次访问约 150 ns，
 * 17 层下来就是 25 µs 量级——这个结构在 PHP/AOT 里不适合 10 万量级。
 *
 * 时间轮的代价则与规模无关：入槽一次数组追加、出槽一次数组读，都是常数。
 *
 *   wheel[ms & 4095] = 该毫秒到期的定时器列表
 *   cursor           = 已经排空到的毫秒
 *
 * 覆盖 [cursor, cursor+4096) 这个近端窗口；更远的（cron、每日重置）放远端堆，
 * 等它进入窗口再挪过来。远端数量通常只有几十个，用堆完全没问题。
 *
 * 时间用**单调毫秒**（hrtime），不用 time()：墙钟被 NTP 回拨会让定时器集体错乱。
 */
final class Timers
{
    /** 近端窗口：4096 个毫秒槽 = 4.096 秒 */
    private const WHEEL_SIZE = 4096;

    private const WHEEL_MASK = 4095;

    /** @var array<int, array<int, Timer>> 毫秒槽下标 → 定时器列表 */
    private array $wheel = [];

    /** @var array<int, Timer> 已过期、下一轮必须立刻处理的（入槽时就已经晚了） */
    private array $immediate = [];

    /** 远端定时器 */
    private TimerHeap $far;

    /** 已排空到的毫秒；-1 表示尚未开始 */
    private int $cursor = -1;

    /** @var array<int, Timer> id => 当前有效条目 */
    private array $live = [];

    /** @var array<int, array<int, int>> 服务地址 => [id, ...] */
    private array $byService = [];

    private int $nextId = 1;

    private int $tombstones = 0;

    /** 近端最早到期时刻（只是给 run() 休眠用的提示，可能偏小） */
    private int $nearMinDue = 0;

    public function __construct()
    {
        $this->far = new TimerHeap();
    }

    /**
     * 注册定时器。
     *
     * @param int $nowMs    当前单调毫秒（由调用方提供，Timer 模块不自己取时钟，便于测试）
     * @param int $dueAtMs  到期时刻；已过去的会立刻在下一轮触发
     * @param int $intervalMs 周期（0 = 一次性）
     * @param string $cron   cron 表达式（与 intervalMs 互斥）
     */
    public function add(
        int $nowMs,
        int $addr,
        int $dueAtMs,
        int $msgId,
        string|array|object $data,
        int $intervalMs = 0,
        string $cron = ""
    ): int {
        if ($this->cursor < 0) {
            $this->cursor = $nowMs - 1;
        }
        $id = $this->nextId;
        $this->nextId++;

        $t = new Timer($id, $dueAtMs, $addr, $msgId, $data, $intervalMs, $cron);
        $this->live[$id] = $t;
        if (!isset($this->byService[$addr])) {
            $this->byService[$addr] = [];
        }
        $this->byService[$addr][] = $id;

        $this->place($t);
        return $id;
    }

    /** 入槽：近端进轮、远端进堆、已过期的进 immediate */
    private function place(Timer $t): void
    {
        if ($t->dueAt <= $this->cursor) {
            $this->immediate[] = $t;
            return;
        }
        if ($t->dueAt - $this->cursor < self::WHEEL_SIZE) {
            $this->wheel[$t->dueAt & self::WHEEL_MASK][] = $t;
            if ($this->nearMinDue === 0 || $t->dueAt < $this->nearMinDue) {
                $this->nearMinDue = $t->dueAt;
            }
            return;
        }
        $this->far->push($t);
    }

    public function cancel(int $id): bool
    {
        if (!isset($this->live[$id])) {
            return false;
        }
        $t = $this->live[$id];
        $t->cancelled = true;
        unset($this->live[$id]);
        $this->tombstones++;

        $addr = $t->addr;
        if (isset($this->byService[$addr])) {
            $kept = [];
            foreach ($this->byService[$addr] as $tid) {
                if ($tid !== $id) {
                    $kept[] = $tid;
                }
            }
            if ($kept === []) {
                unset($this->byService[$addr]);
            } else {
                $this->byService[$addr] = $kept;
            }
        }
        return true;
    }

    /** 服务销毁时调用：撤销它名下所有定时器，避免定时器把死服务"复活" */
    public function cancelAllOf(int $addr): int
    {
        if (!isset($this->byService[$addr])) {
            return 0;
        }
        $ids = $this->byService[$addr];
        $n = 0;
        foreach ($ids as $id) {
            if (isset($this->live[$id])) {
                $this->live[$id]->cancelled = true;
                unset($this->live[$id]);
                $this->tombstones++;
                $n++;
            }
        }
        unset($this->byService[$addr]);
        return $n;
    }

    public function size(): int
    {
        return count($this->live);
    }

    public function tombstones(): int
    {
        return $this->tombstones;
    }

    /**
     * 最早到期时刻（毫秒）。仅作为 run() 的休眠提示：
     * 近端提示可能已经过期（返回小于 now 的值），调用方按"立刻跑"处理即可。
     */
    public function nextDueAt(): int
    {
        if ($this->immediate !== []) {
            return $this->cursor;
        }
        if ($this->nearMinDue > $this->cursor) {
            return $this->nearMinDue;
        }
        $top = $this->far->peek();
        return $top === null ? 0 : $top->dueAt;
    }

    /**
     * 取出所有在 $nowMs 之前（含）到期的定时器，并重排周期/cron 的。
     *
     * @return array<int, Timer>
     */
    public function due(int $nowMs): array
    {
        if ($this->cursor < 0) {
            $this->cursor = $nowMs - 1;
        }
        $out = [];

        // 1) 入槽时就已过期的
        if ($this->immediate !== []) {
            $list = $this->immediate;
            $this->immediate = [];
            foreach ($list as $t) {
                $fired = $this->take($t);
                if ($fired !== null) {
                    $out[] = $fired;
                }
            }
        }

        // 2) 大跳变（进程被挂起过）：整个窗口都过期了，一次性全取
        if ($nowMs - $this->cursor >= self::WHEEL_SIZE) {
            $this->cursor = $nowMs;
            foreach ($this->wheel as $bucket) {
                foreach ($bucket as $t) {
                    $fired = $this->take($t);
                    if ($fired !== null) {
                        $out[] = $fired;
                    }
                }
            }
            $this->wheel = [];
            while (true) {
                $top = $this->far->peek();
                if ($top === null || $top->dueAt > $nowMs) {
                    break;
                }
                $this->far->pop();
                $fired = $this->take($top);
                if ($fired !== null) {
                    $out[] = $fired;
                }
            }
            $this->nearMinDue = 0;
            return $out;
        }

        // 3) 逐毫秒推进
        while ($this->cursor < $nowMs) {
            $this->cursor++;
            $k = $this->cursor & self::WHEEL_MASK;
            if (!isset($this->wheel[$k])) {
                continue;
            }
            $bucket = $this->wheel[$k];
            unset($this->wheel[$k]);
            foreach ($bucket as $t) {
                $fired = $this->take($t);
                if ($fired !== null) {
                    $out[] = $fired;
                }
            }
        }
        if ($this->nearMinDue <= $this->cursor) {
            $this->nearMinDue = 0;
        }

        // 4) 远端补位：把即将进入窗口的挪到轮上
        $limit = $this->cursor + self::WHEEL_SIZE;
        while (true) {
            $top = $this->far->peek();
            if ($top === null || $top->dueAt >= $limit) {
                break;
            }
            $this->far->pop();
            if ($top->cancelled) {
                $this->dropTombstone();
                continue;
            }
            $this->place($top);
        }

        // 补位时可能又产生了"已过期"，这些同样要立刻处理
        if ($this->immediate !== []) {
            $list = $this->immediate;
            $this->immediate = [];
            foreach ($list as $t) {
                $fired = $this->take($t);
                if ($fired !== null) {
                    $out[] = $fired;
                }
            }
        }

        return $out;
    }

    /**
     * 认领一个到期的定时器：负责记账 + 重排，返回要投递的那一个（墓碑返回 null）。
     */
    private function take(Timer $t): ?Timer
    {
        if ($t->cancelled) {
            $this->dropTombstone();
            return null;
        }
        unset($this->live[$t->id]);

        if ($t->intervalMs > 0) {
            // 按"上次到期 + 周期"重排，而不是"现在 + 周期"：后者会把每轮处理耗时
            // 累加成固定漂移。落后太多（进程卡过）时吸附回当前时间，避免补跑风暴。
            $next = $t->dueAt + $t->intervalMs;
            $now = $this->cursor;
            if ($next <= $now) {
                $next = $now + $t->intervalMs;
            }
            $this->rearm($t, $next);
        } elseif ($t->cron !== "") {
            $nextTs = Cron::next($t->cron, intdiv($t->dueAt, 1000));
            if ($nextTs > 0) {
                $this->rearm($t, $nextTs * 1000);
            }
            // 表达式永不触发时不再重排，静默退化为一次性
        }
        return $t;
    }

    private function rearm(Timer $old, int $nextDueAt): void
    {
        $t = new Timer($old->id, $nextDueAt, $old->addr, $old->msgId, $old->data, $old->intervalMs, $old->cron);
        $this->live[$old->id] = $t;
        $this->place($t);
    }

    private function dropTombstone(): void
    {
        $this->tombstones--;
        if ($this->tombstones < 0) {
            $this->tombstones = 0;
        }
    }
}
