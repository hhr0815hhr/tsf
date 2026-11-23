<?php

namespace TSF\Room;

use TSF\Fsm\Machine;

/**
 * 房间：成员表 + 一帧计数 + 一个状态值。
 *
 * ## 为什么不内置状态机
 *
 * 早期版本把 `WAITING / PLAYING / SETTLED / CLOSED` 四个常量写在类里，并在构造函数
 * 里预设好转移。问题不是"不够灵活"，而是**方向错了**：不同游戏的状态几乎必然不同——
 * 斗地主有「叫地主」、麻将有多轮换庄、SLG 有建筑队列、MOBA 有 BP 阶段。把这些当框架
 * 语义，等于用一套"通用"状态去套所有玩法，业务只能往里硬塞或者绕开。
 *
 * 现在的分工：
 *
 *   Room        成员表（席位/身份/容量）、帧计数、关闭标记、一个**不解释含义**的状态值
 *   Machine     状态机本体，由**业务注入**并自己配置转移（pkg/fsm）
 *   RoomService 提供一套默认的三段式状态机，作为"开箱即用"的实现，业务可以不用它
 *
 * Room 不判断"什么状态能进人""什么状态该推帧"——那些是业务语义。它只在
 * 明确被要求时做事：`close()` 之后不再收人，其余一概不管。
 */
class Room
{
    protected int $id;

    /** 业务注入的状态机；为 null 时用 setState() 自己维护一个裸状态值 */
    protected ?Machine $fsm = null;

    protected MemberTable $members;

    protected int $frame = 0;

    protected int $elapsedMs = 0;

    protected bool $closed = false;

    /** @var array<int, string> 状态号 → 名字，业务注册 */
    protected array $names = [];

    /** 没有状态机时的裸状态值 */
    protected int $bareState = 0;

