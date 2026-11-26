<?php

namespace TSF\Kernel;

/**
 * 结构化日志（JSON Lines）。
 *
 * 设计取舍：
 *  - 一行一条 JSON，方便 filebeat/vector 直接采集，不需要正则解析；
 *  - 级别阈值可配，error 额外写 stderr，便于 journald/容器日志第一时间抓到；
 *  - 不做异步落盘（M0 阶段）：写文件在 page cache 里，成本可忽略；后续接 ring buffer。
 *  - 按大小轮转（`log.max_bytes` / `log.max_files`，见 LogFile）：没有上限的日志
 *    迟早写满磁盘，之后所有日志静默消失。
 *  - 支持具名通道写到单独的文件（`Logger::to("gm", ...)`）：审计日志和运行日志混在一起，
 *    事后按操作人 grep 一条记录要在几百万行心跳里找。
 */
final class Logger
{
    public const DEBUG = 0;
    public const INFO = 1;
    public const WARN = 2;
    public const ERROR = 3;

    private static int $level = 1;

    private static string $file = "";

    private static string $channel = "app";

    private static bool $toStdout = false;

    /** 默认通道的落盘目标；init() 之后一定有值 */
    private static LogFile $sink;

    /** @var array<string, LogFile> 具名通道 => 落盘目标 */
    private static array $sinks = [];

    /** @var array<string, int> 具名通道的级别阈值 */
    private static array $sinkLevels = [];

