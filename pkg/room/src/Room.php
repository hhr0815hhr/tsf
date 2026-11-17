<?php

namespace TSF\Room;

use TSF\Fsm\Machine;

/**
 * 房间 = 状态机 + 成员表。**两样都是独立包，房间只是把它们放在一起**。
 *
 * ```
 * pkg/fsm   →  Machine      状态与转移（也能用在登录流程、订单、战斗单位上）
 * pkg/room  →  MemberTable  谁在里面、坐哪、什么身份
 *           →  Room         上面两个的组合，仅此而已
 * ```
 *
 * 这个组合不是随便配的，而是因为"一局游戏"恰好同时需要这两件事：
 * 有生命周期（等待→进行→结算）、有参与人（含观战）。
 *
 * 如果哪个玩法只要其中一样——比如一个纯排队的匹配池，它需要状态机但不需要
 * 席位——那就直接用 `Machine`，不要装 `room`。
 *
 * 为什么状态转移写成表而不是散落在方法里：规则是**数据**，写在构造里一目了然。
 * 如果每个方法里各写一句 `if ($this->state !== X) return false`，
 * 加一个状态就要改一圈，而且"哪些边合法"永远说不清。
 *
 * 为什么坐标不在这里：不是所有玩法都有坐标。见 README §10.1.1。
 */
final class Room
{
    public const WAITING = 1;

    public const PLAYING = 2;

    public const SETTLED = 3;

    public const CLOSED = 4;

    private int $id;

    private Machine $fsm;

    private MemberTable $members;

    /** @var array<int, string> 状态号 → 名字；业务可注册自己的 */
    private array $names = [];

    /** @var array<int, int> 允许进人的状态（state => 1） */
    private array $joinable = [];

    /** @var array<int, int> 推进帧的状态（state => 1） */
    private array $running = [];

    /** @var array<int, int> 允许被快照覆盖的状态（state => 1） */
    private array $loadable = [];

    /** 已经推进的帧数 */
    private int $frame = 0;

    /** 累计推进的时间（毫秒），用于和真实时间对账 */
    private int $elapsedMs = 0;

