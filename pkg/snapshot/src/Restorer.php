<?php

namespace TSF\Snapshot;

/**
 * 批量恢复：枚举 → 逐个读 → 交给调用方重建。
 *
 * ## 它不"限流"，它让恢复这件事**可观测、可分片**
 *
 * 计划里这一项叫「恢复限流」，原始假设是"60 个房间同时恢复会打满 CPU"。
 * 实测否决了这个假设（`bench/snapshot-test`）：
 *
 * | 房间数 | 恢复耗时 |
 * |--:|--:|
 * | 60 | **1.68 ms** |
 * | 500 | 14.82 ms |
 *
 * 验收线是 500 ms，实际快了两个数量级——**顺序恢复就够了，人为限流只会拖慢启动**。
 *
 * 真正的问题在另一个方向：**恢复发生在 workerStart 里，这段时间 worker 不接受连接**。
 * 1.68 ms 无所谓，但如果哪天单机有 5 万个房间，恢复会变成几百毫秒，
 * 启动期就成了服务真空。
 *
 * 所以这里提供的是：
 *
 * - **分批**（`$batchSize`）：每批之间记账，为将来"分批 + 延后"留出结构；
 * - **耗时统计与阈值**：超了就通过返回值告诉调用方，由它决定打日志还是告警；
 * - **坏数据就地清理**：解不出来的快照直接删，否则每次启动都要失败一遍。
 *
 * ## 为什么不认识 Logger
 *
 * 这个包零依赖是刻意的——快照机制不该把日志框架拖进来。统计结果返回给调用方，
 * 它爱怎么记怎么记。
 */
final class Restorer
{
    private int $batchSize;

    private int $warnAtMs;

    /**
     * @param int $batchSize 每多少条算一批（只影响记账粒度，不影响顺序）
     * @param int $warnAtMs  超过这个耗时就在结果的 `slow` 里标记
     */
    public function __construct(int $batchSize = 50, int $warnAtMs = 200)
    {
        $this->batchSize = $batchSize > 0 ? $batchSize : 50;
        $this->warnAtMs = $warnAtMs > 0 ? $warnAtMs : 200;
    }

    /**
     * 恢复某一类快照。
     *
     * @param \Closure     $rebuild (Snapshot): bool —— 返回 false 表示这条数据不可用
     * @param bool         $dropBad 解不出来/重建失败时是否删掉快照
     * @param ?\Closure    $accept  (int $key): bool —— **过滤归属**，返回 false 表示"不是我的"
     *
     * `$accept` 单独一个参数，而不是让 `$rebuild` 返回 false 来跳过，是因为两者语义不同：
     * `$rebuild` 返回 false 意味着"这条数据坏了，可以删"（`$dropBad` 时会真删）。
     * 拿它来跳过别人的房间会把**别的节点的快照删掉**——多节点下这是数据丢失，
     * 而且不报错，只在那个节点的玩家回不来时才被发现。
     *
     * @return array{done: int, skipped: int, not_mine: int, ms: int, slow: bool}
     */
    public function restore(
        Store $store,
        string $kind,
        \Closure $rebuild,
        bool $dropBad = true,
        ?\Closure $accept = null
    ): array {
        $t0 = hrtime(true);
        $keys = $store->keys($kind);
        $notMine = 0;
        $done = 0;
        $skipped = 0;
        $i = 0;
        $slow = false;

        foreach ($keys as $id) {
            // 归属过滤放在 get() 之前：不属于本节点的快照连读都不读，
            // 更不能走到下面任何一条 delete 分支
            if ($accept !== null && !$accept($id)) {
                $notMine++;
                continue;
            }

            $snap = $store->get($kind, $id);
            if ($snap === null) {
                // 版本不符或文件损坏：删掉，否则每次启动都要在这里卡一下
                if ($dropBad) {
                    $store->delete($kind, $id);
                }
                $skipped++;
                continue;
            }

            if ($rebuild($snap)) {
                $done++;
            } else {
                if ($dropBad) {
                    $store->delete($kind, $id);
                }
                $skipped++;
            }

            $i++;
            if ($i % $this->batchSize === 0) {
                $ms = (int) ((hrtime(true) - $t0) / 1000000);
                if ($ms >= $this->warnAtMs) {
                    $slow = true;
                }
            }
        }

        $ms = (int) ((hrtime(true) - $t0) / 1000000);
        if ($ms >= $this->warnAtMs) {
            $slow = true;
        }

        return [
            "done" => $done,
            "skipped" => $skipped,
            "not_mine" => $notMine,
            "ms" => $ms,
            "slow" => $slow,
        ];
    }
}
