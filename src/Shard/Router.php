<?php

namespace TSF\Shard;

use TSF\Breaker\CircuitBreaker;
use TSF\Cluster\Addr;
use TSF\Discovery\Contract\Registry;
use TSF\Rpc\Client;
use TSF\Rpc\Rpc;
use TSF\Service\Scheduler;

/**
 * 跨服路由：**按 uid 找到目标节点并调用过去，调用方不需要知道它在哪**。
 *
 * ```php
 * $router->call($uid, "getProfile", "1001", function (?string $resp, string $err): void {
 *     // 不管 uid 在本机还是别的节点，这里拿到的结果形式完全一样
 * });
 * ```
 *
 * ## "透明"靠的是地址，不是特判
 *
 * 很多人会写成"如果目标是自己就走本地，否则走网络"两套分支。这里不需要：
 * 目标地址统一是 `Addr::pack($nodeId, $entry)`，而 `Scheduler::deliverAs()`
 * 内部就是按地址的节点位决定走本地信箱还是 Cluster。
 *
 * 所以本机和远端的**代码路径是同一条**，区别只在一个位运算里。
 * 这也是为什么 RPC 层不需要知道"跨服"这件事。
 *
 * ## 三个必须在建连前拦掉的失败
 *
 * | 情况 | 错误 | 为什么提前拦 |
 * |:--|:--|:--|
 * | 环里没有可用节点 | `no-node` | 直接说清楚，别让调用方等到超时 |
 * | 节点记录不见了 | `node-unknown` | 注册表刚清掉，重试一次可能就好 |
 * | 节点已过期 | `node-stale` | 还没被 `refresh` 摘掉，但已经不该发 |
 * | codec 不同 | `codec-mismatch:raw!=json` | 这是**最有用**的一条，见下 |
 * | 该目标的在飞数到顶 | `target-saturated` | 挡住"一个坏节点占满整个待发表"，见 `$maxPerTarget` |
 * | 该目标正在熔断 | `circuit-open` | 已经确认它不应答，不必再试 |
 *
 * codec 检查是这个类存在的重要理由之一：等到调用失败，对端只能报
 * `bad-response`（"我解不出来"），因为解不出来的那一端**没法知道对方用的是什么协议**。
 * 注册信息里有 codec 名字，就能在这里直接说出"你要发 JSON，对端是 raw"。
 *
 * 注意它只是**声明**不是保证：注册表写 json、实际跑 raw，这里拦不住。
 * 强保证要靠握手协商，那是更高层的事。
 */
final class Router
{
    private Registry $registry;

    private Client $client;

    /** 本节点号（判断"是不是自己"其实用不到，但统计要用） */
    private int $localNode;

    /** 判定节点过期的阈值，应当和注册表的 TTL 一致 */
    private int $ttlMs;

    private Ring $ring;

    /** 多久重建一次环（节点增删靠它反映） */
    private int $refreshMs;

    private int $lastRefreshMs = 0;

    private string $codecName;

    /** 统计：本地命中 / 远端命中 / 各类失败 */
    private int $hitLocal = 0;

    private int $hitRemote = 0;

    private int $missNoNode = 0;

    private int $missUnknown = 0;

    private int $missStale = 0;

    private int $missCodec = 0;

    private int $fastOpen = 0;

    private int $degraded = 0;

    private int $rejectedSaturated = 0;

    /** 熔断器：判断"这个节点还干不干活"（注册表只管"还在不在"） */
    private CircuitBreaker $breaker;

    /**
     * @var array<int, int> 节点 => 当前在飞数
     *
     * 小整数当 key（节点号），不用 hash。
     */
    private array $inFlight = [];

    /**
     * 单个目标最多允许多少个请求在飞。
     *
     * ## 为什么必须限量
     *
     * `Client` 的待发表是**所有目标共用**的。一个节点卡住（收到不回），
     * 发往它的请求会一直挂到超时；这期间它们占着共用待发表和调用方的并发窗口，
     * 把发往健康节点的请求也挤得发不出去——**这就是雪崩的起点，而且它发生在熔断生效之前**。
     *
     * 压测（§18.5）量到的正是这个：冻结 135 ms 内吞吐从 16.9k 掉到 1k（-94%），
     * 而健康请求本身的延迟只有 2.5 ms。**延迟没坏，是根本发不出去。**
     *
     * 熔断拦的是"已经判定它有病的"之后的请求，在那之前（前 3 次超时回来之前）
     * 什么都拦不住——所以还需要这一层：**不管对方是死是活，一个目标最多占 N 个槽位**。
     *
     * ## 配多大
     *
     * 它要 ≥ 单个健康节点在正常负载下的并发需求，否则会削峰（正常时也被限）；
     * 又要足够小，小到一个坏节点占不满整个待发表。默认 64 是按"几十个客户端连接
     * 共用一条链路"估的保守值。压测里给的是更小的值，见 §18.8。
     */
    private int $maxPerTarget;

