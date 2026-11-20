<?php

namespace TSF\Handoff;

use TSF\Shard\Ring;

/**
 * 用一致性哈希环回答归属。
 *
 * 只做一层委托，但值得单独存在：`Rebalancer` 因此不需要知道"环"这个概念，
 * 也就不会顺手去调 `ring->add()` / `rebuild()` 之类——成员变更只该有一个人负责，
 * 调度器和路由各改一次是集群分裂的经典成因。
 */
final class RingOwnership implements Ownership
{
    private Ring $ring;

    public function __construct(Ring $ring)
    {
        $this->ring = $ring;
    }

    public function ownerOf(string $key): int
    {
        return $this->ring->nodeFor($key);
    }
}
