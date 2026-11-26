<?php

namespace TSF\Metrics;

/**
 * 把 `Metrics` 里的计数导成 Prometheus 文本格式（version=0.0.4）。
 *
 * ## 为什么不是"再写一套指标上报"
 *
 * `Metrics` 已经在热路径上记了计数/仪表/直方图（`inc/gauge/observe`），
 * 缺的只是**一个能被抓取的出口**。日志表格（`Metrics::table()`）适合人看，
 * 不适合机器聚合：grep 出来的数字没法做速率、分位数、告警。
 *
 * 所以这里只做"翻译"：读 `snapshot()`，按 exposition format 渲染成字符串。
 * 不新增状态、不改热路径、不依赖任何网络库——导出失败最多是这次抓不到，
 * 不会影响业务。
 *
 * ## 直方图为什么渲染成 summary
 *
 * Prometheus 的 histogram 需要**桶边界**（`le="0.1"`），而我们的 `Histogram`
 * 只保留分位数和均值（为了内存可控，桶要预定义边界，业务侧定不准）。
 * 强行造桶等于编数据。
 *
 * `summary` 类型恰好接受分位数（`quantile="0.5"`），语义对得上：
 * "这段时间里 90% 的请求快于 X"。
 *
 * 代价要说清：**summary 的分数位不能跨实例聚合**（各自算各自的）。
 * 多实例想看全局 p99，只能用 `_sum / _count` 算均值，或者以后真的做桶。
 *
 * ## 单位是微秒
 *
 * `observe()` 收的是微秒（和日志表格一致）。Prometheus 生态惯例是秒，
 * 但改名会让日志和指标对不上，排障时更痛。所以保留微秒，在 HELP 里写明单位。
 */
final class Prometheus
{
    /** Prometheus 抓取端认这个 Content-Type；版本号别丢，丢了会按最老的解析 */
    public const CONTENT_TYPE = "text/plain; version=0.0.4; charset=utf-8";

    /**
     * 渲染成 exposition 文本。
     *
     * @param bool   $withProcess 是否附带进程内存指标（和业务指标分开放，便于按前缀过滤）
     * @param string $prefix      业务指标名前缀。默认不加：加了会和日志里的名字对不上
     * @param bool   $withRuntime 是否附带框架运行时指标（`Metrics::provide()` 登记的那些）。
     *                           名字固定带 `tsf_` 前缀，不受 `$prefix` 影响
     */
    public static function render(bool $withProcess = true, string $prefix = "", bool $withRuntime = true): string
    {
        $out = "";
        $declared = [];

        foreach (Metrics::snapshot() as $s) {
            $name = self::sanitize($prefix . $s->name);
            if ($name === "") {
                continue;
            }
            // 同名指标只写一次 HELP/TYPE：重复声明会被抓取端当成格式错误整批丢弃
            if (!isset($declared[$name])) {
                $out .= "# HELP " . $name . " " . self::helpText($s) . "\n";
                $out .= "# TYPE " . $name . " " . self::typeOf($s->kind) . "\n";
                $declared[$name] = true;
            }
            $out .= self::renderOne($name, $s);
        }

        if ($withRuntime) {
            // 变量名不能和上面业务指标的循环重名：AOT 下 $name 已经是 php::Str，
            // 从另一个数组里取键赋给它会被判成类型冲突
            $runtime = Metrics::runtimeSnapshot();
            foreach ($runtime as $rname => $rv) {
                $out .= "# HELP " . $rname . " tsf runtime " . $rname . "\n";
                $out .= "# TYPE " . $rname . (Metrics::isRuntimeCounter($rname) ? " counter" : " gauge") . "\n";
                $out .= $rname . " " . self::num((float) $rv) . "\n";
            }
        }

        if ($withProcess) {
            $out .= "# HELP tsf_mem_bytes 当前进程已分配内存（PHP 堆 + 扩展）\n";
            $out .= "# TYPE tsf_mem_bytes gauge\n";
            $out .= "tsf_mem_bytes " . memory_get_usage(true) . "\n";
            $out .= "# HELP tsf_mem_peak_bytes 进程内存峰值\n";
            $out .= "# TYPE tsf_mem_peak_bytes gauge\n";
            $out .= "tsf_mem_peak_bytes " . memory_get_peak_usage(true) . "\n";
        }
        return $out;
    }

    private static function renderOne(string $name, MetricSnapshot $s): string
    {
        if ($s->kind === MetricKind::Histogram) {
            // summary：分位数 + 总和 + 次数，都带 quantile 标签或后缀
            $out = "";
            $out .= self::sample($name, "0.5", $s->p50);
            $out .= self::sample($name, "0.9", $s->p90);
            $out .= self::sample($name, "0.99", $s->p99);
            $out .= $name . "_sum " . self::num($s->sum) . "\n";
            $out .= $name . "_count " . $s->count . "\n";
            return $out;
        }
        if ($s->kind === MetricKind::Gauge) {
            return $name . " " . self::num($s->value) . "\n";
        }
        // 计数器的值在 `value` 里，`count` 对计数器恒为 1（它表示"样本条数"，
        // 只对直方图有意义）。踩过一次：用 count 渲染出来所有计数器都是 1
        return $name . " " . self::num($s->value) . "\n";
    }

    private static function sample(string $name, string $q, float $v): string
    {
        return $name . "{quantile=\"" . $q . "\"} " . self::num($v) . "\n";
    }

    private static function typeOf(MetricKind $k): string
    {
        if ($k === MetricKind::Histogram) {
            return "summary";
        }
        return $k === MetricKind::Gauge ? "gauge" : "counter";
    }

    private static function helpText(MetricSnapshot $s): string
    {
        $unit = $s->kind === MetricKind::Histogram ? "（单位：微秒）" : "";
        // HELP 里不能有换行和反斜杠，否则整行会被截断
        $t = "tsf metric " . $s->name . $unit;
        // HELP 是 UTF-8 文本，Prometheus 接受；但换行会被当成新样本行，必须清掉
        return str_replace(["\\", "\n", "\r"], ["\\\\", " ", " "], $t);
    }

    /**
     * 指标名只允许 `[a-zA-Z0-9_:]`。
     *
     * 我们的名字习惯用点分层（`room.settle.us`），直接输出会被抓取端拒绝，
     * 而且拒绝方式是**整批 400**，不是跳过这一条——一个人写错名字，
     * 整个进程的指标全没了。
     */
    public static function sanitize(string $name): string
    {
        $t = trim($name);
        if ($t === "") {
            return "";
        }
        $out = "";
        $len = strlen($t);
        for ($i = 0; $i < $len; $i++) {
            $c = $t[$i];
            $isDigit = $c >= "0" && $c <= "9";
            $isAlpha = ($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z");
            if ($isAlpha || $c === "_" || $c === ":") {
                $out .= $c;
                continue;
            }
            if ($isDigit) {
                // 首字符不能是数字，否则名字非法
                $out .= $out === "" ? "_" . $c : $c;
                continue;
            }
            $out .= "_";
        }
        return $out;
    }

    /** 数值格式化：非有限值（NaN/INF）Prometheus 不认，按 0 输出并保底不抛 */
    private static function num(float $v): string
    {
        if (!is_finite($v)) {
            return "0";
        }
        if ($v == (int) $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(number_format($v, 6, ".", ""), "0"), ".");
    }
}