    public static function init(string $channel, string $file = "", int $level = self::INFO, bool $toStdout = false): void
    {
        self::$channel = $channel;
        self::$file = $file;
        self::$level = $level;
        self::$toStdout = $toStdout;
        self::$sink = new LogFile($file);
        if ($file !== "") {
            $dir = dirname($file);
            if ($dir !== "" && !is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }

    /**
     * 设置默认通道的轮转：单文件上限（字节）与保留份数。上限为 0 = 不轮转。
     *
     * 必须在 `init()` 之后调用。
     */
    public static function setRotation(int $maxBytes, int $maxFiles): void
    {
        self::ensureSink();
        self::$sink->setRotation($maxBytes, $maxFiles);
    }

    /**
     * 建一个具名通道，写到自己的文件。审计日志这类"要单独留存、单独检索"的记录用它。
     *
     * 文件名为空 = 不建（之后 `Logger::to()` 落回默认通道，不会丢）。
     * 通道有自己的级别阈值，不受主日志 `log.level` 影响——把主日志调到 warn 时，
     * 审计记录不该跟着消失。
     */
    public static function initChannel(
        string $name,
        string $file,
        int $level = self::INFO,
        int $maxBytes = 0,
        int $maxFiles = 5
    ): void {
        if ($file === "") {
            unset(self::$sinks[$name], self::$sinkLevels[$name]);
            return;
        }
        $dir = dirname($file);
        if ($dir !== "" && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        self::$sinks[$name] = new LogFile($file, $maxBytes, $maxFiles);
        self::$sinkLevels[$name] = $level;
    }

    /** 通道是否存在（建过且文件名非空）；给测试和自省用 */
    public static function hasChannel(string $name): bool
    {
        return isset(self::$sinks[$name]);
    }

    /** 默认通道的轮转次数（进程内） */
    public static function rotations(): int
    {
        self::ensureSink();
        return self::$sink->rotations();
    }

    /** 从没调过 init() 也要能写：退化为"只有 stdout" */
    private static function ensureSink(): void
    {
        if (!isset(self::$sink)) {
            self::$sink = new LogFile("");
        }
    }

    /** 通道的轮转次数（进程内）；通道不存在返回 0 */
    public static function channelRotations(string $name): int
    {
        $s = self::$sinks[$name] ?? null;
        if ($s === null) {
            return 0;
        }
        return $s->rotations();
    }

    /**
     * 往具名通道写一条。通道没建过时落回默认通道——调用方不用判断"有没有配"。
     *
     * 级别低于该通道阈值的直接丢弃。error 级别照旧额外写一份到 stderr。
     */
    public static function to(string $channel, int $lv, string $msg, array $ctx = []): void
    {
        $s = self::$sinks[$channel] ?? null;
        if ($s === null) {
            self::write($lv, $msg, $ctx);
            return;
        }
        if ($lv < self::$sinkLevels[$channel]) {
            return;
        }
        $line = self::format($lv, $channel, $msg, $ctx);
        $s->write($line);
        // 纯 stdout 部署（没配文件）时通道日志也要看得见
        if (self::$file === "") {
            echo $line;
        }
        if ($lv === self::ERROR) {
            @file_put_contents("php://stderr", $line);
        }
    }

    public static function levelFromName(string $name): int
    {
        if ($name === "debug") {
            return self::DEBUG;
        }
        if ($name === "warn" || $name === "warning") {
            return self::WARN;
        }
        if ($name === "error") {
            return self::ERROR;
        }
        return self::INFO;
    }

    private static function levelName(int $lv): string
    {
        if ($lv === self::DEBUG) {
            return "debug";
        }
        if ($lv === self::WARN) {
            return "warn";
        }
        if ($lv === self::ERROR) {
            return "error";
        }
        return "info";
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::write(self::DEBUG, $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write(self::INFO, $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write(self::WARN, $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write(self::ERROR, $msg, $ctx);
    }

    /**
     * 取"当前链路 id"的回调。由 `Scheduler` 在启动时注册。
     *
     * 用回调而不是让 Logger 直接调 `Scheduler::instance()`：Logger 在 Kernel 层，
     * Scheduler 在 Service 层，直接依赖会把分层方向拧反（§3.3 的硬约束）。
     */
    private static ?\Closure $traceProvider = null;

    public static function setTraceProvider(\Closure $fn): void
    {
        self::$traceProvider = $fn;
    }

    /**
     * 取当前链路 id；没有链路时返回 0。
     *
     * 给"需要把 trace 落到别处"的组件用（比如 `pkg/eventlog` 记账）。
     * 它们不该各自去调 `Scheduler::instance()`——可选包依赖核心是允许的，
     * 但让每个包都认识调度器，等于把它们绑死在一种运行方式上。
     */
    public static function trace(): int
    {
        $tp = self::$traceProvider;
        if (!($tp instanceof \Closure)) {
            return 0;
        }
        $t = $tp();
        return is_int($t) ? $t : 0;
    }

    /** 把一条记录编成一行 JSON（带换行） */
    private static function format(int $lv, string $channel, string $msg, array $ctx): string
    {
        $rec = [
            "ts" => date("c"),
            "lvl" => self::levelName($lv),
            "ch" => $channel,
            "pid" => posix_getpid(),
            "msg" => $msg,
        ];
        // 链路 id 自动进日志：不用业务记得传。没有链路（定时器/心跳）时不加字段，
        // 免得每条后台日志都挂一个 trace:0 干扰 grep
        $tp = self::$traceProvider;
        if ($tp instanceof \Closure) {
            $t = $tp();
            if (is_int($t) && $t > 0 && !isset($ctx["trace"])) {
                $ctx["trace"] = $t;
            }
        }
        if ($ctx !== []) {
            $rec["ctx"] = $ctx;
        }
        $line = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = "{\"lvl\":\"error\",\"msg\":\"log encode failed\"}";
        }
        $line .= "\n";
        return $line;
    }

    private static function write(int $lv, string $msg, array $ctx): void
    {
        if ($lv < self::$level) {
            return;
        }
        $line = self::format($lv, self::$channel, $msg, $ctx);
        self::ensureSink();
        self::$sink->write($line);
        if (self::$toStdout || self::$file === "") {
            echo $line;
        }
        if ($lv === self::ERROR) {
            @file_put_contents("php://stderr", $line);
        }
    }
}
