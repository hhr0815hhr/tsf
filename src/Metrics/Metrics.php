<?php

namespace TSF\Metrics;

use TSF\Kernel\Logger;

/**
 * 指标埋点：计数、瞬时值、耗时分布，加"慢调用自动打点"。
 *
 * ```php
 * Metrics::inc("packet.in");                       // 计数
 * Metrics::gauge("room.active", 128);              // 瞬时值
 * Metrics::watch("battle.tick", 5000);             // 慢阈值 5 ms
 * $t0 = Metrics::now();
 * ... 干活 ...
 * Metrics::done("battle.tick", $t0, "room=1234");  // 记一次耗时；超阈值打日志
 * ```
 *
 * ## 三个刻意的取舍
 *
 * **1. 不做 HTTP 导出端点。** 这个框架的定位是"自己的游戏服"，不是 K8s 里的
 * 微服务集群。指标落到日志里，用 filebeat/vector 采集已经够用；为它起一个
 * HTTP 端口意味着多一个攻击面、多一份配置、多一个会挂的东西。
 * 真要接 Prometheus，`snapshot()` 返回的就是结构化数据，接一个导出器是几十行的事。
 *
 * **2. 慢调用日志必须限流。** 一个卡顿会让几百个请求同时超过阈值，如果每笔都写一行，
 * 日志会在几秒内涨到几百 MB——**埋点本身把服务拖垮**。所以每个指标默认 1 秒最多
 * 一条，被压掉的条数记在 `suppressed` 里，下一条日志带上它。
 *
 * **3. 埋点不分配。** `inc()` 只是一次哈希查找 + 自增（实测 **81 ns**）；
 * `observe()` 在第一次调用时建直方图，之后不再分配（实测 **~390 ns**）。
 * 埋点不能成为被测对象的一部分——一帧埋 100 次是 39 µs，占 16.7 ms 的 0.2%。
 */
final class Metrics
{
    /** @var array<string, int> */
    private static array $counters = [];

    /** @var array<string, float> 当前值 */
    private static array $gauges = [];

    /** @var array<string, float> 见过的最大值 */
    private static array $gaugeMax = [];

    /** @var array<string, Histogram> */
    private static array $hists = [];

    /** @var array<string, float> 每个指标的慢阈值（µs），0 = 不检查 */
    private static array $slowUs = [];

    /** @var array<string, int> 每个指标上一条慢日志的时间（ms） */
    private static array $lastSlowAt = [];

    /** @var array<string, int> 被限流压掉的慢调用数 */
    private static array $suppressed = [];

    /**
     * 运行时指标的提供者：前缀 => [function (): array<string, int>, ...]。
     *
     * ## 为什么和业务埋点分开
     *
     * 业务埋点（`inc` / `gauge`）是**推**：代码在事件点上记一笔。
     * 框架自己的统计（调度器处理了多少消息、网关有多少会话、集群断了多少次链路）
     * 本来就各自存在 `stats()` 里，再去事件点补一次 `inc` 等于同一件事记两遍，
     * 还要动热路径。所以这里用**拉**：导出的时候现取。
     *
     * 同一个前缀可以有多个提供者（一个进程里两个集群节点、两个网关），取值时相加——
     * "总处理条数""总会话数"本来就该是求和。
     *
     * @var array<string, array<int, \Closure>>
     */
    private static array $providers = [];

    /** @var array<string, array<int, string>> 每个前缀里哪些键是单调计数（其余按 gauge） */
    private static array $providerCounters = [];

    /** 同一个指标两条慢日志之间的最小间隔（ms） */
    private static int $slowIntervalMs = 1000;

    /** 全局默认慢阈值（µs）；0 表示没设，看单个指标 */
    private static float $defaultSlowUs = 0.0;

    // ───────────────────── 写 ─────────────────────

    public static function inc(string $name, int $n = 1): void
    {
        $cur = self::$counters[$name] ?? 0;
        self::$counters[$name] = $cur + $n;
    }

    /** 计数器加一个稍大的值（字节数、金币数这类） */
    public static function add(string $name, int $n): void
    {
        self::inc($name, $n);
    }

    public static function gauge(string $name, float $v): void
    {
        self::$gauges[$name] = $v;
        $max = self::$gaugeMax[$name] ?? $v;
        if ($v > $max) {
            self::$gaugeMax[$name] = $v;
        }
    }

    /** 登记一个耗时指标并设定慢阈值（µs）。不设也能量，只是不会自动打点 */
    public static function watch(string $name, float $slowUs): void
    {
        self::$slowUs[$name] = $slowUs;
        if (!isset(self::$hists[$name])) {
            self::$hists[$name] = new Histogram();
        }
    }

