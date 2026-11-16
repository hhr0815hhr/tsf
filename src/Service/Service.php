<?php

namespace TSF\Service;

/**
 * 服务基类。房间、玩家、匹配器、全局单例……都是它的子类。
 *
 * 三条规矩（决定了整个并发模型）：
 *
 * 1. **不共享内存。** 服务之间只能发消息，不能互相持有对象引用。
 *    直接调用会产生隐式的跨服务依赖和竞态，而消息模型把这些变成显式的、可追踪的边。
 * 2. **单线程执行。** 一个服务的 `onMessage` 永远不会与自己的另一次执行并发，
 *    所以服务内部的状态不需要锁——这是它最大的价值。
 * 3. **本地/远程同构。** 地址里带节点号，`Scheduler::send()` 对两者写法一致，
 *    将来把服务拆到别的进程不用改业务代码。
 *
 * 不要在这里放任何 per-message 的统计（计数、时间戳）：这些应该放在压测端，
 * 否则每条消息都要多付几百纳秒。
 */
abstract class Service
{
    private int $addr = 0;

    /** 由 Scheduler 在 spawn 时注入 */
    public function bindAddr(int $addr): void
    {
        $this->addr = $addr;
    }

    public function addr(): int
    {
        return $this->addr;
    }

    /** 启动钩子：可以在这里注册定时器、加载数据 */
    public function onStart(): void
    {
    }

    /** 销毁钩子：清理外部资源。积压消息由 Scheduler 负责丢弃 */
    public function onStop(): void
    {
    }

    /** 处理一条消息。同一个服务的调用是串行的，可以放心读写自身状态 */
    abstract public function onMessage(Message $m): void;

    /**
     * 以自己身份发一条消息。
     *
     * 在 `onMessage` 里可以直接用 `Scheduler::instance()->deliver()`（更快，省两次调用）；
     * 这个方法是给"服务之外"的场景用的——比如在 main 里手动触发一次、
     * 或者在 onStart 里给别的服务打招呼，那时调度器不知道当前是谁。
     */
    public function send(int $to, int $msgId, string|array|object $data, int $session = 0): bool
    {
        return Scheduler::instance()->deliverAs($this->addr, $to, $msgId, $data, $session);
    }

    // ---------------- 定时器便捷入口 ----------------
    // 定时器到期也是投一条消息，所以这些方法只是"给自己定个闹钟"，
    // 处理仍然走 onMessage，不需要额外的回调注册。

    /** $ms 之后给自己投一条消息 */
    public function timeout(int $ms, int $msgId, string|array|object $data = ""): int
    {
        return Scheduler::instance()->timeout($this->addr, $ms, $msgId, $data);
    }

    /** 每 $ms 给自己投一条消息 */
    public function interval(int $ms, int $msgId, string|array|object $data = ""): int
    {
        return Scheduler::instance()->interval($this->addr, $ms, $msgId, $data);
    }

    /** 按 cron 表达式给自己投消息（分钟粒度） */
    public function every(string $cron, int $msgId, string|array|object $data = ""): int
    {
        return Scheduler::instance()->every($cron, $this->addr, $msgId, $data);
    }

    public function cancelTimer(int $timerId): bool
    {
        return Scheduler::instance()->cancelTimer($timerId);
    }
}
