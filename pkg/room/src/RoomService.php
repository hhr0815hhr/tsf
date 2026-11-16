<?php

namespace TSF\Room;

use TSF\FrameLoop\FrameLoop;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 把 Room 挂到一个 Service 上，并用 FrameLoop 驱动帧。
 *
 * 这是"房间 + 帧循环"的胶水层：想开箱即用的直接使用它；
 * 想让房间按别的方式推进（棋牌用事件驱动、回合制用超时推进），
 * 就只用 `Room` 不用本类——所以帧循环依赖是可以关掉的（构造参数 autoFrame=false）。
 *
 * 房间的状态只由自己的消息驱动，天然串行、不需要锁——这是消息模型的直接红利。
 */
final class RoomService extends Service
{
    public const MSG_JOIN = 3001;

    public const MSG_LEAVE = 3002;

    public const MSG_START = 3003;

    public const MSG_FINISH = 3004;

    public const MSG_TICK = 3005;

    public const MSG_CLOSE = 3006;

    /** 操作结果回包（ok / reject） */
    public const MSG_RESULT = 3100;

    /** 帧间隔（毫秒） */
    public const FRAME_MS = 50;

    /** 单帧 delta 上限：卡顿后不要一次把实体推太远 */
    public const MAX_DELTA_MS = 200;

    private Room $room;

    private ?RoomManager $manager;

    private ?FrameLoop $loop = null;

    private bool $closed = false;

    /** 是否自动开帧循环。棋牌类可以传 false，只拿房间状态机 */
    private bool $autoFrame;

    public function __construct(int $roomId, int $maxPlayers = 8, ?RoomManager $manager = null, bool $autoFrame = true)
    {
        $this->room = new Room($roomId, $maxPlayers);
        $this->manager = $manager;
        $this->autoFrame = $autoFrame;
    }

    public function room(): Room
    {
        return $this->room;
    }

    public function loop(): ?FrameLoop
    {
        return $this->loop;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $loop = $this->loop;
        return [
            "ticks" => $loop === null ? 0 : $loop->ticks(),
            "last_tick_us" => $loop === null ? 0 : $loop->lastUs(),
            "max_tick_us" => $loop === null ? 0 : $loop->maxUs(),
            "frame" => $this->room->frame(),
            "players" => $this->room->playerCount(),
            "state" => $this->room->state(),
        ];
    }

    public function onStart(): void
    {
        if (!$this->autoFrame) {
            return;
        }
        $this->loop = new FrameLoop($this, self::MSG_TICK, self::FRAME_MS, self::MAX_DELTA_MS);
        $this->loop->start();
    }

    public function onStop(): void
    {
        // 定时器由 Scheduler 在 kill 时统一撤销，这里只负责把房间状态收尾
        if (!$this->closed) {
            $this->closed = true;
            $this->room->close();
        }
    }

    public function onMessage(Message $m): void
    {
        // 必须先取到局部变量再 switch：直接 `switch ($m->msgId)` 会让 AOT 生成
        // `php::Int x = m.attr(...)`，而 attr() 返回 Variant，编译期报
        // "cannot convert php::Variant to php::Int"（见 README §5.9）。
        $msgId = $m->msgId;
        switch ($msgId) {
            case self::MSG_JOIN:
                $uid = is_string($m->data) ? (int) $m->data : 0;
                // 只登记成员。坐标是可选的，需要坐标的玩法自己管（见 EntitySet）
                $ok = $uid > 0 && $this->room->add($uid);
                $this->reply($m, $ok ? "ok" : "reject");
                break;

            case self::MSG_LEAVE:
                $uid = is_string($m->data) ? (int) $m->data : 0;
                $ok = $this->room->remove($uid);
                $this->reply($m, $ok ? "ok" : "reject");
                // 房间空了就回收：否则空闲房间会一直占着定时器和内存
                if ($this->room->isEmpty()) {
                    $this->shutdown();
                }
                break;

            case self::MSG_START:
                $this->reply($m, $this->room->start() ? "ok" : "reject");
                break;

            case self::MSG_FINISH:
                $this->reply($m, $this->room->finish() ? "ok" : "reject");
                break;

            case self::MSG_TICK:
                $this->onFrame();
                break;

            case self::MSG_CLOSE:
                $this->shutdown();
                break;
        }
    }

    /** 一帧：算真实 delta、推进房间时钟、记录耗时 */
    private function onFrame(): void
    {
        $loop = $this->loop;
        if ($loop === null) {
            // 没开自动帧（棋牌模式），外部却发了帧消息：忽略而不是崩
            return;
        }
        $delta = $loop->beginFrame();
        $this->room->tick($delta);
        $loop->endFrame();

        // 结算完且没人了：顺手回收
        if ($this->room->state() === Room::SETTLED && $this->room->isEmpty()) {
            $this->shutdown();
        }
    }

    private function reply(Message $m, string $text): void
    {
        if ($m->from === 0) {
            return; // 系统/测试直发，没有回包对象
        }
        Scheduler::instance()->deliver($m->from, self::MSG_RESULT, $text, $m->session);
    }

    /** 关房：撤销自己的定时器（由 kill 负责）并从管理器摘掉 */
    private function shutdown(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->room->close();
        if ($this->manager !== null) {
            $this->manager->onClosed($this->room->id());
        }
        Scheduler::instance()->kill($this->addr());
    }
}
