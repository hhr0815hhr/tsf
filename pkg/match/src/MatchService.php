<?php

namespace TSF\Match;

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 把 MatchQueue 挂到 Service 上：定时扫描 + 消息进出。
 *
 * 单服务串行处理，队列不需要锁——这是消息模型的直接红利。
 *
 * 不同玩法/不同评分口径要用**不同实例**，不要混在一个队列里：
 * 青铜和王者的分差没有可比性，混装会把两边都配得很难看。
 */
final class MatchService extends Service
{
    public const MSG_JOIN = 4001;

    public const MSG_LEAVE = 4002;

    /** 触发一轮扫描（自动定时或外部投递） */
    public const MSG_TICK = 4003;

    /** 入队/离队结果：ok / reject */
    public const MSG_RESULT = 4100;

    private MatchQueue $queue;

    /** 扫描间隔（毫秒）；0 表示不自动扫，完全由外部投 MSG_TICK */
    private int $intervalMs;

    /** @var \Closure|null */
    private ?\Closure $onMatched = null;

    public function __construct(int $baseTolerance = 50, int $expandPerSec = 50, int $intervalMs = 100)
    {
        $this->queue = new MatchQueue($baseTolerance, $expandPerSec);
        $this->intervalMs = $intervalMs >= 0 ? $intervalMs : 0;
    }

    /**
     * 配对成功回调：`$cb(int $uidA, int $uidB)`。
     *
     * 只回调不建房——"配对之后干什么"因玩法而异（1v1 直接开房、5v5 要攒满、
     * 棋牌还要发桌号），硬编码在匹配器里就等于把它和房间绑死了。
     */
    public function onMatched(callable $cb): void
    {
        $this->onMatched = $cb;
    }

    public function onStart(): void
    {
        if ($this->intervalMs > 0) {
            $this->interval($this->intervalMs, self::MSG_TICK);
        }
    }

    public function onMessage(Message $m): void
    {
        // 先取局部变量再 switch：直接对 $m->msgId 做 switch 会让 AOT 生成
        // Variant → Int 的非法转换（README §5.9）
        $msgId = $m->msgId;

        if ($msgId === self::MSG_JOIN) {
            $uid = 0;
            $score = 0;
            $d = $m->data;
            if (is_array($d)) {
                $uid = isset($d["uid"]) ? (int) $d["uid"] : 0;
                $score = isset($d["score"]) ? (int) $d["score"] : 0;
            }
            $ok = $uid > 0 && $this->queue->add($uid, $score, Scheduler::nowMs());
            $this->reply($m, $ok ? "ok" : "reject");
            return;
        }

        if ($msgId === self::MSG_LEAVE) {
            $uid = is_string($m->data) ? (int) $m->data : 0;
            $this->reply($m, $this->queue->remove($uid) ? "ok" : "reject");
            return;
        }

        if ($msgId === self::MSG_TICK) {
            $this->drain();
        }
    }

    /** 扫一轮，把配对结果交出去 */
    private function drain(): void
    {
        $pairs = $this->queue->tryMatch(Scheduler::nowMs());
        if ($pairs === []) {
            return;
        }
        $cb = $this->onMatched;
        if ($cb === null) {
            return;
        }
        foreach ($pairs as $p) {
            $cb($p[0], $p[1]);
        }
    }

    private function reply(Message $m, string $text): void
    {
        if ($m->from === 0) {
            return; // 系统/测试直发，没有回包对象
        }
        Scheduler::instance()->deliver($m->from, self::MSG_RESULT, $text, $m->session);
    }

    /** 直接访问队列——测试和"还要按 uid 查状态"的场景用 */
    public function queue(): MatchQueue
    {
        return $this->queue;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->queue->stats();
    }
}
