<?php

namespace TSF\Handoff;

use TSF\Metrics\Metrics;

/**
 * 自动重平衡：把"本地持有、但不该归本地"的 key 按限流节奏交出去。
 *
 * ## 它只做编排，不做决定
 *
 * 三件事各归各的：`Ownership` 决定该归谁，`Handoff` 负责搬，`Gate` 负责挡住期间的请求。
 * Rebalancer 只做**调度**：扫差异、限并发、把结果收口。所以它自身没有业务语义，
 * 也不需要知道 key 是房间还是玩家。
 *
 * ## 为什么"结果"用轮询而不是回调
 *
 * `Handoff` 的 `onCommit/onRollback` 只有一个槽位，业务已经占了（它要自己删本地状态）。
 * 调度器再抢同一个槽位就会互相覆盖。所以 `Handoff` 额外留了可查询的 `lastResult()`，
 * Rebalancer 每轮自己收口——两边互不干扰。
 *
 * ## 限流是必须的，不是优化
 *
 * 一次扩容可能牵动几千个 key。全量并发会把链路带宽吃满，并让目标端同时收到几千份
 * 快照（那些快照还要各自 freeze 一个 key）。`$limit` 是同时在飞的交接数上限，
 * 到顶就停手，下一轮再来——**背压比吞吐重要**：交接慢一点没关系，把线上拖垮不行。
 *
 * ## 调用节奏
 *
 * `scan()` 由调用方周期性驱动（和 `FrameLoop`、`Handoff::expire()` 一致）。
 * 一轮 scan 会：驱动超时 → 收口上一轮结果 → 发起新交接（受 limit 约束）。
 */
final class Rebalancer
{
    private int $nodeId;

    private int $entry;

    private Handoff $handoff;

    private Gate $gate;

    private Ownership $ownership;

    /** 同时在飞的交接上限 */
    private int $limit;

    /** 每个 key 冻结期间的队列上限 */
    private int $queueMax;

    /** 交接失败后多久内不再重试（毫秒）。没有它，目标持续拒绝会变成每轮打一次的热循环 */
    private int $retryMs;

    /** @var array<string, int> key => 最早可重试时刻 */
    private array $retryAt = [];

    /**
     * 已知"版本落后"的目标节点 => 重新试探的时刻。
     *
     * 为什么不是"换一个目标"：`Ownership` 指出的 owner 是**契约**，
     * 搬到非 owner 会造出所有权分家（两个节点都认为自己该持有）——
     * 那比"晚一点再搬"糟得多。滚动升级期间的正确行为是等对方升上来。
     *
     * 也不要每轮都去试：对方没升级，试一次被拒一次，白占限流槽位。
     *
     * @var array<int, int>
     */
    private array $staleNode = [];

    /** 目标被判定为版本落后后，多久再试探一次 */
    private int $staleTtlMs = 30000;

    /** 归属租约。为 null 时退化成"只信 Ring"（单机或测试） */
    private ?Lease $lease = null;

    private int $leaseTtlMs = 30000;

    /** @var array<string, int> key => 本地认为的租约到期时刻 */
    private array $leased = [];

    /** @var array<string, int> 本地持有的 key => 已用过的版本号 */
    private array $keys = [];

    /** @var array<string, int> 已发起、等结果的 key => 目标节点 */
    private array $tracked = [];

    /** @var \Closure|null function (string $key): ?string 导出状态；返回 null 表示暂时不能搬 */
    private ?\Closure $onExport = null;

    /** @var \Closure|null function (string $key, int $target, array $queued): void */
    private ?\Closure $onMoved = null;

    /** @var \Closure|null function (string $key, int $target, string $why): void */
    private ?\Closure $onFailed = null;

    /** @var array<string, int> */
    private array $stat = [
        "scanned" => 0,
        "skew_skipped" => 0,
        "started" => 0,
        "moved" => 0,
        "failed" => 0,
        "skipped" => 0,
        "lease_denied" => 0,
        "lease_lost" => 0,
        "version_blocked" => 0,
    ];

    public function __construct(
        int $nodeId,
        int $entry,
        Handoff $handoff,
        Gate $gate,
        Ownership $ownership,
        int $limit = 8,
        int $queueMax = 256,
        int $retryMs = 1000
    ) {
        $this->nodeId = $nodeId;
        $this->entry = $entry;
        $this->handoff = $handoff;
        $this->gate = $gate;
        $this->ownership = $ownership;
        $this->limit = $limit > 0 ? $limit : 1;
        $this->queueMax = $queueMax > 0 ? $queueMax : 256;
        $this->retryMs = $retryMs > 0 ? $retryMs : 1000;
    }

    /** 导出状态。返回 null 表示这个 key 现在不能搬（比如正在结算），调度器会跳过它 */
    public function onExport(\Closure $cb): void
    {
        $this->onExport = $cb;
    }

