<?php

namespace TSF\Script;

/**
 * 一个热更脚本。
 *
 * ## 脚本怎么写
 *
 * **必须 return 一个闭包或导出表**，不能定义全局函数：
 *
 * ```php
 * // 单个入口
 * return function ($player) {
 *     $player->gold = $player->gold + 350;
 * };
 *
 * // 多个入口
 * return [
 *     "onQuest"  => function ($player) { ... },
 *     "onLevelUp" => function ($player) { ... },
 * ];
 * ```
 *
 * **为什么不能用 `function onQuest()`**：PHP 不允许重复定义同名函数，
 * 热更时第二次 require 会报 `Cannot redeclare`。返回闭包则每次都是新对象，
 * 旧的自然被 GC。
 *
 * ## 为什么调用走 interp_invoke
 *
 * AOT 编译期要确定调用目标的签名；闭包是运行期拿到的，直接 `$fn($p)` 会被 tpc 拒绝。
 */
final class Script
{
    /** @var array<string, object>|object|null 导出表或单个闭包 */
    private array|object|null $exports = null;

    private int $loadedMtime = 0;

    private string $path;

    private int $versions = 0;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** 首次加载。返回 false 表示文件不存在或加载失败。 */
    public function load(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        $r = \interp_require_file($this->path);
        if (!is_array($r) && !is_object($r)) {
            return false;
        }

        $this->exports = $r;
        $this->loadedMtime = $this->mtime();
        $this->versions++;
        return true;
    }

    /** 重新加载（热更）。 */
    public function reload(): bool
    {
        return $this->load();
    }

    /**
     * 调用脚本里的某个入口。
     *
     * @param string $name 导出表里的键；脚本返回单个闭包时传 "" 或 "main"
     * @param object $obj  传给脚本的对象（脚本里改它，宿主侧同一对象跟着变）
     */
    public function call(string $name, object $obj): array|object|string|int|float|bool|null
    {
        $target = $this->resolve($name);
        if ($target === null) {
            return null;
        }
        return \interp_invoke($target, $obj);
    }

    /**
     * 用受限视图调用脚本 —— **推荐的生产用法**。
     *
     * 和 call() 的区别：call() 直接传业务对象（脚本有全部权限），
     * callWithContext() 传 Context（只能按白名单读写）。
     */
    public function callWithContext(string $name, Context $ctx): array|object|string|int|float|bool|null
    {
        $target = $this->resolve($name);
        if ($target === null) {
            return null;
        }
        return \interp_invoke($target, $ctx);
    }

    /** 文件是否比上次加载时更新 */
    public function changed(): bool
    {
        return is_file($this->path) && $this->mtime() > $this->loadedMtime;
    }

    /** 变更时自动重载，返回是否重载了 */
    public function reloadIfChanged(): bool
    {
        if (!$this->changed()) {
            return false;
        }
        return $this->reload();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function mtime(): int
    {
        $t = @filemtime($this->path);
        return is_int($t) ? $t : 0;
    }

    /** 加载过几次（每次 reload +1，可用来确认热更真的发生了） */
    public function versions(): int
    {
        return $this->versions;
    }

    public function loaded(): bool
    {
        return $this->exports !== null;
    }

    /** @return object|null */
    private function resolve(string $name): ?object
    {
        $e = $this->exports;
        if ($e === null) {
            return null;
        }

        // 单个闭包
        if (is_object($e)) {
            return $e;
        }

        // 导出表
        if ($name === "" || $name === "main") {
            $first = $e["main"] ?? null;
            if (is_object($first)) {
                return $first;
            }
            // 没有 main 时，如果表里只有一个，就用它
            if (count($e) === 1) {
                foreach ($e as $v) {
                    return is_object($v) ? $v : null;
                }
            }
            return null;
        }

        $v = $e[$name] ?? null;
        return is_object($v) ? $v : null;
    }
}
