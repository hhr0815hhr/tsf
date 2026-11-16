<?php

namespace TSF\Room;

/**
 * 房间号的分段编址：`nodeId * 10^9 + seq`。
 *
 * ## 为什么不用全局自增
 *
 * 全局自增要么依赖一个中心服务（注册表/db），要么依赖"单写者"的假设。前者把每次建房
 * 变成一次网络往返，且中心挂了就建不了房；后者的代价在单机时代看不出来，一旦多节点
 * 就是 P0-2 那个形状——**两个节点各自从 1 数，号重复**。
 *
 * 分段是纯本地运算：给定 nodeId，本节点的号段是固定的，不和任何人协调。
 *
 * ## 为什么不做"号段回收"
 *
 * 房间号会进结算幂等键 `duel:<房间号>`。号一旦复用，新的一局会被判成"已结过账"
 * → **钱静默不发、日志也不多一行**（见 `Settle::maxSettledRoom()` 的注释）。
 * 回收省下的只是"号用得慢一点"，换来的是一个会静默吞钱的复用源，方向就是错的。
 *
 * 容量：nodeId ≤ 255，单节点 10 亿号。按每秒建 1 个房、24 小时不停算约 31 年。
 *
 * ## node 0 的行为与旧代码一致
 *
 * `compose(0, n) === n`，所以单节点部署（nodeId=0）拿到的号还是 1,2,3……，
 * 老日志、老断言、老的 `duel:1` 全都照旧。
 */
final class RoomIds
{
    /** 每个节点占 10 亿个号 */
    public const SEGMENT = 1000000000;

    /** 节点号上限（与 Addr 的 node 位一致） */
    public const MAX_NODE = 255;

    /** 房间号 0 保留为"无" */
    public static function compose(int $node, int $seq): int
    {
        if ($node < 0 || $node > self::MAX_NODE || $seq <= 0 || $seq >= self::SEGMENT) {
            return 0;
        }
        return $node * self::SEGMENT + $seq;
    }

    /** 这个号属于哪个节点（0 号房返回 -1，因为 0 是保留值） */
    public static function node(int $roomId): int
    {
        if ($roomId <= 0) {
            return -1;
        }
        return intdiv($roomId, self::SEGMENT);
    }

    /** 段内序号（0 号房返回 0） */
    public static function seq(int $roomId): int
    {
        if ($roomId <= 0) {
            return 0;
        }
        return $roomId % self::SEGMENT;
    }

    /**
     * 给定一批已存在的房间号，算出本节点下一个可用的段内序号。
     *
     * **只在段内取最大值**：别的节点的号段不参与，否则节点 1 会从节点 0 的号后面接着数，
     * 白扔掉自己整段空间。
     *
     * @param array<int> $existing 已存在的房间号（已结算的、还在跑的、快照里的都行）
     */
    public static function nextSeq(int $node, array $existing): int
    {
        $max = 0;
        foreach ($existing as $id) {
            $id = (int) $id;
            if (self::node($id) !== $node) {
                continue;
            }
            $s = self::seq($id);
            if ($s > $max) {
                $max = $s;
            }
        }
        return $max + 1;
    }

    /** 给人看的展示形式：`n1#42`（日志和工单里比 1000000042 好认） */
    public static function label(int $roomId): string
    {
        return "n" . self::node($roomId) . "#" . self::seq($roomId);
    }
}