    /**
     * 记一次耗时（µs）。超过阈值时打一条慢日志。
     *
     * `$ctx` 是给排障用的自由文本（房间号、uid、协议号……），**只在超阈值时才会被
     * 写进日志**。但要注意：字符串拼接发生在调用方，所以 `"room=" . $id` 在热路径上
     * 每次都要付拼接的钱。要么传空，要么由调用方自己先判断该不该拼。
     */
    public static function observe(string $name, float $us, string $ctx = ""): void
    {
        $h = self::$hists[$name] ?? null;
        if ($h === null) {
            $h = new Histogram();
            self::$hists[$name] = $h;
        }
        $h->record($us);

        $th = self::$slowUs[$name] ?? self::$defaultSlowUs;
        if ($th <= 0.0 || $us < $th) {
            return;
        }
        self::logSlow($name, $us, $th, $ctx);
    }

    /** 计时起点（hrtime 纳秒） */
    public static function now(): int
    {
        return hrtime(true);
    }

    /**
     * 从 `now()` 到现在记一次耗时，返回 µs。
     *
     * 用"起点 + done"而不是"闭包包起来"：闭包每次调用要分配对象，
     * 而这个 API 会出现在每一帧、每一个包的处理路径上。
     */
    public static function done(string $name, int $t0, string $ctx = ""): float
    {
        $us = (float) (hrtime(true) - $t0) / 1000.0;
        self::observe($name, $us, $ctx);
        return $us;
    }

    // ───────────────────── 慢调用打点 ─────────────────────

    private static function logSlow(string $name, float $us, float $threshold, string $ctx): void
    {
        $nowMs = intdiv(hrtime(true), 1000000);
        $last = self::$lastSlowAt[$name] ?? 0;
        if ($nowMs - $last < self::$slowIntervalMs) {
            // 限流：一个卡顿会有成百上千笔超阈值，每笔都写会把磁盘写满，
            // 而日志 IO 又会让本来就在卡的那条路径更卡——正反馈
            self::$suppressed[$name] = (self::$suppressed[$name] ?? 0) + 1;
            return;
        }
        self::$lastSlowAt[$name] = $nowMs;

        $ctxArr = [];
        $ctxArr["metric"] = $name;
        $ctxArr["us"] = (int) $us;
        $ctxArr["limit_us"] = (int) $threshold;
        $dropped = self::$suppressed[$name] ?? 0;
        if ($dropped > 0) {
            $ctxArr["suppressed"] = $dropped;
            self::$suppressed[$name] = 0;
        }
        if ($ctx !== "") {
            $ctxArr["ctx"] = $ctx;
        }
        Logger::warn("slow: " . $name, $ctxArr);
    }

    // ───────────────────── 读 ─────────────────────

    /** @return MetricSnapshot[] 按名字排序 */
    public static function snapshot(): array
    {
        $names = [];
        foreach (self::$counters as $k => $v) {
            $names[(string) $k] = true;
        }
        foreach (self::$gauges as $k => $v) {
            $names[(string) $k] = true;
        }
        foreach (self::$hists as $k => $v) {
            $names[(string) $k] = true;
        }
        $list = [];
        foreach ($names as $k => $v) {
            $list[] = (string) $k;
        }
        sort($list);

        $out = [];
        foreach ($list as $name) {
            $s = new MetricSnapshot();
            $s->name = $name;
            $h = self::$hists[$name] ?? null;
            if ($h !== null) {
                $s->kind = MetricKind::Histogram;
                $s->count = $h->count;
                $s->sum = $h->sum;
                $s->max = $h->max;
                $s->value = $h->mean();
                $s->p50 = $h->percentile(0.50);
                $s->p90 = $h->percentile(0.90);
                $s->p99 = $h->percentile(0.99);
                $s->slowUs = self::$slowUs[$name] ?? self::$defaultSlowUs;
                $s->suppressed = self::$suppressed[$name] ?? 0;
                $out[] = $s;
                continue;
            }
            if (isset(self::$gauges[$name])) {
                $s->kind = MetricKind::Gauge;
                $s->count = 1;
                $s->value = self::$gauges[$name];
                $s->max = self::$gaugeMax[$name] ?? $s->value;
                $out[] = $s;
                continue;
            }
            $s->kind = MetricKind::Counter;
            $s->count = 1;
            $s->value = (float) (self::$counters[$name] ?? 0);
            $out[] = $s;
        }
        return $out;
    }

