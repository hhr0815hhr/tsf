<?php

namespace TSF\Cluster;

/**
 * 服务地址编码：高 8 位节点号，低 24 位服务 id。
 *
 *   | node(8b) | id(24b) |
 *
 * 为什么把节点号编进地址而不是另开一个结构：
 * 业务发消息时不该关心对方在不在本进程。`Scheduler::deliver($addr, ...)` 按高位
 * 判断走本地队列还是走跨进程通道，调用方代码完全一样——这是"本地与远程
 * 长得一样"的前提，否则迟早会出现"这段代码只能在单进程跑"。
 *
 * 为什么放在 Cluster 命名空间而不是 Service：
 * 地址是**集群寻址**的概念，Service 和 Cluster 都要用它。放在 Service 里会让
 * Cluster 反向依赖 Service（分层倒挂）；放在这里则是 Service → Cluster 的单向依赖。
 *
 * 还有第三种选择：下沉到 `Kernel`（现在是纯叶子模块，不依赖任何兄弟模块），
 * 那样 `Scheduler` 就完全不依赖 Cluster。当前没有环、也没有实际问题，所以没改——
 * 这里记一笔，免得下一个人以为是随手放的。
 *
 * 容量：256 节点 × 16,777,215 服务。地址 0 保留为"系统/无"。
 */
final class Addr
{
    public const NODE_BITS = 8;

    public const ID_BITS = 24;

    public const ID_MASK = 0xFFFFFF;

    public const MAX_ID = 0xFFFFFF;

    public static function pack(int $node, int $id): int
    {
        return (($node & 0xFF) << self::ID_BITS) | ($id & self::ID_MASK);
    }

    public static function node(int $addr): int
    {
        return ($addr >> self::ID_BITS) & 0xFF;
    }

    public static function id(int $addr): int
    {
        return $addr & self::ID_MASK;
    }

    public static function isLocal(int $addr): bool
    {
        return (($addr >> self::ID_BITS) & 0xFF) === 0;
    }

    /** 便于日志阅读：n3:1042 或 local:1042 */
    public static function label(int $addr): string
    {
        $node = self::node($addr);
        $id = self::id($addr);
        if ($node === 0) {
            return "local:" . $id;
        }
        return "n" . $node . ":" . $id;
    }
}
