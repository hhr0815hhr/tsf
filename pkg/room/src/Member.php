<?php

namespace TSF\Room;

/**
 * 房间成员：**纯数据，不是基类**。
 *
 * ## 为什么不用继承表达"不同身份"
 *
 * 直觉写法是 `class Player extends Member`、`class Npc extends Member`。
 * 在这个运行时里实测（`bench/probe-actor`，1000 个成员遍历一次）：
 *
 * | 写法 | 成本 |
 * |:--|--:|
 * | 抽象基类 + 虚方法派发 | 181 ns/个 |
 * | 抽象基类 + `instanceof` 取子类字段 | 221 ns/个 |
 * | **单类 + role 字段** | **112 ns/个** |
 * | 造一个子类实例 | 273 ns |
 * | 造一个单类实例 | **46 ns** |
 *
 * 多态在 AOT 下能用，但每一项都贵 1.6~6 倍。更要命的是**维度会爆炸**：
 * 玩家/ NPC × 占座/观战 × 有状态机/无状态机，四个维度就是十几个子类，
 * 而其中大部分组合根本不存在。
 *
 * ## 房间里对成员做的事，其实只有"判权"
 *
 * 谁能操作、谁占席位、谁只是看着——这些都是**读一个字段**就能答的问题，
 * 不需要虚派发。真正需要"不同行为"的是 NPC 的 AI、玩家的输入处理，
 * 那些是 Agent/System 的事，不该塞进成员表。
 *
 * 所以：**身份是数据（role），行为在别处。**
 *
 * 需要给某类身份挂额外数据时，用旁表（`array<int, YourData>`）按 uid 索引，
 * 而不是往这个类上加字段——否则所有身份都背上不属于自己的字段。
 */
final class Member
{
    /** 占席位的玩家 */
    public const PLAYER = 1;

    /** NPC/机器人：占席位，但不受客户端驱动 */
    public const NPC = 2;

    /** 观战：**不占席位**，人数不限 */
    public const SPECTATOR = 3;

    public int $uid;

    public int $role;

    /** 席位号；观战者为 -1 */
    public int $seat;

    /** 展示名，空表示用 uid */
    public string $name;

    public function __construct(int $uid, int $role = self::PLAYER, string $name = "")
    {
        $this->uid = $uid;
        $this->role = $role;
        $this->seat = -1;
        $this->name = $name;
    }

    public function isPlayer(): bool
    {
        return $this->role === self::PLAYER;
    }

    public function isSpectator(): bool
    {
        return $this->role === self::SPECTATOR;
    }

    /** 占不占席位——容量只约束占席位的人 */
    public function occupiesSeat(): bool
    {
        return $this->role !== self::SPECTATOR;
    }

    public function displayName(): string
    {
        if ($this->name !== "") {
            return $this->name;
        }
        return "#" . $this->uid;
    }
}
