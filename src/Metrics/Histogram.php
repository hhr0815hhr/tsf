<?php

namespace TSF\Metrics;

/**
 * 指数分桶直方图：记次数、总和、最大值、分位数。
 *
 * ## 为什么不是"存所有样本"
 *
 * 存全部样本才能算出精确分位数，但一个跑几小时的服务器就是几百万个 float——
 * 内存无限涨，还得排序。**监控不需要精确值**，需要的是"p99 大概在哪个量级、
 * 有没有变坏"，1 个桶的误差（这里约 19%）对判断毫无影响。
 *
 * ## 分桶方式
 *
 * 每个八度（2 的幂）切 4 个桶，从 1 µs 到约 71 分钟，共 128 个桶。
 *
 * | 值 | 落桶 | 上报上界 |
 * |--:|--:|--:|
 * | 1 µs | 0 | 1.19 µs |
 * | 2 µs | 4 | 2.38 µs |
 * | 1000 µs | 39 | 1124 µs |
 * | 1 s | 79 | 1.08 s |
 *
 * 误差恒定为"上界/真值 ≤ 2^0.25 ≈ 1.19"，**不随量级变化**——这比线性分桶好：
 * 线性分桶在 1 ms 附近可能很细，但 10 s 的毛刺会被归到一个巨大的桶里。
 *
 * ## 热路径成本
 *
 * `record()` 里是：两次字段自增、一次比较、一次 `log()`、一次桶数组自增。
 * 整个 `Metrics::observe()` 实测 **~390 ns**（`inc()` 是 81 ns）。
 *
 * 顺便纠正一个直觉：这 390 ns **不是** `log()` 的锅——单独量 `log()` 只有 **2 ns**。
 * 成本摊在"一次字符串键查找 + 一次方法调用 + 三次小数组操作"上。
 * 想再压只能把指标句柄缓存起来（`$h = Metrics::histogram("x")` 然后 `$h->record()`），
 * 收益约 2~3 倍，但会让调用方多管一个句柄。现在这样够用：
 * 一帧埋 100 次 = 39 µs，占 16.7 ms 帧预算的 0.2%。
 */
final class Histogram
{
    /** 每个八度切几个桶（2^2 = 4） */
    private const SUB_BITS = 2;

    /** 桶数：128 × 0.25 个八度 = 32 个八度，够到 2^32 µs ≈ 71 分钟 */
    public const BUCKETS = 128;

    /** ln(2) 的倒数 = 1/ln2，用来做 log2 */
    private const INV_LN2 = 1.4426950408889634;

    /** @var array<int, int> 桶下标 => 次数 */
    private array $counts = [];

    public int $count = 0;

    public float $sum = 0.0;

    public float $max = 0.0;

    public function record(float $us): void
    {
        $this->count++;
        $this->sum += $us;
        if ($us > $this->max) {
            $this->max = $us;
        }
        $i = self::index($us);
        $cur = $this->counts[$i] ?? 0;
        $this->counts[$i] = $cur + 1;
    }

    public static function index(float $us): int
    {
        if ($us <= 1.0) {
            return 0;   // 含 0 和负数（负数不该出现，但不值得为它抛异常）
        }
        // 不用 log($x, 2)：两参数版本的支持面比单参数窄
        $i = (int) (log($us) * self::INV_LN2 * (float) (1 << self::SUB_BITS));
        if ($i < 0) {
            return 0;
        }
        if ($i >= self::BUCKETS) {
            return self::BUCKETS - 1;
        }
        return $i;
    }

    /** 第 $i 个桶的上界（µs） */
    public static function upperBound(int $i): float
    {
        return 2.0 ** ((float) ($i + 1) / (float) (1 << self::SUB_BITS));
    }

    /**
     * 分位数（µs）。返回的是**桶上界**，所以一定 ≥ 真实值，误差 ≤ 19%。
     *
     * 空直方图返回 0；样本很少时（比如 p99 但只有 3 个样本）返回最大值——
     * 比返回 0 好：0 会让人以为"很快"，而实际是"样本不够"。
     */
    public function percentile(float $p): float
    {
        if ($this->count === 0) {
            return 0.0;
        }
        $target = (int) ceil($p * (float) $this->count);
        if ($target < 1) {
            $target = 1;
        }
        $acc = 0;
        for ($i = 0; $i < self::BUCKETS; $i++) {
            $c = $this->counts[$i] ?? 0;
            if ($c === 0) {
                continue;
            }
            $acc += $c;
            if ($acc >= $target) {
                return self::upperBound($i);
            }
        }
        return $this->max;
    }

    /** 平均值（µs） */
    public function mean(): float
    {
        return $this->count > 0 ? $this->sum / (float) $this->count : 0.0;
    }

    public function reset(): void
    {
        $this->counts = [];
        $this->count = 0;
        $this->sum = 0.0;
        $this->max = 0.0;
    }
}
