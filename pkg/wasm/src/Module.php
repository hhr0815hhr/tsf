<?php

namespace TSF\Wasm;

/**
 * 一个已加载的 wasm 模块（主实例 + 可选灰度实例）。
 *
 * 分流是**确定性**的：同一个 `$key` 永远落到同一侧。游戏里这个 key 通常是玩家 id——
 * 用随机数分流会让同一局的一半请求走旧逻辑、一半走新逻辑，状态会分裂。
 */
final class Module
{
    private int $slot;

    private string $name;

    private function __construct(int $slot, string $name)
    {
        $this->slot = $slot;
        $this->name = $name;
    }

    /**
     * 加载主实例。路径必须是 `tsf module build` 产出的 cwasm——
     * 用 `wasmtime compile` 直接编出来的配置不一致，宿主会拒绝启动。
     */
    public static function load(string $name, string $path): ?self
    {
        $slot = \wasm_load($name, $path);
        if ($slot < 0) {
            return null;
        }
        $m = new self($slot, $name);
        Registry::attach($name, $m);
        return $m;
    }

    /**
     * 加载灰度实例并设比例（千分比）。0 = 全走主，1000 = 全走灰度。
     * 灰度实例加载失败时主实例继续服务，不会中断。
     */
    public function canary(string $path, int $permille): bool
    {
        if (\wasm_canary($this->slot, $path) !== 0) {
            return false;
        }
        \wasm_ratio($this->slot, $permille);
        return true;
    }

    /** 调整灰度比例，不动实例 */
    public function setRatio(int $permille): int
    {
        return \wasm_ratio($this->slot, $permille);
    }

    /**
     * 按 key 分流调用。key <= 0 时恒走主实例。
     * 失败返回负值（-1000 句柄无效 / -2000+rc 宿主错误）。
     */
    public function call2(string $fn, int $a, int $b, int $key = 0): int
    {
        return \wasm_call2($this->slot, $key, $fn, $a, $b);
    }

    /** 原子切换到新实现，返回耗时微秒；失败返回负值且旧实例继续服务 */
    public function swap(string $path): int
    {
        return \wasm_swap($this->slot, $path);
    }

    /** 观测：main=N,canary=M,ratio=R */
    public function stats(): string
    {
        return \wasm_stats($this->slot);
    }

    public function name(): string
    {
        return $this->name;
    }

    public static function bridgeVersion(): string
    {
        return \wasm_version();
    }

    public function close(): void
    {
        \wasm_close($this->slot);
        Registry::detach($this->name);
    }
}
