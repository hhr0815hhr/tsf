<?php

namespace TSF\Metrics;

/**
 * 一个指标在某一刻的快照。
 *
 * 用**类**而不是嵌套数组，是为了 AOT：`array<string, mixed>` 在这个框架里
 * 是明令禁止的（lint 会拦），而且 `Kernel\Config` 记过一次教训——异构嵌套数组
 * 反复下标访问会触发类型校验异常。
 *
 * 字段按类型给全，不用"用不到的字段留空"那套：
 * 计数器也能有 count（等于它的值），直方图也有 value（等于均值），
 * 导出表格时不用按类型分支取值。
 */
/**
 * 指标类型。
 *
 * 用 enum 而不是三个字符串常量：以后加一种类型（比如 summary），
 * `match` 的穷尽性会让所有漏分支的地方**编译期**报错；
 * 字符串常量只会静默走到默认分支，等线上看板数字不对才发现。
 *
 * 注意：enum case 在 AOT 下是对象而非标量常量，所以这里换来的是类型安全，
 * **不是**比较速度——不要把它当性能优化。
 */
enum MetricKind
{
    case Counter;
    case Gauge;
    case Histogram;

    /** 导出用的短名（日志表格、Prometheus 的 type 字段） */
    public function label(): string
    {
        return match ($this) {
            self::Counter => "counter",
            self::Gauge => "gauge",
            self::Histogram => "histogram",
        };
    }
}

final class MetricSnapshot
{
    public MetricKind $kind = MetricKind::Counter;

    public string $name = "";

    /** 样本数（counter/gauge 恒为 1） */
    public int $count = 0;

    /** 计数器的值 / gauge 的当前值 / 直方图的平均值 */
    public float $value = 0.0;

    /** 直方图：总和（µs） */
    public float $sum = 0.0;

    /** 直方图：最大（µs） */
    public float $max = 0.0;

    public float $p50 = 0.0;

    public float $p90 = 0.0;

    public float $p99 = 0.0;

    /** 慢调用被日志限流压掉的条数 */
    public int $suppressed = 0;

    /** 慢调用阈值（µs），0 表示没设 */
    public float $slowUs = 0.0;
}
