<?php

namespace TSF\Breaker;

/**
 * 熔断器：按"目标"统计连续失败，坏掉的先短路掉，过一会儿放一个探针去试。
 *
 * ```php
 * if (!$breaker->allow($nodeId, Scheduler::nowMs())) {
 *     // 直接失败，不发出请求
 * }
 * // ... 拿到结果后
 * $breaker->ok($nodeId);                   // 或者
 * $breaker->fail($nodeId, Scheduler::nowMs());
 * ```
 *
 * ## 三态
 *
 * ```
 *           连续失败 N 次
 *   CLOSED ──────────────▶ OPEN
 *      ▲                     │
 *      │  探针成功            │ 冷却结束，放一个探针
 *      └──── HALF_OPEN ◀─────┘
 *                │
 *                └──探针失败──▶ OPEN（冷却翻倍）
 * ```
 *
 * `CLOSED` 正常放行；`OPEN` 一律拒绝；`HALF_OPEN` **只放一个**请求，
 * 其余继续拒绝——放一批进去的话，节点恢复不了就会被一起打死，
 * 那等于把"探活"变成了"再压一次"。
 *
 * ## 它解决的是服务发现解决不了的那一半
 *
 * 服务发现靠心跳判活，但**进程活着不等于能干活的**：
 *
 * | 情况 | 心跳 | 能服务吗 |
 * |:--|:--|:--|
 * | 进程被杀 | 停 | 不能 |
 * | GC 卡死 / 死循环 / 磁盘 IO 阻塞 | **继续跳** | 不能 |
 * | socket 没 listen 上（端口/路径冲突） | **继续跳** | 不能 |
 * | 依赖的下游挂了，自己一直超时 | **继续跳** | 不能 |
 *
 * 后三种场景注册表会一直说"它还活着"，所有请求照发不误，然后集体超时。
 * 熔断器是**唯一**能发现它们的机制——它看的是"实际有没有应答"，不是"有没有说话"。
 *
 * 所以这两层不是替代关系，是两层过滤：注册表挡"已经不在了的"，
 * 熔断器挡"还在但不干活的"。
 *
 * ## 冷却为什么要翻倍
 *
 * 第一次 2 秒就试一次，是想让偶发抖动（一次网络丢包、一次长 GC）**尽快恢复**——
 * 这时候不该惩罚它 30 秒。
 *
 * 但如果探针也失败了，说明不是抖动。冷却翻倍（2s → 4s → 8s → … → 30s 封顶），
 * 避免对一个真的死掉的节点每 2 秒发一次请求——那会让每次恢复尝试都卡一次超时，
 * 恢复得越慢、惩罚越久，正好反了。
 */
final class CircuitBreaker
{
    public const CLOSED = 0;

    public const OPEN = 1;

    public const HALF_OPEN = 2;

    /** 连续失败多少次判定坏掉 */
    private int $threshold;

    /** 第一次熔断后多久放探针 */
    private int $baseCooldownMs;

    /** 冷却上限（连续探针失败会一直翻倍，必须封顶） */
    private int $maxCooldownMs;

    /**
     * @var array<int, array{state: int, fails: int, openedAt: int, cool: int, probing: bool}>
     *
     * 用节点号（小整数）当 key。**不要用 hash 之类的大整数当 key**——
     * AOT 下取回来可能对不上（见 `Shard\Ring` 的注释）。
     */
    private array $slots = [];

    private int $trips = 0;

    private int $probes = 0;

    private int $shortCircuited = 0;

    public function __construct(int $threshold = 3, int $baseCooldownMs = 2000, int $maxCooldownMs = 30000)
    {
        $this->threshold = $threshold >= 1 ? $threshold : 1;
        $this->baseCooldownMs = $baseCooldownMs > 0 ? $baseCooldownMs : 2000;
        $this->maxCooldownMs = $maxCooldownMs >= $this->baseCooldownMs ? $maxCooldownMs : $this->baseCooldownMs;
    }

    /**
     * 现在能不能往这个目标发请求。
     *
     * **这个方法有副作用**：冷却结束时它会顺手把状态推进到 `HALF_OPEN`
     * 并占住探针位。所以不能拿它当只读的 `state()` 用。
     */
    public function allow(int $key, int $nowMs): bool
    {
        $s = $this->slots[$key] ?? null;
        if (!is_array($s)) {
            return true;   // 没见过的目标：先信任
        }

        $state = (int) $s["state"];

        if ($state === self::CLOSED) {
            return true;
        }

        if ($state === self::OPEN) {
            if ($nowMs - (int) $s["openedAt"] < (int) $s["cool"]) {
                $this->shortCircuited++;
                return false;
            }
            // 冷却结束：放一个探针出去
            $this->slots[$key]["state"] = self::HALF_OPEN;
            $this->slots[$key]["probing"] = true;
            $this->probes++;
            return true;
        }

        // HALF_OPEN：已经有探针在飞，其余一律拒绝
        if ((bool) $s["probing"]) {
            $this->shortCircuited++;
            return false;
        }
        $this->slots[$key]["probing"] = true;
        $this->probes++;
        return true;
    }

