<?php

namespace TSF\Room;

use TSF\Fsm\Machine;

/**
 * 「等待 → 进行 → 结算」三段式状态机。
 *
 * 这是**一个可选的辅助实现**，不是框架语义。以前它被硬写在 `Room` 里（连同四个常量），
 * 结果是"所有玩法都得是这个形状"——斗地主多一段叫地主就撞墙，麻将多一轮换庄也撞墙。
 *
 * 现在 `Room` 不认识任何状态，只接受一个注入的 `Machine`。用不用这个类完全由业务决定：
 *
 * ```php
 * // 用默认三段式
 * $room = new Room(1, 4, ThreeStage::fsm());
 * $room->fsm()->fire("start");
 *
 * // 用自己的状态
 * $fsm = new Machine(DdzState::Waiting->value);
 * $fsm->allow(DdzState::Waiting->value, "call", DdzState::Calling->value);
 * $room = new Room(1, 3, $fsm);
 * ```
 */
final class ThreeStage
{
    public const WAITING = 1;
    public const PLAYING = 2;
    public const SETTLED = 3;
    public const CLOSED = 4;

    /** 造一台配好转移的状态机。每次调用返回新实例——状态机有状态，不能共用 */
    public static function fsm(): Machine
    {
        $fsm = new Machine(self::WAITING);
        $fsm->allow(self::WAITING, "start", self::PLAYING)
            ->allow(self::PLAYING, "finish", self::SETTLED)
            // close 从任何非 CLOSED 状态都允许，所以三条边都要写——
            // 这比在 close() 里写 `if ($state !== CLOSED)` 更能表达"关闭是全局可用的"
            ->allow(self::WAITING, "close", self::CLOSED)
            ->allow(self::PLAYING, "close", self::CLOSED)
            ->allow(self::SETTLED, "close", self::CLOSED);
        return $fsm;
    }

    /** 注册好状态名，日志里能看到 waiting/playing 而不是 state1/state2 */
    public static function room(int $id, int $maxPlayers): Room
    {
        $room = new Room($id, $maxPlayers, self::fsm());
        $room->setStateName(self::WAITING, "waiting");
        $room->setStateName(self::PLAYING, "playing");
        $room->setStateName(self::SETTLED, "settled");
        $room->setStateName(self::CLOSED, "closed");
        return $room;
    }
}
