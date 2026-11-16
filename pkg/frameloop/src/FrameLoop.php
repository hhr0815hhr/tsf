<?php

namespace TSF\FrameLoop;

use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 帧循环：按固定间隔给自己的服务投一条"该跑一帧了"的消息，并算出**可变 delta**。
 *
 * 为什么单独成包：需要帧循环的玩法（MOBA/射击/MMO 的场景）和需要房间的玩法
 * 不是同一批。棋牌有房间但没有帧；SLG 有格子但没有 50ms 帧。两者交叉，所以拆开。
 *
 * 为什么 delta 用真实经过时间而不是固定 frameMs：
 *   进程卡了 150ms，下一帧的 delta 就该是 150，位置按真实时间推进，
 *   否则游戏内时间会比真实时间慢。上限 maxDeltaMs 是为了卡顿后不让实体一次瞬移太远
 *   （宁可丢掉一段时间，也不要穿过墙体）。
 *
 * 为什么是"定时投消息"而不是在 onMessage 里 while 循环：
 *   后者会一直占着调度器，同一个进程里别的服务（网络、其他房间）全排不上。
 *   走消息，每帧之间自然回到调度器，公平性交给 budget。
 */
final class FrameLoop
{
    private Service $host;

    private int $msgId;

    private int $frameMs;

    private int $maxDeltaMs;

    private int $lastMs = 0;

    private int $timerId = 0;

    private int $frameStart = 0;

    private int $ticks = 0;

    private int $lastUs = 0;

    private int $maxUs = 0;

    public function __construct(Service $host, int $tickMsgId, int $frameMs = 50, int $maxDeltaMs = 200)
    {
        if ($frameMs <= 0) {
            throw new \RuntimeException("frameloop: frameMs 必须 > 0");
        }
        $this->host = $host;
        $this->msgId = $tickMsgId;
        $this->frameMs = $frameMs;
        $this->maxDeltaMs = $maxDeltaMs > 0 ? $maxDeltaMs : $frameMs;
    }

    /** 开始投帧，返回定时器 id */
    public function start(): int
    {
        $this->lastMs = Scheduler::nowMs();
        $this->timerId = $this->host->interval($this->frameMs, $this->msgId);
        return $this->timerId;
    }

    /** 停止投帧（服务销毁时定时器会被 Scheduler 自动撤销，这里是主动停用） */
    public function stop(): bool
    {
        if ($this->timerId <= 0) {
            return false;
        }
        $ok = $this->host->cancelTimer($this->timerId);
        $this->timerId = 0;
        return $ok;
    }

    public function isRunning(): bool
    {
        return $this->timerId > 0;
    }

    public function frameMs(): int
    {
        return $this->frameMs;
    }

    /**
     * 收到帧消息时先调它：返回本帧真实 delta（毫秒），并开始计时。
     */
    public function beginFrame(): int
    {
        $now = Scheduler::nowMs();
        $delta = $now - $this->lastMs;
        $this->lastMs = $now;
        if ($delta < 0) {
            $delta = 0;
        }
        if ($delta > $this->maxDeltaMs) {
            $delta = $this->maxDeltaMs;
        }
        $this->frameStart = hrtime(true);
        return $delta;
    }

    /** 业务 tick 结束后调它：记录本帧耗时，返回微秒数 */
    public function endFrame(): int
    {
        $us = intdiv(hrtime(true) - $this->frameStart, 1000);
        $this->lastUs = $us;
        if ($us > $this->maxUs) {
            $this->maxUs = $us;
        }
        $this->ticks++;
        return $us;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            "ticks" => $this->ticks,
            "last_us" => $this->lastUs,
            "max_us" => $this->maxUs,
        ];
    }

    public function ticks(): int
    {
        return $this->ticks;
    }

    public function maxUs(): int
    {
        return $this->maxUs;
    }

    public function lastUs(): int
    {
        return $this->lastUs;
    }
}
