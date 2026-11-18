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
}
