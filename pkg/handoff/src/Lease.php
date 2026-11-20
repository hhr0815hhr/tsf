<?php

namespace TSF\Handoff;

/**
 * key 归属的租约：把"谁持有这个 key"从**内存里的事实**变成**集群共识**。
 *
 * ## 为什么光有 Ring 不够
 *
 * `Ring` 算出来的归属是**推导值**：同样的环、同样的 key，每个节点都算出同一个答案。
 * 但推导只说明"应该归谁"，不说明"实际是谁在跑"。两种情况下两者会分叉：
 *
 * 1. 业务在两个节点上 `own()` 了同一个 key（代码 bug、或者上次交接没清理干净）；
 * 2. 交接途中老节点还没删、新节点已经装好，两个都认为自己持有。
 *
 * 分叉之后两个节点会同时发起交接，把同一份状态往两个方向搬——这是最难恢复的一类
 * 数据错乱。租约的作用就是在动手之前先"举手"：**只有拿到租约的节点才有资格搬。**
 *
 * ## 语义要求
 *
 * - `tryAcquire` 必须是**原子的**（Redis 里就是 `SET NX PX`）。分两步"先查后写"
 *   等于没做，两个节点会同时通过查询、然后同时写。
 * - 带 TTL。持有者崩了不能让 key 永久锁死——这与节点注册表的取舍一致：
 *   靠 TTL 收敛，而不是靠"我得记着去释放"。
 * - `renew` 只在**自己还持有**时续期。如果租约已经过期被别人拿走，续期必须失败，
 *   调用方据此停手（否则会出现"两个人各自拿着同一把锁"）。
 *
 * 这些语义用内存实现（`MemoryLease`）只在单进程里有意义，仅适合测试与单机部署；
 * 跨机必须用 `RedisLease`（`pkg/registry`）这类有中心存储的实现。
 */
interface Lease
{
    /**
     * 尝试取得租约。成功返回 true；已被别人持有（且未过期）返回 false。
     *
     * @param int $ttlMs 租约有效期，到期自动失效
     */
    public function tryAcquire(string $key, int $ttlMs): bool;

    /**
     * 续期。返回 false 表示**已经不再持有**（过期被别人拿走，或从未取得），
     * 调用方必须立刻停手并当作"这个 key 不归我"处理。
     */
    public function renew(string $key, int $ttlMs): bool;

    /** 释放。幂等：没持有也返回 true */
    public function release(string $key): bool;

    /** 当前是否持有（本地视角，不保证远端一致；排障用） */
    public function holds(string $key): bool;
}
