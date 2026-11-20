<?php

namespace TSF\Handoff;

/**
 * "这个 key 目前该归哪个节点"。
 *
 * 自动重平衡只依赖这一个判断，所以抽成接口而不是直接吃 `Shard\Ring`：
 *
 * - 生产里用 `RingOwnership` 包一层（`Ring::nodeFor`）；
 * - 测试里塞一张写死的映射表，就能精确构造"该搬 / 不该搬 / 目标不可达"三种场景，
 *   不需要真的凑出一个哈希环。
 *
 * 返回 0 表示"没有归属"（例如集群还没成员），调用方必须跳过，不能当成节点 0。
 */
interface Ownership
{
    public function ownerOf(string $key): int;
}
