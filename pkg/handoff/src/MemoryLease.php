<?php

namespace TSF\Handoff;

/**
 * 进程内的租约实现。
 *
 * **只在单进程/单机部署下有效**：它保证不了任何跨节点互斥——两个进程各有各的
 * 数组。存在的意义是让单机也能用同一套编排代码，以及给测试一个确定性实现。
 *
 * 跨机请用 `TSF\Registry\RedisLease`（`pkg/registry`）。
 */
final class MemoryLease implements Lease
{
    /** @var array<string, int> key => 到期时刻（毫秒） */
    private array $held = [];

    private int $nodeId;

    public function __construct(int $nodeId = 1)
    {
        $this->nodeId = $nodeId;
    }

    public function tryAcquire(string $key, int $ttlMs): bool
    {
        $now = $this->nowMs();
        $exp = $this->held[$key] ?? 0;
        if ($exp > $now) {
            return false;   // 还被人拿着
        }
        $this->held[$key] = $now + ($ttlMs > 0 ? $ttlMs : 1);
        return true;
    }

    public function renew(string $key, int $ttlMs): bool
    {
        $now = $this->nowMs();
        $exp = $this->held[$key] ?? 0;
        if ($exp <= $now) {
            // 已经过期：本地也不再认为持有，和 Redis 里"过期即丢"保持一致
            unset($this->held[$key]);
            return false;
        }
        $this->held[$key] = $now + ($ttlMs > 0 ? $ttlMs : 1);
        return true;
    }

    public function release(string $key): bool
    {
        unset($this->held[$key]);
        return true;
    }

    public function holds(string $key): bool
    {
        return ($this->held[$key] ?? 0) > $this->nowMs();
    }

    public function nodeId(): int
    {
        return $this->nodeId;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