    public function __construct(int $id, int $maxPlayers = 8)
    {
        $this->id = $id;
        $this->members = new MemberTable($maxPlayers);

        // 转移规则集中声明。close 从任何非 CLOSED 状态都允许，所以三条边都要写——
        // 这比在 close() 里写 `if ($state !== CLOSED)` 更能表达"关闭是全局可用的"。
        // 默认语义 = 改造前的硬编码行为，保证已有代码零改动。
        // 三个集合都是「业务语义」，框架不该替业务决定：斗地主多一个「叫地主」状态，
        // 它既允许进人、又不该推帧——这套东西由业务声明，而不是改框架源码。
        $this->names = [
            self::WAITING => "waiting",
            self::PLAYING => "playing",
            self::SETTLED => "settled",
            self::CLOSED => "closed",
        ];
        $this->joinable = [self::WAITING => 1, self::PLAYING => 1];
        $this->running = [self::PLAYING => 1];
        $this->loadable = [self::WAITING => 1];

        $fsm = new Machine(self::WAITING);
        $fsm->allow(self::WAITING, "start", self::PLAYING)
            ->allow(self::PLAYING, "finish", self::SETTLED)
            ->allow(self::WAITING, "close", self::CLOSED)
            ->allow(self::PLAYING, "close", self::CLOSED)
            ->allow(self::SETTLED, "close", self::CLOSED);
        $this->fsm = $fsm;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function state(): int
    {
        return $this->fsm->state();
    }

    public function stateName(): string
    {
        $s = $this->fsm->state();
        return $this->names[$s] ?? ("state" . $s);
    }

    /**
     * 注册自定义状态名。
     *
     * 改造前 `stateName()` 是四条 if：第五个状态会被当成 "closed"——一个错误答案，
     * 而不是"未知"。查表之后未知状态返回 `state5` 这种可辨认的名字。
     */
    public function setStateName(int $state, string $name): void
    {
        $this->names[$state] = $name;
    }

    /**
     * 声明「哪些状态允许进人」。
     *
     * 不传则沿用默认（WAITING / PLAYING 可进）。**这是业务语义**：斗地主的「叫地主」
     * 阶段允许进人（还能观战），而「结算中」不允许。
     *
     * @param array<int, int> $states 状态号列表
     */
    public function setJoinableStates(array $states): void
    {
        $this->joinable = [];
        foreach ($states as $st) {
            $this->joinable[$st] = 1;
        }
    }

    /**
     * 声明「哪些状态推进帧」。
     *
     * @param array<int, int> $states
     */
    public function setRunningStates(array $states): void
    {
        $this->running = [];
        foreach ($states as $st) {
            $this->running[$st] = 1;
        }
    }

    /**
     * 声明「哪些状态允许被快照覆盖」。
     *
     * @param array<int, int> $states
     */
    public function setLoadableStates(array $states): void
    {
        $this->loadable = [];
        foreach ($states as $st) {
            $this->loadable[$st] = 1;
        }
    }

    /** 状态机本体——需要改转移规则或挂钩子时用 */
    public function fsm(): Machine
    {
        return $this->fsm;
    }

    public function members(): MemberTable
    {
        return $this->members;
    }

    /** 占席位人数（容量比较对象，不含观战） */
    public function playerCount(): int
    {
        return $this->members->occupants();
    }

    /** 总人数（含观战） */
    public function memberCount(): int
    {
        return $this->members->count();
    }

    public function maxPlayers(): int
    {
        return $this->members->capacity();
    }

    public function frame(): int
    {
        return $this->frame;
    }

    public function elapsedMs(): int
    {
        return $this->elapsedMs;
    }

    public function isEmpty(): bool
    {
        return $this->members->isEmpty();
    }

    /** 全部 uid（含观战） */
    public function uids(): array
    {
        return $this->members->uids();
    }

    /** 占席位的 uid，按席位号升序 */
    public function seated(): array
    {
        return $this->members->seated();
    }

    public function has(int $uid): bool
    {
        return $this->members->has($uid);
    }

    /** 加入（玩家身份）。只要布尔结果时用它 */
    public function add(int $uid, string $name = ""): bool
    {
        if (!isset($this->joinable[$this->state()])) {
            return false;
        }
        return $this->members->add($uid, Member::PLAYER, $name) !== null;
    }

    /**
     * 按身份加入。需要席位号或成员对象时用它。
     *
     * 结算后不再收人（观战也拒）：房间很快要销毁，让观战挤进来只会拖长回收。
     */
    public function addAs(int $uid, int $role, string $name = ""): ?Member
    {
        if (!isset($this->joinable[$this->state()])) {
            return null;
        }
        return $this->members->add($uid, $role, $name);
    }

    public function remove(int $uid): bool
    {
        return $this->members->remove($uid);
    }

    /** WAITING -> PLAYING，至少要有一个占席位的人 */
    public function start(): bool
    {
        if ($this->members->occupants() === 0) {
            return false;
        }
        if (!$this->fsm->fire("start")) {
            return false;
        }
        $this->frame = 0;
        $this->elapsedMs = 0;
        return true;
    }

    /** PLAYING -> SETTLED */
    public function finish(): bool
    {
        return $this->fsm->fire("finish");
    }

    /** 任意状态 -> CLOSED（幂等）；清空成员 */
    public function close(): bool
    {
        if (!$this->fsm->fire("close")) {
            return false;
        }
        $this->members->clear();
        return true;
    }

    /**
     * 推进一帧。返回是否真的推进了（非 PLAYING 返回 false）。
     *
     * **只记时间，不动任何东西**——实体推进在 `EntitySet::tick()` 里。
     * 房间不需要知道世界上有没有坐标这回事。
     *
     * delta 是"距离上一帧的真实耗时"，不是固定 50——这样网络抖动或进程卡顿后
     * 位置不会突然跳变，也不会落后于真实时间。
     */
    public function tick(int $deltaMs): bool
    {
        if (!isset($this->running[$this->fsm->state()])) {
            return false;
        }
        if ($deltaMs < 0) {
            $deltaMs = 0;
        }
        $this->frame++;
        $this->elapsedMs += $deltaMs;
        return true;
    }

    /**
     * 导出成可序列化的数组。**键的顺序是固定的**——恢复后再导出必须字节一致，
     * 否则"冷哈希一致"这条验收过不去。
     */
    public function toArray(): array
    {
        return [
            "id" => $this->id,
            "state" => $this->fsm->state(),
            "frame" => $this->frame,
            "elapsedMs" => $this->elapsedMs,
            "maxPlayers" => $this->members->capacity(),
            "members" => $this->members->toArray(),
        ];
    }

    /**
     * 从数组整份重建。没有可恢复的内容时返回 null（id 非法）。
     *
     * 用静态工厂而不是"先 new 再 load"：玩家上限也在快照里，
     * 调用方拿不到它，只能由这里解析。
     */
    public static function fromArray(array $d): ?Room
    {
        $id = isset($d["id"]) ? (int) $d["id"] : 0;
        if ($id <= 0) {
            return null;
        }
        $max = isset($d["maxPlayers"]) ? (int) $d["maxPlayers"] : 8;
        if ($max <= 0) {
            $max = 8;
        }
        $room = new Room($id, $max);
        if (!$room->loadFrom($d)) {
            return null;
        }
        return $room;
    }

    /**
     * 把快照数据灌进这个房间。
     *
     * **只允许灌进一个全新的房间**（空成员、WAITING）——否则就是"用旧快照
     * 覆盖正在跑的对局"，那是数据事故而不是恢复。要重建就 `fromArray`。
     */
    public function loadFrom(array $d): bool
    {
        if (!$this->members->isEmpty() || !isset($this->loadable[$this->fsm->state()])) {
            return false;
        }

        // 不再把状态号限制在 [WAITING, CLOSED]：业务自定义的状态（比如叫地主）
        // 也是合法状态，硬校验会把它悄悄改成 WAITING，恢复出来的房间状态是错的。
        $state = isset($d["state"]) ? (int) $d["state"] : $this->fsm->state();
        if ($state < 0) {
            $state = $this->fsm->state();
        }
        $this->fsm->force($state);

        $this->frame = isset($d["frame"]) ? (int) $d["frame"] : 0;
        $this->elapsedMs = isset($d["elapsedMs"]) ? (int) $d["elapsedMs"] : 0;

        $rows = isset($d["members"]) && is_array($d["members"]) ? $d["members"] : [];
        $this->members->loadFrom($rows);
        return true;
    }
}
