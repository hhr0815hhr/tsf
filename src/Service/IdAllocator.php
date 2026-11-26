<?php

namespace TSF\Service;

/**
 * 本地服务 id 分配器：**单调递增，到顶回绕**，跳过还活着的和刚死不久的。
 *
 * ## 为什么需要
 *
 * 24 位 id 原来只增不收：16,777,215 次 spawn 之后 `本地服务 id 用尽`，此后这个进程
 * 永久不能再建服务（实测 2026-11-25：22.3 秒打满）。对"一局一房间服"的游戏，
 * 按每秒开 10 个房间算约 19 天——到期表现是**新房间开不出来**，只能重启。
 *
 * ## 为什么是"回绕"而不是"空闲表"
 *
 * 直觉的做法是把杀掉的 id 放进空闲表、下次优先取。那是最糟的选择：低号 id 被反复
 * 复用，一个服务死后几毫秒它的 id 就给了别人，而别处（另一个服务的字段、对端节点、
 * 一张还没过期的租约）存的旧地址会**指向新服务**——消息投错对象，且没有任何报错。
 * 这类问题叫 ABA，地址里没有代际号就挡不住。
 *
 * 回绕没有这个问题：id 仍然只增，到顶才绕回 1。一个 id 从"死"到"被复用"隔着
 * 整整一轮地址空间——按每秒 1000 次 spawn 算约 4.6 小时，按每秒 100 次算约 46 小时。
 * 存了那么久还对得上的旧地址，在任何设计下都是垃圾。
 *
 * ## 最小宽限期
 *
 * 回绕的间隔取决于 spawn 速率，极端高频下（压测、脚本死循环重建）可能只有几十秒。
 * 所以再加一道兜底：最近被释放的 id 记在一个小环里，`$graceMs` 内不分配。
 * 环只保留最近 4096 个——正常业务到不了那么高的 kill 频率，到了也只是少一层保护，
 * 回绕本身的间隔仍然在。
 *
 * ## 不能保证什么
 *
 * 宽限期挡不住"活人对着旧地址发消息"：绕回之后同一个 id 确实会是另一个服务。
 * 需要绝对不混淆的调用方，应该按名字重新解析地址（注册表），而不是把地址存起来长期用。
 */
final class IdAllocator
{
    /** 最近释放记录的环长 */
    private const DEAD_RING = 4096;

    private int $max;

    private int $graceMs;

    /** 下一个候选 id */
    private int $next = 1;

    /** 是否已经绕回过（绕回之前，id 都是没用过的，不需要查释放记录） */
    private bool $wrapped = false;

    private int $wraps = 0;

    /** @var array<int, int> id => 释放时刻（单调毫秒） */
    private array $deadAt = [];

    /** @var array<int, int> 按释放先后排列的 id，用于淘汰环里最老的 */
    private array $deadOrder = [];

    private int $deadHead = 0;

    public function __construct(int $max, int $graceMs = 5000)
    {
        $this->max = $max;
        $this->graceMs = $graceMs > 0 ? $graceMs : 0;
    }

    /** 设置最小宽限期（毫秒）。只影响之后的分配，已经绕回过的也适用 */
    public function setGraceMs(int $ms): void
    {
        $this->graceMs = $ms > 0 ? $ms : 0;
    }

    public function wraps(): int
    {
        return $this->wraps;
    }

    /** 下一个候选 id；到顶之前它就是"还剩多少个没用过" */
    public function next(): int
    {
        return $this->next;
    }

    /** 还没用过的 id 个数（绕回之后为 0） */
    public function left(): int
    {
        if ($this->wrapped) {
            return 0;
        }
        $n = $this->max - $this->next + 1;
        return $n > 0 ? $n : 0;
    }

    /** 宽限期记录环里当前的条目数（上限 DEAD_RING）；给测试和自省用 */
    public function trackedReleases(): int
    {
        return count($this->deadAt);
    }

    /**
     * 取一个可用 id。
     *
     * @param \Closure $isLive function (int $id): bool 这个 id 上是否还有活着的服务
     * @throws \RuntimeException 地址空间里已经没有可用的 id
     */
    public function acquire(int $nowMs, \Closure $isLive): int
    {
        // 最多试 max 次：每个 id 最多被否决一次，再往下就是整个空间都不可用
        $tries = 0;
        while ($tries < $this->max) {
            $id = $this->next;
            if ($id > $this->max) {
                $id = 1;
                $this->wrapped = true;
                $this->wraps++;
            }
            $this->next = $id + 1;
            $tries++;
            if ($isLive($id)) {
                continue;
            }
            if ($this->wrapped && isset($this->deadAt[$id])) {
                if ($nowMs - $this->deadAt[$id] < $this->graceMs) {
                    continue;
                }
                unset($this->deadAt[$id]);
            }
            return $id;
        }
        throw new \RuntimeException(
            "service: 本地服务 id 用尽（" . $this->max . " 个 id 都被占用或在宽限期内）"
        );
    }

    /** 服务销毁时调用：记下释放时刻，供回绕后的宽限期判断 */
    public function release(int $id, int $nowMs): void
    {
        if (isset($this->deadAt[$id])) {
            return;
        }
        $this->deadAt[$id] = $nowMs;
        $this->deadOrder[] = $id;
        if (count($this->deadOrder) - $this->deadHead > self::DEAD_RING) {
            $old = $this->deadOrder[$this->deadHead];
            $this->deadHead++;
            unset($this->deadAt[$old]);
            // 整个数组搬一次，摊还 O(1)；否则 head 会一直涨
            if ($this->deadHead >= self::DEAD_RING) {
                $this->deadOrder = array_slice($this->deadOrder, $this->deadHead);
                $this->deadHead = 0;
            }
        }
    }
}
