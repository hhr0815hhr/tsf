<?php

namespace TSF\Handoff;

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
        "started" => 0,
        "moved" => 0,
        "failed" => 0,
        "skipped" => 0,
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

    /** 登记一个本地持有的 key（业务在创建时调）。重复登记返回 false */
    public function own(string $key): bool
    {
        if ($key === "" || isset($this->keys[$key])) {
            return false;
        }
        $this->keys[$key] = 0;
        return true;
    }

    /** 业务自己销毁了这个 key（不再参与重平衡） */
    public function disown(string $key): void
    {
        unset($this->keys[$key]);
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
        // 2) 收口上一轮的结果
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
            if ($this->handoff->isPending($k)) {
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

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["owned"] = count($this->keys);
        $out["inflight"] = count($this->tracked);
        return $out;
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
            if ($this->handoff->isPending($k)) {
                continue;
            }
            $res = $this->handoff->lastResult($k);
            if ($res === "committed") {
                $queued = $this->gate->commit($k);
                unset($this->keys[$k]);
                $done[] = $k;
                $this->stat["moved"]++;
                $cb = $this->onMoved;
                if ($cb instanceof \Closure) {
                    $cb($k, $target, $queued);
                }
            } elseif ($res === "rolledback") {
                $this->gate->rollback($k);
                $this->retryAt[$k] = $nowMs;
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
