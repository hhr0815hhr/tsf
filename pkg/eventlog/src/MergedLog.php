<?php

namespace TSF\EventLog;

/**
 * 把多个节点的事件日志文件归并成一条有序流（**只读**）。
 *
 * ## 为什么可以合并，而不是"必须全局有序"
 *
 * 有了房间归属，**同一个房间的钱只由一个节点写**。所以：
 *
 * - 同一房间的事件，在它自己那个文件里的先后是对的；
 * - 跨房间（跨节点）的先后，对正确性没有影响——对账是逐 uid 比余额，
 *   结算幂等键是业务主键 `duel:<房间号>`，没有任何地方依赖"全局第 3 条是谁"。
 *
 * 换句话说，全局序不是需求。归并只是为了**确定**：同样的输入必须得到同样的折叠结果，
 * 否则对账会出现"两次跑结果不同"的假警报。
 *
 * ## 排序键 `(t, 文件序号, s)`
 *
 * - `t` 是事件时间（毫秒）。不同节点在同一毫秒写的事件，谁先谁后本身没有意义；
 * - 用**文件序号**而不是节点号来打破平手，是因为 `EventLog` 不记录自己属于哪个节点；
 *   只要能区分开、且每次一致就够了；
 * - `s` 是节点内的序号，同一毫秒内同文件的顺序是真实的。
 *
 * ## 代价
 *
 * 全量读进内存再排序：10 万条 ≈ 20 MB 量级。启动路径上可以接受（余额重建本来就要
 * 扫全量），但如果将来日志到千万级，得换成 k 路流式归并（每个文件一个游标）。
 * 这里先用简单实现，把接口留成"返回数组"。
 */
final class MergedLog
{
    /**
     * @param array<EventLog> $logs 各节点的日志（顺序决定同 `t` 时的稳定次序）
     * @return array<array{s: int, id: string, k: string, t: int, d: array}>
     */
    public static function replay(array $logs, int $fromSeq = 1): array
    {
        $all = [];
        $i = 0;
        foreach ($logs as $log) {
            foreach ($log->replay($fromSeq) as $ev) {
                $ev["_f"] = $i;   // 文件序号，只用于排序，不进事件本身
                $all[] = $ev;
            }
            $i++;
        }

        // 稳定比较：t → 文件 → 序号
        usort($all, static function (array $a, array $b): int {
            if ($a["t"] !== $b["t"]) {
                return $a["t"] < $b["t"] ? -1 : 1;
            }
            if ($a["_f"] !== $b["_f"]) {
                return $a["_f"] < $b["_f"] ? -1 : 1;
            }
            if ($a["s"] === $b["s"]) {
                return 0;
            }
            return $a["s"] < $b["s"] ? -1 : 1;
        });

        $out = [];
        foreach ($all as $ev) {
            unset($ev["_f"]);
            $out[] = $ev;
        }
        return $out;
    }

    /** @param array<EventLog> $logs */
    public static function badLines(array $logs): int
    {
        $n = 0;
        foreach ($logs as $log) {
            $n += $log->badLines();
        }
        return $n;
    }
}