    /**
     * 交接成功。`$queued` 是冻结期间攒下的请求（已按原序），调用方负责转给新宿主。
     *
     * 回调里**不要**再做"删本地状态"之外的重活：它跑在 scan 的循环里，会拖慢整轮。
     */
    public function onMoved(\Closure $cb): void
    {
        $this->onMoved = $cb;
    }

    /** 交接失败。key 仍归本地，闸门已回到 OPEN */
    public function onFailed(\Closure $cb): void
    {
        $this->onFailed = $cb;
    }

    /**
     * 启用归属租约。
     *
     * 开了之后，**只有拿到租约才允许发起交接**。这是防"两个节点同时认为自己是
     * owner、于是同时往两个方向搬"的唯一手段——Ring 算出来的只是"应该归谁"，
     * 不保证"实际是谁在跑"。
     *
     * @param int $ttlMs 租约有效期；持有期间每轮 scan 会在剩余不足一半时续期
     */
    /** 调整"版本落后"目标的重新试探间隔 */
    public function setStaleTtl(int $ms): void
    {
        $this->staleTtlMs = $ms > 0 ? $ms : 30000;
    }

    /** 该节点当前是否被判定为版本落后（观测/告警用） */
    public function isStale(int $node): bool
    {
        return isset($this->staleNode[$node]);
    }

    /** @return array<int, int> 节点 => 到期时刻（毫秒），给监控/排障看 */
    public function staleNodes(): array
    {
        return $this->staleNode;
    }

    public function useLease(Lease $lease, int $ttlMs = 30000): void
    {
        $this->lease = $lease;
        $this->leaseTtlMs = $ttlMs > 0 ? $ttlMs : 30000;
    }

    /** 登记一个本地持有的 key（业务在创建时调）。重复登记返回 false */
    public function own(string $key): bool
    {
        if ($key === "" || isset($this->keys[$key])) {
            return false;
        }
        $this->keys[$key] = 0;
        return true;
    }

    /** 业务自己销毁了这个 key（不再参与重平衡）。顺手把租约还回去，别等它过期 */
    public function disown(string $key): void
    {
        unset($this->keys[$key]);
        unset($this->retryAt[$key]);
        if ($this->lease !== null) {
            $this->lease->release($key);
        }
        unset($this->leased[$key]);
    }

