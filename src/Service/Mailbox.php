<?php

namespace TSF\Service;

/**
 * 服务信箱：单消费者 FIFO。
 *
 * 实现是「数组 + 头指针」，不是 `SplQueue` 也不是 `array_shift`。实测（AOT）：
 *
 *   array + 头指针   54 ns/条   ← 采用
 *   SplQueue        178 ns/条
 *   array_shift    12,299 ns/条 @1万；351,666 ns/条 @10万（O(n) 搬移，绝对不能用）
 *
 * 队列排空后把数组整个丢掉重置，避免反复 push/pop 让底层哈希表无限增长。
 * 不需要额外的 lock：单进程内由 Scheduler 串行驱动，没有并发写。
 */
final class Mailbox
{
    /** @var array<int, Message> */
    private array $items = [];

    private int $head = 0;

    private int $size = 0;

    /**
     * 是否已在调度器的就绪队列里。
     *
     * 放在信箱上而不是 Scheduler 里另开一个 `inReady[addr] => bool` 数组：
     * 省掉每帧一次哈希查找（约 200 ns），也不需要两处状态同步。
     */
    public bool $queued = false;

    /**
     * 积压上限。超过就丢弃新消息并返回 false。
     *
     * 为什么必须有上限：消费慢或消息成环（A 发 B、B 又发 A）时，队列会一路涨到 OOM，
     * 而进程是被"合法代码"杀死的，日志里什么都看不到。宁可丢消息并计数，也不要 OOM。
     * 256k 条大约对应几十 MB，到了这个量级说明上游已经出问题，丢弃是正确响应。
     */
    private int $limit = 262144;

    public function setLimit(int $n): void
    {
        $this->limit = $n > 0 ? $n : 0;
    }

    /** @return bool false 表示已满被丢弃 */
    public function push(Message $m): bool
    {
        if ($this->size >= $this->limit) {
            return false;
        }
        $this->items[] = $m;
        $this->size++;
        return true;
    }

    /** 取出队首；空队列返回 null */
    public function pop(): ?Message
    {
        if ($this->size === 0) {
            return null;
        }
        $m = $this->items[$this->head];
        $this->head++;
        $this->size--;
        if ($this->size === 0) {
            // 一次排空就释放，别让数组长度一直涨
            $this->items = [];
            $this->head = 0;
        }
        return $m;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    /** 丢弃全部积压（服务被销毁时用），返回丢弃条数 */
    public function clear(): int
    {
        $n = $this->size;
        $this->items = [];
        $this->head = 0;
        $this->size = 0;
        $this->queued = false;
        return $n;
    }
}
