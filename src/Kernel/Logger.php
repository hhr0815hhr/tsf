<?php

namespace TSF\Kernel;

/**
 * 结构化日志（JSON Lines）。
 *
 * 设计取舍：
 *  - 一行一条 JSON，方便 filebeat/vector 直接采集，不需要正则解析；
 *  - 级别阈值可配，error 额外写 stderr，便于 journald/容器日志第一时间抓到；
 *  - 不做异步落盘（M0 阶段）：写文件在 page cache 里，成本可忽略；后续接 ring buffer。
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

    public static function init(string $channel, string $file = "", int $level = self::INFO, bool $toStdout = false): void
    {
        self::$channel = $channel;
        self::$file = $file;
        self::$level = $level;
        self::$toStdout = $toStdout;
        if ($file !== "") {
            $dir = dirname($file);
            if ($dir !== "" && !is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
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

    private static function write(int $lv, string $msg, array $ctx): void
    {
        if ($lv < self::$level) {
            return;
        }
        $rec = [
            "ts" => date("c"),
            "lvl" => self::levelName($lv),
            "ch" => self::$channel,
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

        if (self::$file !== "") {
            @file_put_contents(self::$file, $line, FILE_APPEND);
        }
        if (self::$toStdout || self::$file === "") {
            echo $line;
        }
        if ($lv === self::ERROR) {
            @file_put_contents("php://stderr", $line);
        }
    }
}
