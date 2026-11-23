<?php

namespace TSF\Script;

use TSF\Kernel\Logger;

/**
 * 多脚本管理：注册、按名取、批量检测变更。
 *
 * ```php
 * $mgr = new Manager();
 * $mgr->register("quest", __DIR__ . "/scripts/quest.php");
 * $mgr->register("levelup", __DIR__ . "/scripts/levelup.php");
 * $mgr->loadAll();
 *
 * // 每秒调一次（放帧循环或定时器里）
 * $reloaded = $mgr->watchAll();
 * if ($reloaded !== []) {
 *     Logger::info("scripts reloaded", ["names" => $reloaded]);
 * }
 *
 * // 业务代码
 * $mgr->get("quest")?->call("onQuest", $player);
 * ```
 *
 * **注意**：`watchAll` 是同步 `stat` 检查，脚本多了会慢。
 * 脚本超过 20 个时改成定时器每 5 秒扫一次，或者用 inotify（Linux）。
 */
final class Manager
{
    /** @var array<string, Script> */
    private array $scripts = [];

    public function register(string $name, string $path): Script
    {
        $s = new Script($path);
        $this->scripts[$name] = $s;
        return $s;
    }

    public function get(string $name): ?Script
    {
        return $this->scripts[$name] ?? null;
    }

    /** @return list<string> 加载失败的脚本名 */
    public function loadAll(): array
    {
        $failed = [];
        foreach ($this->scripts as $name => $s) {
            if (!$s->load()) {
                $failed[] = $name;
            }
        }
        if ($failed !== []) {
            Logger::error("script load failed", ["names" => $failed]);
        }
        return $failed;
    }

    /** @return list<string> 本次重载的脚本名 */
    public function watchAll(): array
    {
        $reloaded = [];
        foreach ($this->scripts as $name => $s) {
            if ($s->reloadIfChanged()) {
                $reloaded[] = $name;
            }
        }
        return $reloaded;
    }

    /** @return list<string> */
    public function names(): array
    {
        $out = [];
        foreach ($this->scripts as $k => $_) {
            $out[] = $k;
        }
        return $out;
    }

    /** 脚本间调用的最大嵌套层数。超过就认为写成了循环调用 */
    public const MAX_DEPTH = 8;

    private int $depth = 0;

    /**
     * 脚本间调用：按逻辑名找到目标脚本，调它的入口。
     *
     * 为什么放在 Manager 而不是 Script：只有 Manager 知道"逻辑名 → 文件"的映射，
     * 也只有它能统一管**递归深度**——A 调 B、B 又调 A 时，任何一方单独都看不全局。
     *
     * 超时预算：嵌套调用共用一次外层预算（每层各给 500ms 会被叠乘），
     * 所以这里固定给较短的 500ms，真正的兜底靠最外层的执行预算（§39）。
     */
    public function invoke(string $target, string $entry, string $arg = "", int $timeoutMs = 500): string|int|float|bool|null
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new \RuntimeException(
                "script: 调用层级超过 " . self::MAX_DEPTH . " 层（疑似循环调用 " . $target . ":" . $entry . "）"
            );
        }
        $s = $this->get($target);
        if ($s === null) {
            return null;
        }
        $holder = new ScriptArg();
        $holder->value = $arg;
        $ctx = new Context($holder, ["value"], [], $arg);

        // **自动绑定 callScript**：这是脚本层自身的能力（Manager 就是它的实现），
        // 让业务每处手动绑的话，嵌套调用必然在第二层断开——A→B 能通，B→C 就炸。
        // 递归不靠这里防，靠上面的 depth 上限。
        $self = $this;
        $ctx->bind("callScript", function (string $t, string $e, string $a) use ($self): string|int|float|bool|null {
            return $self->invoke($t, $e, $a);
        });

        $this->depth++;
        try {
            $r = $s->callWithContext($entry, $ctx, $timeoutMs);
        } finally {
            $this->depth--;
        }
        // 目标脚本出错时 interp 返回结构化错误数组。**不能静默变 null**：
        // 调用方会以为"对方返回空"，而不是"对方炸了"，这类问题最难查。
        if (is_array($r) && isset($r["ok"]) && $r["ok"] === false) {
            $why = isset($r["message"]) ? (string) $r["message"] : "script error";
            throw new \RuntimeException("script " . $target . ":" . $entry . " failed: " . $why);
        }
        if (is_bool($r) || is_int($r) || is_float($r) || is_string($r)) {
            return $r;
        }
        return null;
    }
}

/**
 * 脚本间调用的入参容器。
 *
 * 存在的理由：`Context` 的 target 必须是对象，而调用入参是一个字符串。
 * 与其让 Context 在 target 为 null 时特判，不如给一个只有 `value` 字段的载体——
 * 脚本侧用 `$ctx->arg()` 读，不用关心它长什么样。
 */
final class ScriptArg
{
    public string $value = "";
}