    /**
     * @param Machine|null $fsm 业务的状态机。不传则用裸状态值（setState/getState）
     */
    public function __construct(int $id, int $maxPlayers = 8, ?Machine $fsm = null)
    {
        $this->id = $id;
        $this->members = new MemberTable($maxPlayers);
        $this->fsm = $fsm;
        if ($fsm !== null) {
            $this->bareState = $fsm->state();
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    /** 状态机本体；没注入时为 null */
    public function fsm(): ?Machine
    {
        return $this->fsm;
    }

    /** 当前状态值。含义由业务定义，Room 不解释 */
    public function state(): int
    {
        if ($this->fsm !== null) {
            return $this->fsm->state();
        }
        return $this->bareState;
    }

    /** 没有状态机时直接置状态 */
    public function setState(int $state): void
    {
        if ($this->fsm !== null) {
            $this->fsm->force($state);
            return;
        }
        $this->bareState = $state;
    }

    /** 注册状态名（只为日志/调试可读） */
    public function setStateName(int $state, string $name): void
    {
        $this->names[$state] = $name;
    }

    public function stateName(): string
    {
        $s = $this->state();
        return $this->names[$s] ?? ("state" . $s);
    }

    public function members(): MemberTable
    {
        return $this->members;
    }

    public function maxPlayers(): int
    {
        return $this->members->capacity();
    }

    public function playerCount(): int
    {
        return $this->members->occupants();
    }

    public function memberCount(): int
    {
        return $this->members->count();
    }

    public function has(int $uid): bool
    {
        return $this->members->has($uid);
    }

    public function isEmpty(): bool
    {
        return $this->members->isEmpty();
    }

    /**
     * 全部成员 uid（含观战）。
     *
     * 纯委托，和其它成员表访问器（`has()` / `playerCount()` / `isEmpty()`）同类：
     * 不解释任何状态语义，所以可以放在 Room 上。业务需要"遍历房间里的人"时
     * 不该被迫先拿到 MemberTable 再调一次。
     *
     * @return array<int>
     */
    public function uids(): array
    {
        return $this->members->uids();
    }

    /**
     * 加入（玩家身份）。返回 false 的四种原因：
     *
     *   1. 房间已关闭
     *   2. `$uid <= 0`
     *   3. 该 uid 已在房间里（重复加入**不会**刷新成员信息，也不会占第二个座）
     *   4. 席位已满（`maxPlayers`；观战者不占座，见 `Member::occupiesSeat()`）
     *
     * 返回 bool 而不是原因，是因为调用方通常只想"加进去了没有"。要区分，
     * 用 `has($uid)` / `isClosed()` / `playerCount()` 自己判——别猜 false 的含义。
     */
    public function add(int $uid, string $name = ""): bool
    {
        if ($this->closed) {
            return false;
        }
        return $this->members->add($uid, Member::PLAYER, $name) !== null;
    }

    public function addAs(int $uid, int $role, string $name = ""): ?Member
    {
        if ($this->closed) {
            return null;
        }
        return $this->members->add($uid, $role, $name);
    }

    public function remove(int $uid): bool
    {
        return $this->members->remove($uid);
    }

    /**
     * 推进一帧。
     *
     * **调用方负责判断该不该推**（比如只在出牌阶段调）——Room 不知道你的状态语义。
     * 这里只拒绝两件它确实知道的事：房间已关闭、delta 为负。
     *
     * delta 是"距离上一帧的真实耗时"，不是固定 50——这样网络抖动或进程卡顿后
     * 位置不会突然跳变，也不会落后于真实时间。
     */
    public function tick(int $deltaMs): bool
    {
        if ($this->closed) {
            return false;
        }
        if ($deltaMs < 0) {
            $deltaMs = 0;
        }
        $this->frame++;
        $this->elapsedMs += $deltaMs;
        return true;
    }

    public function frame(): int
    {
        return $this->frame;
    }

    public function elapsedMs(): int
    {
        return $this->elapsedMs;
    }

    /**
     * 关房：置关闭标志，并让状态机走 `close`。
     *
     * 为什么这里要 fire 而不是只置标志：`ThreeStage` 从 WAITING / PLAYING / SETTLED
     * 都声明了 `close` 边——如果没人触发，那三条边就是死代码，而且关闭之后的房间
     * 仍然能 `fire("start")`（状态机不知道"关了吗"）。关闭本身是 Room 自己拥有的
     * 生命周期概念（`$closed` 就在这个类里），所以由它触发是合理的。
     *
     * 不清成员：结算、快照、掉线补发都还要按 uid 遍历。要清请显式调 `members()->clear()`。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->fsm !== null) {
            $this->fsm->fire("close");
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return array<string, int|string|float|bool|array> */
    public function toArray(): array
    {
        return [
            "id" => $this->id,
            // 容量必须带上：不带的话恢复时只能猜默认值，
            // 一个 2 人房会被恢复成 8 人房（多出来的人能进，是真正的越权）
            "maxPlayers" => $this->members->capacity(),
            "state" => $this->state(),
            "frame" => $this->frame,
            "elapsedMs" => $this->elapsedMs,
            "closed" => $this->closed ? 1 : 0,
            "members" => $this->members->toArray(),
        ];
    }

    /**
     * 静态工厂：从 `toArray()` 的结果造一个新房间。
     *
     * 和 `loadFrom()` 的分工容易混，这里说清：
     *
     *   loadFrom()  往**已有**房间灌数据，失败条件是"房间不空"；
     *   fromArray() 造**新**房间，失败条件是"快照里没有合法 id"。
     *
     * 快照恢复必须走后者——先 `new Room()` 再 loadFrom 的话，容量已经按默认值
     * 定死了，`maxPlayers` 就丢了。
     *
     * @param array<string, mixed> $d
     * @param Machine|null $fsm 业务的状态机；不传则恢复成裸状态值
     */
    public static function fromArray(array $d, ?Machine $fsm = null): ?Room
    {
        $id = isset($d["id"]) ? (int) $d["id"] : 0;
        if ($id <= 0) {
            return null;
        }
        $max = isset($d["maxPlayers"]) ? (int) $d["maxPlayers"] : 8;
        if ($max <= 0) {
            $max = 8;
        }
        $room = new Room($id, $max, $fsm);
        if (!$room->loadFrom($d)) {
            return null;
        }
        return $room;
    }

    /**
     * 把快照数据灌进这个房间。
     *
     * 只允许灌进一个**全新房间**（没有成员、未关闭）——否则就是"用旧快照覆盖正在跑的
     * 对局"，那是数据事故而不是恢复。要重建就新建一个。
     *
     * @param array<string, mixed> $d
     */
    public function loadFrom(array $d): bool
    {
        if (!$this->members->isEmpty() || $this->closed) {
            return false;
        }

        $state = isset($d["state"]) ? (int) $d["state"] : $this->state();
        $this->setState($state);

        $this->frame = isset($d["frame"]) ? (int) $d["frame"] : 0;
        $this->elapsedMs = isset($d["elapsedMs"]) ? (int) $d["elapsedMs"] : 0;

        // 关闭标志也要恢复：`toArray()` 写了它，不读回来就是"快照往返丢字段"——
        // 一个已关的房间恢复后会重新收人
        $closed = isset($d["closed"]) ? (int) $d["closed"] : 0;
        if ($closed === 1) {
            $this->closed = true;
        }

        $rows = isset($d["members"]) && is_array($d["members"]) ? $d["members"] : [];
        $this->members->loadFrom($rows);
        return true;
    }
}