    public function isOwned(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function ownCount(): int
    {
        return count($this->keys);
    }

    public function inflight(): int
    {
        return count($this->tracked);
    }

    /**
     * 跑一轮。返回本轮**新发起**的交接数。
     *
     * 返回 0 不代表没事可做：可能是到限流上限了，也可能是没有差异。
     * 要看区别请查 `stats()` 里的 scanned / started。
     */
    public function scan(int $nowMs): int
    {
        // 1) 先让 Handoff 处理超时（否则"卡住的"会一直占着 tracked）
        $this->handoff->expire($nowMs);
        // 2) 在飞的也要续租：交接可能跑好几秒，而它们不在下面的候选循环里
        //    （那里一看到 pending 就 continue），不在这里续就会中途过期
        $this->renewTracked($nowMs);
        // 3) 收口上一轮的结果
        $this->collect($nowMs);
        // 3) 发起新的
        $started = 0;
        $inflight = count($this->tracked);
        $snapshot = $this->keys;

        foreach ($snapshot as $key => $ver) {
            $k = (string) $key;
            $this->stat["scanned"]++;
            if ($inflight >= $this->limit) {
                break;
            }
            if ($this->handoff->isPending($k) || $this->handoff->isAborting($k)) {
                // 撤销还没收尾时也不能重新发起，否则同一份状态会有两次在飞
                continue;
            }
            // 刚失败过的 key 先冷静一会儿：目标持续拒绝时，每轮重试一次就是热循环，
            // 而且会让同一份快照在链路上反复传
            if (($this->retryAt[$k] ?? 0) + $this->retryMs > $nowMs) {
                continue;
            }
            $target = $this->ownership->ownerOf($k);
            if ($target <= 0 || $target === $this->nodeId) {
                continue;
            }
            // 目标刚被判定版本落后：这轮别碰它，省下槽位也省一次被拒
            if (isset($this->staleNode[$target])) {
                if ($this->staleNode[$target] > $nowMs) {
                    $this->stat["skew_skipped"]++;
                    continue;
                }
                unset($this->staleNode[$target]);
            }

            // 动手之前先举手：拿不到租约说明别人认为这个 key 归他，
            // 此时搬过去只会制造两份状态
            if (!$this->takeLease($k, $nowMs)) {
                $this->stat["lease_denied"]++;
                continue;
            }

            $state = "";
            $ready = false;
            $cb = $this->onExport;
            if ($cb instanceof \Closure) {
                $out = $cb($k);
                if (is_string($out)) {
                    $state = $out;
                    $ready = true;
                }
            }
            if (!$ready) {
                $this->stat["skipped"]++;
                continue;
            }

            if (!$this->gate->freeze($k, $target, $this->entry, $this->queueMax)) {
                continue;
            }
            $ver++;
            if (!$this->handoff->begin($target, $this->entry, $k, $ver, $state, $nowMs)) {
                // 发不出去（链路没握手）：立刻解冻，别让业务卡在"排队"里
                $this->gate->rollback($k);
                continue;
            }
            $this->keys[$k] = $ver;
            $this->tracked[$k] = $target;
            $inflight++;
            $started++;
            $this->stat["started"]++;
        }
        return $started;
    }

    /**
     * 取或续租约。没配租约时直接放行（单机部署不该被迫起一个 Redis）。
     *
     * 续期只在**剩余不足一半**时做：每轮都续会把 Redis 变成每键每轮的写放大。
     */
    private function takeLease(string $key, int $nowMs): bool
    {
        if ($this->lease === null) {
            return true;
        }
        $exp = $this->leased[$key] ?? 0;
        if ($exp <= $nowMs) {
            if (!$this->lease->tryAcquire($key, $this->leaseTtlMs)) {
                return false;
            }
            $this->leased[$key] = $nowMs + $this->leaseTtlMs;
            return true;
        }
        if ($exp - $nowMs < intdiv($this->leaseTtlMs, 2)) {
            if (!$this->lease->renew($key, $this->leaseTtlMs)) {
                // 续不上说明已经过期被别人拿走：本地必须立刻放弃这个 key，
                // 否则会出现"两个人各自拿着同一把锁"
                unset($this->leased[$key]);
                $this->stat["lease_lost"]++;
                return false;
            }
            $this->leased[$key] = $nowMs + $this->leaseTtlMs;
        }
        return true;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["owned"] = count($this->keys);
        $out["inflight"] = count($this->tracked);
        return $out;
    }

    /**
     * 给在飞的交接续租；续不上就撤销这次交接。
     *
     * 这是"租约中途丢失"的唯一处理点：别的节点已经接手这个 key，你这次交接再走完，
     * 就等于两份状态同时存在。撤销会发 ABORT 让目标侧卸掉已装的部分。
     */
    private function renewTracked(int $nowMs): void
    {
        if ($this->lease === null || $this->tracked === []) {
            return;
        }
        $lost = [];
        foreach ($this->tracked as $key => $target) {
            $k = (string) $key;
            $exp = $this->leased[$k] ?? 0;
            if ($exp - $nowMs >= intdiv($this->leaseTtlMs, 2)) {
                continue;
            }
            if (!$this->lease->renew($k, $this->leaseTtlMs)) {
                $lost[] = $k;
                continue;
            }
            $this->leased[$k] = $nowMs + $this->leaseTtlMs;
        }
        foreach ($lost as $k) {
            $this->stat["lease_lost"]++;
            unset($this->leased[$k]);
            if ($this->handoff->isPending($k)) {
                $this->handoff->abort($k, $nowMs);
            }
        }
    }

    /** 收口：把已出结果的交接通知业务，并清理跟踪表 */
    private function collect(int $nowMs): void
    {
        if ($this->tracked === []) {
            return;
        }
        $done = [];
        foreach ($this->tracked as $key => $target) {
            $k = (string) $key;
            if ($this->handoff->isPending($k) || $this->handoff->isAborting($k)) {
                // 撤销还没收尾时也不能重新发起，否则同一份状态会有两次在飞
                continue;
            }
            $res = $this->handoff->lastResult($k);
            if ($res === "committed") {
                $queued = $this->gate->commit($k);
                unset($this->keys[$k]);
                unset($this->retryAt[$k]);
                // 交出去了就把租约放掉：新宿主会在 onInstall 里自己取
                if ($this->lease !== null) {
                    $this->lease->release($k);
                }
                unset($this->leased[$k]);
                $done[] = $k;
                $this->stat["moved"]++;
                $cb = $this->onMoved;
                if ($cb instanceof \Closure) {
                    $cb($k, $target, $queued);
                }
            } elseif ($res === "rolledback") {
                $this->gate->rollback($k);
                $why = $this->handoff->lastWhy($k);
                // "version" = 目标比源旧，读不懂这份状态。重试没有意义——
                // 对方要升级代码才会变。给一个长退避，别每秒打一次
                if ($why === "version") {
                    $this->stat["version_blocked"]++;
                    // 按**节点**记，不是按 key：同一个目标上所有 key 都会被拒，
                    // 逐个 key 退避等于每轮都要把每个 key 撞一遍
                    $this->staleNode[$target] = $nowMs + $this->staleTtlMs;
                    Metrics::inc("rebalance.version_blocked");
                    $this->retryAt[$k] = $nowMs + $this->retryMs * 10;
                } else {
                    $this->retryAt[$k] = $nowMs;
                }
                $done[] = $k;
                $this->stat["failed"]++;
                $cb2 = $this->onFailed;
                if ($cb2 instanceof \Closure) {
                    $cb2($k, $target, $this->handoff->lastWhy($k));
                }
            }
            // 空串：既不在飞也没有结果（被外部 rollback 过），留在表里下轮再看
        }
        foreach ($done as $k) {
            unset($this->tracked[$k]);
        }
    }
}
