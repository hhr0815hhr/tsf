<?php

namespace TSF\Gm;

use TSF\Kernel\Logger;

/**
 * GM 指令注册表 + 派发。
 *
 * ```php
 * Registry::on("player.ban", function (array $args): Result {
 *     $uid = isset($args["uid"]) ? (int) $args["uid"] : 0;
 *     if ($uid <= 0) { return Result::fail("uid 必填"); }
 *     // ... 真的去封
 *     return Result::ok("{\"uid\":" . $uid . "}");
 * });
 * ```
 *
 * ## 为什么 handler 拿的是 array 而不是强类型对象
 *
 * GM 指令是"运维手输"的入口，参数天然是松散的键值：今天加一个 `--reason`，
 * 明天加一个 `--duration`。为每条指令生成一个参数类，收益只是一点点补全，
 * 代价是每加一个指令都要改代码 + 重新编译。
 *
 * 反过来，**返回类型必须是强类型**（`Result`）：那是框架要处理的东西，
 * 不能让它面对异构数组。
 *
 * ## 派发一定包 try/catch
 *
 * 一个 handler 抛异常不应该让 GM 端口挂掉——那样最需要运维的时候反而进不去。
 * 异常被转成 `{ok:false,error:...}`，同时记审计日志。
 *
 * ## 审计日志是**框架**打的，不是 handler 打的
 *
 * 让每个 handler 自己记审计，一定有人忘。所以派发前后由框架统一记：
 * 谁、从哪个 IP、什么指令、什么参数、成功还是失败、花了多久。
 * handler 只负责干活。
 */
final class Registry
{
    /** @var array<string, \Closure> */
    private static array $handlers = [];

    /** @var array<string, string> 指令 => 说明（`gm.help` 用） */
    private static array $docs = [];

    public static function on(string $cmd, \Closure $handler, string $doc = ""): void
    {
        self::$handlers[$cmd] = $handler;
        if ($doc !== "") {
            self::$docs[$cmd] = $doc;
        }
    }

    public static function has(string $cmd): bool
    {
        return isset(self::$handlers[$cmd]);
    }

    /** @return string[] */
    public static function commands(): array
    {
        $out = [];
        foreach (self::$handlers as $k => $v) {
            $out[] = (string) $k;
        }
        sort($out);
        return $out;
    }

    /** @return array<string, string> 指令 => 说明 */
    public static function docs(): array
    {
        return self::$docs;
    }

    public static function clear(): void
    {
        self::$handlers = [];
        self::$docs = [];
    }

    /**
     * 派发并记审计日志。**不抛异常**。
     *
     * `$peer` 是调用方 IP（已经过鉴权），写进审计日志用于追责。
     */
    public static function dispatch(string $cmd, array $args, string $peer = ""): Result
    {
        $t0 = hrtime(true);

        if ($cmd === "") {
            return self::audit($cmd, $args, $peer, Result::fail("缺少 cmd"), $t0);
        }
        if (!isset(self::$handlers[$cmd])) {
            return self::audit($cmd, $args, $peer, Result::fail("未知指令：" . $cmd), $t0);
        }

        $r = null;
        try {
            $fn = self::$handlers[$cmd];
            $r = $fn($args);
        } catch (\Throwable $e) {
            // 细节进日志，响应里只给一句——不要把堆栈/内部路径回给调用方
            Logger::error("gm handler failed", ["cmd" => $cmd, "err" => $e->getMessage()]);
            $r = Result::fail("指令执行失败：" . $e->getMessage());
        }

        if (!($r instanceof Result)) {
            $r = Result::fail("handler 没有返回 Result");
        }
        return self::audit($cmd, $args, $peer, $r, $t0);
    }

    private static function audit(string $cmd, array $args, string $peer, Result $r, int $t0): Result
    {
        $us = (int) round((float) (hrtime(true) - $t0) / 1000.0);
        $argStr = "";
        foreach ($args as $k => $v) {
            if ($argStr !== "") {
                $argStr .= "&";
            }
            // 值可能是数组/数字，统一转成字符串；长值截断避免日志被一个大参数撑爆
            $s = is_scalar($v) ? (string) $v : "...";
            if (strlen($s) > 64) {
                $s = substr($s, 0, 64) . "…";
            }
            $argStr .= $k . "=" . $s;
        }
        if (strlen($argStr) > 512) {
            $argStr = substr($argStr, 0, 512) . "…";
        }

        $ctx = [
            "peer" => $peer,
            "cmd" => $cmd,
            "args" => $argStr,
            "ok" => $r->ok,
            "us" => $us,
        ];
        if (!$r->ok) {
            $ctx["err"] = $r->error;
        }
        // 审计用 info 级别：它必须默认可见（warn 以上会被刷屏淹没，
        // 而审计的价值恰恰在于"事后按 uid 搜一条操作记录"）
        Logger::info("gm: " . $cmd, $ctx);

        return $r;
    }
}
