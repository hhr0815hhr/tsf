<?php

namespace TSF\Gateway;

/**
 * 每连接令牌桶限流（按秒批量补充）。
 *
 * 设计动机来自实测：AOT 下一次 `isset` 205 ns、数组读 153 ns、`microtime` 233 ns。
 * 最初按"每次调用现算补充"实现，单次 allow() 要 949 ns——占 23 µs 帧预算的 4%。
 *
 * 改成两个动作：
 *   refillAll()  每秒一次，把所有连接的令牌补满（O(n) 摊到 1 秒，成本可忽略）
 *   take()       每帧一次，只做"读-判断-写"，约 350 ns
 *
 * 精度代价：补充粒度从微秒级变成秒级。对"每秒允许 N 条"的语义完全够用。
 */
final class RateLimiter
{
    /** 每秒补充的令牌数；<=0 表示不限流（快路径直接放行） */
    private int $rate;

    /** 桶容量（允许的瞬时突发） */
    private int $burst;

    /** @var array<int, int> fd => 当前令牌数（整数，省掉浮点运算） */
    private array $tokens = [];

    private bool $enabled;

    public function __construct(int $ratePerSec, int $burst)
    {
        $this->rate = $ratePerSec;
        $this->burst = $burst;
        $this->enabled = $ratePerSec > 0;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function open(int $fd): void
    {
        if ($this->enabled) {
            $this->tokens[$fd] = $this->burst;
        }
    }

    /** 每秒调用一次：全体连接补充令牌 */
    public function refillAll(): void
    {
        if (!$this->enabled) {
            return;
        }
        $burst = $this->burst;
        $rate = $this->rate;
        foreach ($this->tokens as $fd => $t) {
            $n = $t + $rate;
            $this->tokens[$fd] = $n > $burst ? $burst : $n;
        }
    }

    /** 每帧调用：取一个令牌。返回 false 表示超限 */
    public function take(int $fd): bool
    {
        if (!$this->enabled) {
            return true;
        }
        if (!isset($this->tokens[$fd])) {
            $this->tokens[$fd] = $this->burst;
        }
        $t = $this->tokens[$fd];
        if ($t < 1) {
            return false;
        }
        $this->tokens[$fd] = $t - 1;
        return true;
    }

    public function close(int $fd): void
    {
        unset($this->tokens[$fd]);
    }

    public function tokenOf(int $fd): int
    {
        return $this->tokens[$fd] ?? 0;
    }
}
