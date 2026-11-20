<?php

namespace TSF\Handoff;

/**
 * 交接期间的入口闸门：冻结这个 key，把期间的请求按顺序排队。
 *
 * ## 它补的是 handoff 之外那一半
 *
 * `Handoff` 只管"状态搬过去没有"，但搬运需要时间（一个 RTT 到几秒）。这段时间里
 * 打到这个 key 的请求怎么办？三种做法，只有一种是安全的：
 *
 * | 做法 | 结果 |
 * |:--|:--|
 * | 直接转发给新宿主 | **错的**：新宿主的状态还是老的，此时改动会被交接覆盖掉 |
 * | 直接丢弃 / 让客户端重试 | 能用，但会丢一次写，且客户端重试要和幂等配套 |
 * | **本地排队，交接完成后按序补交** | 正确：队列和快照是同一时刻的，谁最终拥有谁消费 |
 *
 * ## 关键不变量：**先 freeze，再取快照**
 *
 * 顺序反了就有窗口：快照之后、freeze 之前到达的请求，既不在快照里、也进不了队列，
 * 交接完成后凭空消失。所以调用顺序必须是：
 *
 * ```
 * $gate->freeze($key, $targetNode, $targetEntry);
 * $state = $business->export($key);        // 快照（此后本地不再改）
 * $handoff->begin($targetNode, $targetEntry, $key, $ver, $state, now);
 * ```
 *
 * ## 成功与失败各自的收尾
 *
 * - 交接成功（`onCommit`）→ `commit()`：队列交给**新宿主**，之后 `submit()` 返回 `forward`
 * - 交接失败（`onRollback`）→ `rollback()`：队列**在本地**按原序放回，恢复 `OPEN`
 *
 * 两条路都不会丢：区别只是队列最终被谁消费。
 *
 * ## 队列满了怎么办
 *
 * 返回 `overflow` 让调用方**显式拒绝**（回客户端重试）。不做静默丢弃、也不做
 * "丢最老的"——在游戏服里那等于悄悄吞掉一次操作，玩家只会觉得"点了没反应"。
 */
final class Gate
{
    public const OPEN = 0;

    public const FROZEN = 1;

    /** 交接已完成，请求应当直接发给新宿主 */
    public const FORWARD = 2;

    /** 放行（没冻结，本地处理） */
    public const PASS = "pass";

    /** 已排队，等 commit/rollback 决定去向 */
    public const QUEUED = "queued";

    /** 队列满，调用方必须拒绝这个请求 */
    public const OVERFLOW = "overflow";

    /** 已交接，请直接投给新宿主 */
    public const FORWARDED = "forward";

    /** @var array<string, int> */
    private array $state = [];

    /** @var array<string, int> */
    private array $toNode = [];

    /** @var array<string, int> */
    private array $toEntry = [];

    /** @var array<string, int> */
    private array $maxQueue = [];

    /** @var array<string, array<int, string>> */
    private array $queue = [];

    /** @var array<string, int> */
    private array $dropped = [];

    /**
     * 冻结一个 key。返回 false 表示已经在冻结中（重复调用不该重置队列）。
     *
     * @param int $maxQueue 队列上限，超过就返回 overflow 让调用方拒绝
     */
    public function freeze(string $key, int $targetNode, int $targetEntry, int $maxQueue = 256): bool
    {
        if ($key === "") {
            return false;
        }
        $cur = $this->state[$key] ?? self::OPEN;
        if ($cur !== self::OPEN) {
            return false;
        }
        $this->state[$key] = self::FROZEN;
        $this->toNode[$key] = $targetNode;
        $this->toEntry[$key] = $targetEntry;
        $this->maxQueue[$key] = $maxQueue > 0 ? $maxQueue : 256;
        $this->queue[$key] = [];
        $this->dropped[$key] = 0;
        return true;
    }

    /**
     * 提交一个请求。返回值三态，调用方必须分别处理：
     * `pass` 本地处理 / `queued` 等结果 / `overflow` 拒绝 / `forward` 转给新宿主。
     */
    public function submit(string $key, string $payload): string
    {
        $cur = $this->state[$key] ?? self::OPEN;
        if ($cur === self::OPEN) {
            return self::PASS;
        }
        if ($cur === self::FORWARD) {
            return self::FORWARDED;
        }
        $q = $this->queue[$key] ?? [];
        if (count($q) >= ($this->maxQueue[$key] ?? 256)) {
            $this->dropped[$key] = ($this->dropped[$key] ?? 0) + 1;
            return self::OVERFLOW;
        }
        $q[] = $payload;
        $this->queue[$key] = $q;
        return self::QUEUED;
    }

    /**
     * 交接成功：取走队列（按原序交给新宿主），并切到 FORWARD。
     *
     * 返回顺序就是入队顺序——**这个顺序不能乱**，因为队列里的操作之间可能有依赖
     * （先建房间再改配置），打乱等于换了一种语义。
     *
     * @return array<int, string>
     */
    public function commit(string $key): array
    {
        if (($this->state[$key] ?? self::OPEN) !== self::FROZEN) {
            return [];
        }
        $q = $this->queue[$key] ?? [];
        $this->queue[$key] = [];
        $this->state[$key] = self::FORWARD;
        return $q;
    }

    /**
     * 交接失败：取走队列（按原序在本地重放），恢复 OPEN。
     *
     * @return array<int, string>
     */
    public function rollback(string $key): array
    {
        if (($this->state[$key] ?? self::OPEN) !== self::FROZEN) {
            return [];
        }
        $q = $this->queue[$key] ?? [];
        $this->queue[$key] = [];
        $this->state[$key] = self::OPEN;
        return $q;
    }

    public function state(string $key): int
    {
        return $this->state[$key] ?? self::OPEN;
    }

    public function queueSize(string $key): int
    {
        return count($this->queue[$key] ?? []);
    }

    /** 因为队列满被拒的请求数（观测：持续非零说明队列上限太小或目标端太慢） */
    public function dropped(string $key): int
    {
        return $this->dropped[$key] ?? 0;
    }

    /** @return array<int, int> [目标节点, 目标入口]；未冻结时返回 [0, 0] */
    public function targetOf(string $key): array
    {
        return [$this->toNode[$key] ?? 0, $this->toEntry[$key] ?? 0];
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $frozen = 0;
        $forward = 0;
        $queued = 0;
        $dropped = 0;
        foreach ($this->state as $k => $s) {
            if ($s === self::FROZEN) {
                $frozen++;
                $queued += count($this->queue[$k] ?? []);
            } elseif ($s === self::FORWARD) {
                $forward++;
            }
        }
        foreach ($this->dropped as $d) {
            $dropped += $d;
        }
        return [
            "frozen" => $frozen,
            "forward" => $forward,
            "queued" => $queued,
            "dropped" => $dropped,
        ];
    }
}
