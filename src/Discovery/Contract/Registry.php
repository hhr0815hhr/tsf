<?php

namespace TSF\Discovery\Contract;

use TSF\Discovery\NodeInfo;

/**
 * 节点注册表。抽象出来是因为"集群成员从哪来"完全取决于部署环境：
 *
 * | 实现 | 适合 | 代价 |
 * |:--|:--|:--|
 * | `FileRegistry` | 单机多进程 / 测试 | 轮询目录 |
 * | Redis / etcd 实现 | 跨机器 | 一次网络往返 |
 *
 * 上层（路由、负载均衡）只看到 `alive()` 和 `get()`，不关心底下是文件还是 Redis。
 *
 * ## 存活判定用"最后一次心跳 + TTL"，不用"显式下线"
 *
 * 进程被 `kill -9` 时没有任何机会发"我下线了"——显式注销只对优雅关闭有效。
 * 所以真正的存活判定必须是 `now - lastSeen >= ttl`，注销只是"加速收敛"的优化。
 *
 * 这也意味着**TTL 决定了故障收敛时间**，而收敛时间和误判率是一对矛盾：
 * TTL 太小，GC 抖动或一次调度延迟就会误判节点死亡；太大，故障恢复就慢。
 * 3 秒是个常见折中（验收线也是 3 s）。
 */
interface Registry
{
    /** 注册（或覆盖）一个节点。心跳由 `heartbeat()` 单独负责 */
    public function register(NodeInfo $n): bool;

    /** 心跳：只更新"还活着"这个事实，不改其他字段 */
    public function heartbeat(int $nodeId): bool;

    /** 显式注销。进程正常退出时调，能立刻收敛；崩溃时靠 TTL */
    public function unregister(int $nodeId): bool;

    /** 取一个节点（不管死活）。不存在返回 null */
    public function get(int $nodeId): ?NodeInfo;

    /**
     * 活着的节点列表。
     *
     * `$ttlMs = 0` 表示用实现自己的默认值。
     *
     * @return array<int, NodeInfo>
     */
    public function alive(int $ttlMs = 0): array;

    /** 全部节点（含已过期的），排障用 */
    public function all(): array;

    /**
     * 重新扫描并对比上次快照。有变化时触发回调，返回是否变化。
     *
     * 这是"变更通知"的实现方式：文件/Redis 都不提供推送，只能由调用方
     * 定期 `refresh()`。做成显式方法而不是内部定时器，是因为轮询频率
     * 应该由使用场景决定（测试里可能只想扫一次）。
     */
    public function refresh(): bool;

    /** 注册变更回调：`function (): void` */
    public function onChanged(\Closure $cb): void;

    /** 上一次快照里的节点数（观测用） */
    public function known(): int;
}