    /**
     * 是否在 `refresh()` 时清掉已消失节点的熔断记忆。
     *
     * 默认**不清**。理由：节点重启后会短时间重新入环，而刚重启的节点
     * 往往还没预热（缓存空、连接池空），保留熔断记忆能让它多缓 2 秒再上量，
     * 避免"重启即被压死再重启"的循环。
     */
    private bool $forgetOnLeave = false;

    public function __construct(
        Registry $registry,
        Client $client,
        int $localNode,
        int $ttlMs = 3000,
        int $vnodes = 150,
        int $refreshMs = 1000,
        ?CircuitBreaker $breaker = null,
        int $maxPerTarget = 64
    ) {
        $this->registry = $registry;
        $this->client = $client;
        $this->localNode = $localNode > 0 ? $localNode : 1;
        $this->ttlMs = $ttlMs > 0 ? $ttlMs : 3000;
        $this->ring = new Ring($vnodes);
        $this->refreshMs = $refreshMs > 0 ? $refreshMs : 1000;
        $this->codecName = $client->codec()->name();
        $this->breaker = $breaker !== null ? $breaker : new CircuitBreaker();
        $this->maxPerTarget = $maxPerTarget > 0 ? $maxPerTarget : 64;
        $this->refresh();
    }

    /** 单个目标当前在飞数（诊断用） */
    public function inFlight(int $nodeId): int
    {
        return $this->inFlight[$nodeId] ?? 0;
    }

    /**
     * 回调结束时递减在飞数。
     *
     * 保持 `public`：它从闭包里被调用，而"闭包能否访问同类私有方法"在 AOT 下
     * 没验证过（PHP 解释器里是可以的，闭包继承了类的 scope）。多一个公开方法
     * 比调试一个诡异的可见性错误便宜。
     *
     * `$current` 由调用方先读一遍再传进来，避免闭包里连读两次属性。
     */
    public function decInFlight(int $nodeId, int $current): void
    {
        $v = $current - 1;
        if ($v <= 0) {
            $this->inFlight[$nodeId] = 0;
            return;
        }
        $this->inFlight[$nodeId] = $v;
    }

    public function breaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    public function ring(): Ring
    {
        return $this->ring;
    }

    /**
     * 从注册表重建环。返回存活节点数。
     *
     * 节点增删只会影响"uid 落到哪个节点"，不会影响已经在飞的调用——
     * 那些调用拿着已经算好的地址，照常完成。
     */
    public function refresh(): int
    {
        $alive = $this->registry->alive($this->ttlMs);
        $ids = [];
        foreach ($alive as $n) {
            $ids[] = $n->nodeId;
        }
        if ($this->forgetOnLeave) {
            foreach ($this->ring->nodes() as $old) {
                if (!in_array($old, $ids, true)) {
                    $this->breaker->forget($old);
                }
            }
        }
        $this->ring->rebuild($ids);
        $this->lastRefreshMs = (int) (microtime(true) * 1000);
        return count($ids);
    }

    /**
     * 节点离开环时是否清掉熔断记忆。默认不清——见 `$forgetOnLeave` 的说明。
     */
    public function setForgetOnLeave(bool $on): void
    {
        $this->forgetOnLeave = $on;
    }

    /** uid 该去哪个节点。0 表示当前没有可用节点 */
    public function nodeFor(int $uid): int
    {
        $this->maybeRefresh();
        // 加前缀区分 key 空间：以后要按房间号/公会 id 分片时，
        // 同一个数字在不同空间里应当能落到不同节点
        return $this->ring->nodeFor("u:" . $uid);
    }

