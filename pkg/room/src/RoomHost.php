<?php

namespace TSF\Room;

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 共享宿主：多个小房间共用一个 Service、一个定时器、一帧 delta。
 *
 * 为什么需要它：一房一 Service 时，5,000 个房间就是 5,000 个服务 + 5,000 个
 * 50 ms 定时器——每秒 10 万次定时器出槽，再加上每个房间各自的 mailbax 派发。
 * 合并之后这些固定开销摊到一份。
 *
 * 什么时候**不该**用它：房间里有重逻辑（物理、AOI、大量实体）时，一个房间的
 * 慢帧会拖累同宿主的其他房间——没有隔离。那种房间应该独占 Service。
 *
 * 所以承载方式是**配置项**，不是二选一：
 *   小房间（棋牌、回合制、无实时位置）→ 共享
 *   大房间（MMO 副本、MOBA、实体多）  → 独占
 */
class RoomHost extends Service
{
    public const MSG_ADD = 3201;

    public const MSG_DROP = 3202;

    public const MSG_TICK = 3203;

    /** 转发给某个房间的操作，data = [room, op, data] */
    public const MSG_TO_ROOM = 3204;

    /** 单帧 delta 上限：卡顿后不要一次把实体推太远 */
    public const MAX_DELTA_MS = 200;

    /** @var array<int, Room> roomId => Room */
    protected array $rooms = [];

    protected int $frameMs;

    protected int $maxRooms;

    protected ?RoomManager $manager;

    protected int $lastTickMs = 0;

    protected int $ticks = 0;

    protected int $maxTickUs = 0;

    protected int $maxRoomsSeen = 0;

    protected int $reaped = 0;

    public function __construct(?RoomManager $manager = null, int $frameMs = 50, int $maxRooms = 200)
    {
        $this->manager = $manager;
        $this->frameMs = $frameMs > 0 ? $frameMs : 50;
        $this->maxRooms = $maxRooms > 0 ? $maxRooms : 200;
    }

    public function onStart(): void
    {
        $this->lastTickMs = Scheduler::nowMs();
        $this->interval($this->frameMs, self::MSG_TICK);
    }

    public function onMessage(Message $m): void
    {
        $msgId = $m->msgId;

        if ($msgId === self::MSG_TICK) {
            $this->tickAll();
            return;
        }

        if ($msgId === self::MSG_ADD) {
            $d = $m->data;
            if (!is_array($d)) {
                return;
            }
            $roomId = isset($d["room"]) ? (int) $d["room"] : 0;
            $max = isset($d["max"]) ? (int) $d["max"] : 8;
            if ($roomId <= 0 || isset($this->rooms[$roomId])) {
                return;
            }
            if (count($this->rooms) >= $this->maxRooms) {
                // 满了就拒绝。管理器自己统计每个宿主的房间数，满了会先开新宿主，
                // 所以正常路径走不到这里——这是防御，不是主逻辑。
                return;
            }
            $this->rooms[$roomId] = ThreeStage::room($roomId, $max);
            return;
        }

        if ($msgId === self::MSG_DROP) {
            $roomId = is_string($m->data) ? (int) $m->data : 0;
            $this->reap($roomId);
            return;
        }

        if ($msgId === self::MSG_TO_ROOM) {
            $d = $m->data;
            if (!is_array($d)) {
                return;
            }
            $roomId = isset($d["room"]) ? (int) $d["room"] : 0;
            $op = isset($d["op"]) ? (int) $d["op"] : 0;
            $payload = isset($d["data"]) ? $d["data"] : "";
            $this->dispatch($roomId, $op, $payload);
        }
    }

    /** 一帧：算一次 delta，推进所有房间 */
    protected function tickAll(): void
    {
        $now = Scheduler::nowMs();
        $delta = $now - $this->lastTickMs;
        $this->lastTickMs = $now;
        if ($delta < 0) {
            $delta = 0;
        }
        if ($delta > self::MAX_DELTA_MS) {
            $delta = self::MAX_DELTA_MS;
        }

        $t0 = hrtime(true);
        $dead = [];
        // 不用 foreach 的 key：AOT 里 key 是 php::Var，赋给 Int 变量会报
        // "Cannot assign value to variable of type php::Int with type php::Var"。
        // 直接用 $room->id() 拿 int 更省事（README §5.9 同类问题）。
        foreach ($this->rooms as $room) {
            $room->tick($delta);
            if ($room->state() === ThreeStage::SETTLED && $room->isEmpty()) {
                $dead[] = $room->id();
            }
        }
        $us = intdiv(hrtime(true) - $t0, 1000);

        $this->ticks++;
        if ($us > $this->maxTickUs) {
            $this->maxTickUs = $us;
        }
        $n = count($this->rooms);
        if ($n > $this->maxRoomsSeen) {
            $this->maxRoomsSeen = $n;
        }

        foreach ($dead as $id) {
            $this->reap($id);
        }
    }

    protected function dispatch(int $roomId, int $op, string|array|object $payload): void
    {
        if (!isset($this->rooms[$roomId])) {
            return;
        }
        $room = $this->rooms[$roomId];

        if ($op === RoomManager::OP_JOIN) {
            $uid = is_string($payload) ? (int) $payload : 0;
            if ($uid > 0) {
                // 共享宿主只登记成员；坐标由玩法层自己的 EntitySet 管
                $room->add($uid);
            }
            return;
        }

        if ($op === RoomManager::OP_LEAVE) {
            $uid = is_string($payload) ? (int) $payload : 0;
            $room->remove($uid);
            if ($room->isEmpty()) {
                $this->reap($roomId);
            }
            return;
        }

        if ($op === RoomManager::OP_START) {
            $room->start();
            return;
        }

        if ($op === RoomManager::OP_FINISH) {
            $room->finish();
            return;
        }

        if ($op === RoomManager::OP_CLOSE) {
            $this->reap($roomId);
        }
    }

    /** 摘掉一个房间：先关状态、再摘表、最后通知管理器（顺序不能反） */
    protected function reap(int $roomId): void
    {
        if (!isset($this->rooms[$roomId])) {
            return;
        }
        $this->rooms[$roomId]->close();
        unset($this->rooms[$roomId]);
        $this->reaped++;
        if ($this->manager !== null) {
            $this->manager->onClosed($roomId);
        }
    }

    public function roomCount(): int
    {
        return count($this->rooms);
    }

    public function full(): bool
    {
        return count($this->rooms) >= $this->maxRooms;
    }

    public function room(int $roomId): ?Room
    {
        return $this->rooms[$roomId] ?? null;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            "rooms" => count($this->rooms),
            "max_rooms_seen" => $this->maxRoomsSeen,
            "ticks" => $this->ticks,
            "max_tick_us" => $this->maxTickUs,
            "reaped" => $this->reaped,
        ];
    }
}