    /** 记一次成功：直接回到 CLOSED，冷却也复位（下次抖动还是从 2 秒开始试） */
    public function ok(int $key): void
    {
        $s = $this->slots[$key] ?? null;
        if (!is_array($s)) {
            return;
        }
        if ((int) $s["state"] === self::CLOSED && (int) $s["fails"] === 0) {
            return;   // 已经是健康态，别每来一次成功就重写一遍
        }
        $this->slots[$key] = [
            "state" => self::CLOSED,
            "fails" => 0,
            "openedAt" => 0,
            "cool" => $this->baseCooldownMs,
            "probing" => false,
        ];
    }

    /** 记一次失败（只记"对端没应答"，业务错误不该记进来——见 `Rpc::blamesPeer`） */
    public function fail(int $key, int $nowMs): void
    {
        $s = $this->slots[$key] ?? null;
        if (!is_array($s)) {
            $s = [
                "state" => self::CLOSED,
                "fails" => 0,
                "openedAt" => 0,
                "cool" => $this->baseCooldownMs,
                "probing" => false,
            ];
        }

        $state = (int) $s["state"];

        // 已经熔断，说明这次失败是熔断前就发出去的请求陆续回来的，重复计数没有意义。
        // 更重要的是：不刷新 openedAt，否则在飞请求越多、冷却被推得越远，
        // 一个本来就该恢复的节点会永远醒不过来
        if ($state === self::OPEN) {
            return;
        }

        if ($state === self::HALF_OPEN) {
            $cool = (int) $s["cool"] * 2;
            if ($cool > $this->maxCooldownMs) {
                $cool = $this->maxCooldownMs;
            }
            $this->slots[$key] = [
                "state" => self::OPEN,
                "fails" => (int) $s["fails"] + 1,
                "openedAt" => $nowMs,
                "cool" => $cool,
                "probing" => false,
            ];
            $this->trips++;
            return;
        }

        $fails = (int) $s["fails"] + 1;
        if ($fails >= $this->threshold) {
            $this->slots[$key] = [
                "state" => self::OPEN,
                "fails" => $fails,
                "openedAt" => $nowMs,
                "cool" => $this->baseCooldownMs,
                "probing" => false,
            ];
            $this->trips++;
            return;
        }
        $s["fails"] = $fails;
        $this->slots[$key] = $s;
    }

    /**
     * 探针没拿到结论时把探针位还回去。
     *
     * 什么情况会"没结论"：请求根本没发出去（我方待发表满、codec 编不出来）。
     * 这既不能算对端健康，也不该算它坏——但**必须把探针位放开**，
     * 否则 `HALF_OPEN` 的 `probing` 永远是 true，这个节点再也等不到下一次探活。
     *
     * 状态保持 `HALF_OPEN` 不动，下一个请求会立刻拿到探针（不需要再等冷却）——
     * 冷却的意思是"给对端休息时间"，而这次我们压根没打扰它。
     */
    public function release(int $key): void
    {
        $s = $this->slots[$key] ?? null;
        if (!is_array($s)) {
            return;
        }
        if ((int) $s["state"] === self::HALF_OPEN) {
            $this->slots[$key]["probing"] = false;
        }
    }

    /**
     * 只读地报告当前状态。
     *
     * 冷却已过但还没人来问的时候，报告 `HALF_OPEN`（"该试了"）而不是 `OPEN`，
     * 否则监控面板会显示"还在熔断"，实际下一个请求就会被放进去。
     */
    public function state(int $key, int $nowMs): int
    {
        $s = $this->slots[$key] ?? null;
        if (!is_array($s)) {
            return self::CLOSED;
        }
        $state = (int) $s["state"];
        if ($state === self::OPEN && $nowMs - (int) $s["openedAt"] >= (int) $s["cool"]) {
            return self::HALF_OPEN;
        }
        return $state;
    }

    /** 当前不该发请求的目标（监控/路由过滤用） */
    public function openKeys(int $nowMs): array
    {
        $out = [];
        foreach ($this->slots as $k => $s) {
            if ($this->state((int) $k, $nowMs) !== self::CLOSED) {
                $out[] = (int) $k;
            }
        }
        return $out;
    }

    /** 清掉某个目标的记忆（节点彻底下线时调；不自动清理由 `Shard\Router` 决定） */
    public function forget(int $key): void
    {
        unset($this->slots[$key]);
    }

    public function fails(int $key): int
    {
        $s = $this->slots[$key] ?? null;
        return is_array($s) ? (int) $s["fails"] : 0;
    }

    /** 当前冷却时长（诊断用：能看出这个节点是抖动还是真的坏了） */
    public function cooldown(int $key): int
    {
        $s = $this->slots[$key] ?? null;
        return is_array($s) ? (int) $s["cool"] : 0;
    }

    public function stats(): array
    {
        return [
            "tracked" => count($this->slots),
            "trips" => $this->trips,
            "probes" => $this->probes,
            "short_circuited" => $this->shortCircuited,
        ];
    }
}