    /**
     * 按 uid 调用。返回是否真的发出去了。
     *
     * **不管目标在本机还是远端，`$cb` 的语义完全一致**——这是"透明"的定义。
     *
     * `$cb` 一定会被调用一次：发不出去（无节点 / 已熔断 / 协议不符）时立刻调，
     * 发出去了就在响应或超时时调。调用方不需要兜底"回调可能永远不来"。
     *
     * ## 降级（可选）
     *
     * `$fallback` 签名 `(int $uid, string $method): ?string`。目标不可用时
     * （熔断中、节点没了、协议不符）先问它要一个替代值：
     *
     * - 返回 string：**按成功回调**（`$err` 为空）。调用方主动给了兜底值，
     *   就是承认"这个替代结果可以当结果用"——这正是降级的定义。
     * - 返回 null：按原样把错误交给调用方。
     *
     * 注意降级值不带"这是降级数据"的标记。要区分的话，在 `$fallback` 里
     * 返回带标记的内容（比如 `json_encode(["degraded"=>true,"data"=>...])`），
     * 而不是让框架替业务决定数据长什么样。
     */
    public function call(
        int $uid,
        string $method,
        string $body,
        \Closure $cb,
        int $timeoutMs = 0,
        ?\Closure $fallback = null
    ): bool {
        $node = $this->nodeFor($uid);
        if ($node === 0) {
            $this->missNoNode++;
            $this->degrade($fallback, $uid, $method, $cb, "no-node");
            return false;
        }

        $info = $this->registry->get($node);
        if ($info === null) {
            $this->missUnknown++;
            $this->degrade($fallback, $uid, $method, $cb, "node-unknown");
            return false;
        }

        $now = (int) (microtime(true) * 1000);
        if (!$info->isAlive($now, $this->ttlMs)) {
            // 已经过期但环还没来得及重建（refresh 最长 1 秒一次）
            $this->missStale++;
            $this->degrade($fallback, $uid, $method, $cb, "node-stale");
            return false;
        }

        if ($info->codec !== $this->codecName) {
            $this->missCodec++;
            $this->degrade($fallback, $uid, $method, $cb, "codec-mismatch:" . $info->codec . "!=" . $this->codecName);
            return false;
        }

        // 单目标在飞上限：**放在熔断检查之前**。
        //
        // 顺序是有讲究的：`allow()` 会占用 HALF_OPEN 的探针位（有副作用），
        // 如果先 allow 再因为饱和而拒绝，就得把这个位还回去（`release()`），
        // 白绕一圈。而饱和本来就意味着"还没发出去"，对"这个节点健不健康"
        // 没有任何信息量——不该碰熔断器的状态
        $cur = $this->inFlight[$info->nodeId] ?? 0;
        if ($cur >= $this->maxPerTarget) {
            $this->rejectedSaturated++;
            $this->degrade($fallback, $uid, $method, $cb, "target-saturated");
            return false;
        }

        // 熔断检查放在最后：前面几项都是"这个节点不存在/不该用"，只有这里
        // 是"它存在但最近不应答"。顺序反了也能跑，但统计会混（熔断计数里
        // 混进一堆 node-stale，看不出到底有几个节点是真坏的）
        if (!$this->breaker->allow($info->nodeId, Scheduler::nowMs())) {
            $this->fastOpen++;
            $this->degrade($fallback, $uid, $method, $cb, "circuit-open");
            return false;
        }

        if ($info->nodeId === $this->localNode) {
            $this->hitLocal++;
        } else {
            $this->hitRemote++;
        }

        // 统一地址：本机与远端的差别只在 deliverAs 内部的一个位运算里
        $addr = Addr::pack($info->nodeId, $info->entry);

        // 包一层回调记健康度。**只有"对端没应答"才记失败**——
        // 业务错误（角色不存在、参数非法）恰恰证明对端活着，
        // 记进去等于"玩家查了几个空角色，整台服被熔断"
        $nodeId = $info->nodeId;
        $this->inFlight[$nodeId] = $cur + 1;

        $self = $this;
        $wrapped = function (?string $resp, string $err) use ($cb, $nodeId, $self): void {
            // 先减在飞数：不管成功失败都要减，而且必须**在调用户回调之前**减——
            // 用户回调里往往会紧接着发下一个请求，晚减的话它会被自己卡住
            $now = $self->inFlight($nodeId);
            $self->decInFlight($nodeId, $now);

            if ($err === "") {
                $self->breaker()->ok($nodeId);
            } elseif (Rpc::blamesPeer($err)) {
                $self->breaker()->fail($nodeId, Scheduler::nowMs());
            } elseif (Rpc::isLocalError($err)) {
                // 我们自己的问题（待发表满/编不出来），探针没拿到结论就还回去，
                // 否则 HALF_OPEN 的探针位被占住，这个节点永远等不到下一次探活
                $self->breaker()->release($nodeId);
            } else {
                // 远端返回的业务错误：能回话就是健康的
                $self->breaker()->ok($nodeId);
            }
            $cb($resp, $err);
        };

        $this->client->call($addr, $method, $body, $wrapped, $timeoutMs);
        return true;
    }

    /** 目标不可用时走降级；没有降级就原样报错 */
    private function degrade(
        ?\Closure $fallback,
        int $uid,
        string $method,
        \Closure $cb,
        string $err
    ): void {
        if ($fallback === null) {
            $cb(null, $err);
            return;
        }
        $v = $fallback($uid, $method);
        if (!is_string($v)) {
            $cb(null, $err);
            return;
        }
        $this->degraded++;
        $cb($v, "");
    }

    /** 某个节点上有哪些 uid 归它管（均衡度分析/迁移估算用） */
    public function ownerOf(int $uid): int
    {
        return $this->nodeFor($uid);
    }

    public function stats(): array
    {
        return [
            "nodes" => count($this->ring->nodes()),
            "local" => $this->hitLocal,
            "remote" => $this->hitRemote,
            "no_node" => $this->missNoNode,
            "unknown" => $this->missUnknown,
            "stale" => $this->missStale,
            "codec" => $this->missCodec,
            "fast_open" => $this->fastOpen,
            "degraded" => $this->degraded,
            "saturated" => $this->rejectedSaturated,
            "open_nodes" => $this->breaker->openKeys(Scheduler::nowMs()),
            "breaker" => $this->breaker->stats(),
        ];
    }

    private function maybeRefresh(): void
    {
        $now = (int) (microtime(true) * 1000);
        if ($now - $this->lastRefreshMs < $this->refreshMs) {
            return;
        }
        $this->refresh();
    }
}
