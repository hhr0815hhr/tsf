<?php

namespace TSF\Timer;

/**
 * 二叉最小堆（数组实现）。
 *
 * 为什么不用 `SplPriorityQueue`：
 *   - 它不支持"按 id 撤销"，只能靠惰性删除，而它连"拿到堆顶但不出堆"都不方便；
 *   - 优先级相同的元素出堆顺序由内部实现决定，我们要求同一毫秒内按注册先后（FIFO），
 *     否则同一时刻的一批定时器顺序不确定，测试会变得不可重复。
 *
 * 顺序规则：先比 dueAt，再比 id（id 单调递增 = 注册先后）。
 */
final class TimerHeap
{
    /** @var array<int, Timer> */
    private array $items = [];

    public function size(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** 看一眼最早到期时间，不出堆 */
    public function peek(): ?Timer
    {
        return $this->items[0] ?? null;
    }

    public function push(Timer $t): void
    {
        $this->items[] = $t;
        $this->up(count($this->items) - 1);
    }

    public function pop(): ?Timer
    {
        $n = count($this->items);
        if ($n === 0) {
            return null;
        }
        $top = $this->items[0];
        if ($n === 1) {
            // 必须整体赋 [] 而不是 unset($items[0])：
            // PHP 的数组内部"下一个可用下标"不会因 unset 复位，
            // 之后 $items[] = $x 会落到下标 1，而 peek() 读的是下标 0 → 堆"凭空空了"。
            $this->items = [];
            return $top;
        }
        $last = $this->items[$n - 1];
        unset($this->items[$n - 1]);
        $this->items[0] = $last;
        $this->down(0);
        // 堆很小时整体重排 key。
        //
        // 为什么必须做：unset 不会复位 PHP 数组的"下一个可用下标"，下次 push
        // 会落到比 count 更大的 key 上。反复 push/pop 于是让 key 空间只增不减，
        // 哈希表的桶跟着涨（每个槽约 36 B），元素数却一直是 0~n。
        // 实测 10 轮 × 2000 个定时器涨到 6 MB 就是这个原因（见 bench/lifecycle）。
        //
        // 只在 n 很小时做：array_values 是 O(n)，堆大时每次都做会把 pop 从
        // O(log n) 退化成 O(n)。小堆重建的累计成本可以忽略，而 key 空间
        // 被限制在"历史最大堆大小"，不会随累计 push 次数增长。
        if ($n <= 32) {
            $this->items = array_values($this->items);
        }
        return $top;
    }

    /**
     * 排序规则：先比 dueAt，再比 id（id 递增 = 注册先后，保证同刻 FIFO）。
     *
     * 注意：up/down 里**内联**了这个比较，没有抽成方法。AOT 下一次方法调用约 240 ns，
     * 堆每次调整要比较 ~log2(n) 次，10 万定时器时单个 push 会因此多花 3~4 µs
     * （实测注册从 1.6 µs 涨到 5.6 µs）——抽方法的代价在这个位置完全不值得。
     */
    private function up(int $i): void
    {
        while ($i > 0) {
            $parent = ($i - 1) >> 1;
            $a = $this->items[$i];
            $b = $this->items[$parent];
            // 内联 less()：先比 dueAt，再比 id（id 递增 = 注册先后，保证同刻 FIFO）
            $before = $a->dueAt !== $b->dueAt ? $a->dueAt < $b->dueAt : $a->id < $b->id;
            if (!$before) {
                return;
            }
            $this->items[$i] = $b;
            $this->items[$parent] = $a;
            $i = $parent;
        }
    }

    private function down(int $i): void
    {
        $n = count($this->items);
        while (true) {
            $l = ($i << 1) + 1;
            if ($l >= $n) {
                return;
            }
            $r = $l + 1;
            $small = $l;
            if ($r < $n) {
                $a = $this->items[$r];
                $b = $this->items[$l];
                $rBefore = $a->dueAt !== $b->dueAt ? $a->dueAt < $b->dueAt : $a->id < $b->id;
                if ($rBefore) {
                    $small = $r;
                }
            }
            $cur = $this->items[$i];
            $sm = $this->items[$small];
            $smBefore = $sm->dueAt !== $cur->dueAt ? $sm->dueAt < $cur->dueAt : $sm->id < $cur->id;
            if (!$smBefore) {
                return;
            }
            $this->items[$i] = $sm;
            $this->items[$small] = $cur;
            $i = $small;
        }
    }
}
