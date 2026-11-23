<?php

namespace TSF\Script;

/**
 * 脚本私有状态的容器。
 *
 * ## 为什么需要它
 *
 * 热更的本质是"重新 require 一遍文件"，于是脚本里的 `static` 变量、`global` 变量
 * **必然重置**——这不是 PHP 的限制，Lua 换 chunk 也一样。
 *
 * 但玩法恰恰需要跨 reload 存活的东西：
 *
 * - 活动已发奖名单（reload 后不能重复发）
 * - 本轮累计计数（reload 后不能清零）
 * - 冷却时间戳
 *
 * 所以状态不能放在脚本里，要**外置到宿主**，由脚本通过 `Context` 读写。
 * 这和 Lua 界的通行做法一致：状态放在宿主侧的表里，脚本只负责逻辑。
 *
 * ## 生命周期
 *
 * 挂在 `Script` 对象上，`reload()` 只换 `exports`，不碰 `state`——
 * 所以"改脚本"不会丢状态，"重启进程"才会（要跨进程得自己落到 Redis/文件）。
 *
 * ## 为什么是独立类而不是 `array`
 *
 * PHP 数组是值语义，`Context` 拿到的会是副本，写回去就丢了。
 * 必须用一个对象持有它，所有 Context 共享同一个引用。
 */
final class State
{
    /**
     * 键值表。只允许标量：脚本状态是"计数器/时间戳/名单开关"这类，
     * 复杂结构应该落库，而不是在内存里堆。
     *
     * @var array<string, int|string|float|bool>
     */
    public array $data = [];

    public function getInt(string $key, int $default = 0): int
    {
        if (!isset($this->data[$key])) {
            return $default;
        }
        $v = $this->data[$key];
        return is_int($v) ? $v : (int) $v;
    }

    public function setInt(string $key, int $value): void
    {
        $this->data[$key] = $value;
    }

    public function addInt(string $key, int $delta): int
    {
        $next = $this->getInt($key) + $delta;
        $this->data[$key] = $next;
        return $next;
    }

    public function getString(string $key, string $default = ""): string
    {
        if (!isset($this->data[$key])) {
            return $default;
        }
        $v = $this->data[$key];
        return is_string($v) ? $v : (string) $v;
    }

    public function setString(string $key, string $value): void
    {
        $this->data[$key] = $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        if (!isset($this->data[$key])) {
            return $default;
        }
        return $this->data[$key] === true;
    }

    public function setBool(string $key, bool $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function del(string $key): void
    {
        unset($this->data[$key]);
    }

    public function count(): int
    {
        return count($this->data);
    }

    /** 导出全部（落盘/落库用）。返回的是副本，改它不影响内部 */
    public function export(): array
    {
        return $this->data;
    }

    /** 载入（从落盘/落库恢复）。整体替换，不做合并——调用方自己决定语义 */
    public function import(array $data): void
    {
        $this->data = [];
        foreach ($data as $k => $v) {
            if (is_int($v) || is_string($v) || is_float($v) || is_bool($v)) {
                $this->data[(string) $k] = $v;
            }
        }
    }
}