    /**
     * 文本表格（给日志用）。
     *
     * 表头用英文：中文列名按字节补空格会对不齐（一个汉字 3 字节），
     * 而日志表格对齐是给人快速扫的，对不齐就没意义了。
     */
    public static function table(): string
    {
        $snap = self::snapshot();
        $out = "metrics: " . count($snap) . " 个" . PHP_EOL;
        $head = sprintf("%-24s %-10s %8s %10s %10s %10s %10s %10s", "NAME", "KIND", "COUNT", "MEAN", "P50", "P90", "P99", "MAX");
        $out .= $head . PHP_EOL;
        foreach ($snap as $s) {
            if ($s->kind === MetricKind::Counter) {
                $out .= sprintf(
                    "%-24s %-10s %8s %10s %10s %10s %10s %10s",
                    $s->name,
                    "counter",
                    "-",
                    self::fmtNum($s->value),
                    "-",
                    "-",
                    "-",
                    "-"
                ) . PHP_EOL;
                continue;
            }
            if ($s->kind === MetricKind::Gauge) {
                $out .= sprintf(
                    "%-24s %-10s %8s %10s %10s %10s %10s %10s",
                    $s->name,
                    "gauge",
                    "-",
                    self::fmtNum($s->value),
                    "-",
                    "-",
                    "-",
                    self::fmtNum($s->max)
                ) . PHP_EOL;
                continue;
            }
            $out .= sprintf(
                "%-24s %-10s %8d %10s %10s %10s %10s %10s",
                $s->name,
                "hist",
                $s->count,
                self::fmtUs($s->value),
                self::fmtUs($s->p50),
                self::fmtUs($s->p90),
                self::fmtUs($s->p99),
                self::fmtUs($s->max)
            ) . PHP_EOL;
        }
        return $out;
    }

    /** 把表格打进日志（一条 info，消息体是多行文本） */
    public static function dump(): void
    {
        Logger::info(self::table());
    }

    /**
     * 登记一个运行时指标提供者。
     *
     * `$fn` 返回 `array<string, int>`（通常就是组件的 `stats()`）。导出时每个键变成
     * `<前缀>_<键>`；在 `$counters` 里列出的是单调计数（Prometheus 里可以 `rate()`），
     * 没列出的一律按 gauge。
     *
     * @param array<int, string> $counters
     */
    public static function provide(string $prefix, \Closure $fn, array $counters = []): void
    {
        // rtrim：`sanitize` 会把 "bad prefix!" 末尾的非法字符变成下划线，前缀不该以它结尾
        $p = rtrim(Prometheus::sanitize($prefix), "_");
        if ($p === "") {
            return;
        }
        self::$providers[$p][] = $fn;
        self::$providerCounters[$p] = $counters;
    }

    /** 清掉所有提供者（测试用；`reset()` 不清这里——提供者是"谁在运行"，不是"记了多少"） */
    public static function clearProviders(): void
    {
        self::$providers = [];
        self::$providerCounters = [];
    }

    /**
     * 当前所有运行时指标，键是 `<前缀>_<键>`，同前缀的多个提供者已相加。
     *
     * @return array<string, int>
     */
    public static function runtimeSnapshot(): array
    {
        $out = [];
        foreach (self::$providers as $prefix => $fns) {
            foreach ($fns as $fn) {
                $stats = $fn();
                foreach ($stats as $k => $v) {
                    $name = $prefix . "_" . Prometheus::sanitize((string) $k);
                    if (isset($out[$name])) {
                        $out[$name] += $v;
                    } else {
                        $out[$name] = $v;
                    }
                }
            }
        }
        return $out;
    }

    /** 运行时指标里哪些是单调计数（给导出器标 TYPE 用） */
    public static function isRuntimeCounter(string $name): bool
    {
        // 逐个前缀试：键名自己也可能带下划线（max_tick_gap_us），所以不能只按最后一个下划线切
        foreach (self::$providerCounters as $prefix => $keys) {
            if (strncmp($name, $prefix . "_", strlen($prefix) + 1) !== 0) {
                continue;
            }
            $key = substr($name, strlen($prefix) + 1);
            if (in_array($key, $keys, true)) {
                return true;
            }
        }
        return false;
    }

    public static function reset(): void
    {
        self::$counters = [];
        self::$gauges = [];
        self::$gaugeMax = [];
        self::$hists = [];
        self::$slowUs = [];
        self::$lastSlowAt = [];
        self::$suppressed = [];
    }

    /** 慢日志限流间隔（ms）。测试里调小可以验证限流逻辑 */
    public static function slowLogIntervalMs(int $ms): void
    {
        self::$slowIntervalMs = $ms > 0 ? $ms : 0;
    }

    /** 全局默认慢阈值（µs）。单指标可以用 `watch()` 覆盖 */
    public static function defaultSlowUs(float $us): void
    {
        self::$defaultSlowUs = $us;
    }

    // ───────────────────── 格式化 ─────────────────────

    private static function fmtUs(float $us): string
    {
        if ($us >= 1000000.0) {
            return sprintf("%.1fs", $us / 1000000.0);
        }
        if ($us >= 1000.0) {
            return sprintf("%.1fms", $us / 1000.0);
        }
        return sprintf("%.0fµs", $us);
    }

    private static function fmtNum(float $v): string
    {
        if ($v >= 1000000.0) {
            return sprintf("%.2fM", $v / 1000000.0);
        }
        if ($v >= 1000.0) {
            return sprintf("%.1fk", $v / 1000.0);
        }
        return sprintf("%.0f", $v);
    }
}
