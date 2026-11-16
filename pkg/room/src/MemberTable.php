<?php

namespace TSF\Room;

/**
 * 成员表：谁在房间里、坐哪、是什么身份。
 *
 * **只管"关系"，不管状态机、不管坐标**——那两样各有各的包。
 *
 * ## 容量只约束"占席位"的人
 *
 * 观战者不占席位、人数不限。这不是小细节：棋牌房里"一桌 4 人 + 200 个观战"
 * 是常态，如果把观战也算进容量，要么开不了观战、要么把容量配得虚高。
 *
 * ## 席位号是稳定的
 *
 * 玩家坐下时分配最小空位，离开后空出来给别人。席位号在一局内不变——
 * 客户端按席位号画座位，中途换号会让人物跳位。
 */
final class MemberTable
{
    /** @var array<int, Member> uid => 成员 */
    private array $byUid = [];

    /** @var array<int, int> seat => uid */
    private array $bySeat = [];

    /** 席位上限（只算占席位的人） */
    private int $capacity;

    public function __construct(int $capacity = 8)
    {
        if ($capacity <= 0) {
            throw new \RuntimeException("room: 席位上限必须 > 0");
        }
        $this->capacity = $capacity;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * 加入。返回成员对象；失败返回 null。
     *
     * 失败的原因只有三种：uid 重复、席位已满（且不是观战）、uid 非法。
     * 用 null 而不是 false，是为了让调用方一次拿到席位号之类的新信息。
     */
    public function add(int $uid, int $role = Member::PLAYER, string $name = ""): ?Member
    {
        if ($uid <= 0 || isset($this->byUid[$uid])) {
            return null;
        }
        if ($role < Member::PLAYER || $role > Member::SPECTATOR) {
            return null;
        }

        $m = new Member($uid, $role, $name);

        if ($m->occupiesSeat()) {
            $seat = $this->freeSeat();
            if ($seat < 0) {
                return null; // 满员
            }
            $m->seat = $seat;
            $this->bySeat[$seat] = $uid;
        }

        $this->byUid[$uid] = $m;
        return $m;
    }

    public function remove(int $uid): bool
    {
        $m = $this->byUid[$uid] ?? null;
        if (!$m instanceof Member) {
            return false;
        }
        if ($m->seat >= 0) {
            unset($this->bySeat[$m->seat]);
        }
        unset($this->byUid[$uid]);
        return true;
    }

    public function get(int $uid): ?Member
    {
        return $this->byUid[$uid] ?? null;
    }

    public function has(int $uid): bool
    {
        return isset($this->byUid[$uid]);
    }

    /** 总人数（含观战） */
    public function count(): int
    {
        return count($this->byUid);
    }

    /** 占席位的人数——容量比较对象 */
    public function occupants(): int
    {
        return count($this->bySeat);
    }

    public function isFull(): bool
    {
        return count($this->bySeat) >= $this->capacity;
    }

    public function isEmpty(): bool
    {
        return $this->byUid === [];
    }

    /** 全部 uid（含观战），顺序不保证 */
    public function uids(): array
    {
        $out = [];
        foreach ($this->byUid as $uid => $m) {
            $out[] = (int) $uid;
        }
        return $out;
    }

    /** 占席位的 uid，按席位号升序 */
    public function seated(): array
    {
        $out = [];
        for ($s = 0; $s < $this->capacity; $s++) {
            $uid = $this->bySeat[$s] ?? null;
            if ($uid !== null) {
                $out[] = (int) $uid;
            }
        }
        return $out;
    }

    /** 观战者 uid */
    public function spectators(): array
    {
        $out = [];
        foreach ($this->byUid as $uid => $m) {
            if ($m->role === Member::SPECTATOR) {
                $out[] = (int) $uid;
            }
        }
        return $out;
    }

    /** 席位号；未入座返回 -1 */
    public function seatOf(int $uid): int
    {
        $m = $this->byUid[$uid] ?? null;
        if (!$m instanceof Member) {
            return -1;
        }
        return $m->seat;
    }

    public function clear(): void
    {
        $this->byUid = [];
        $this->bySeat = [];
    }

    /**
     * 导出成可序列化的数组。
     *
     * 顺序是 `byUid` 的插入顺序——**恢复时必须按同样的顺序插回去**，
     * 否则两次导出的字节不同，"冷哈希一致"这条验收就过不去。
     *
     * 席位号显式带出去：恢复时不能走 `add()`（那会重新分配座位，
     * 客户端按席位号画座位，一换就人物跳位）。
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->byUid as $m) {
            $out[] = [
                "uid" => $m->uid,
                "role" => $m->role,
                "seat" => $m->seat,
                "name" => $m->name,
            ];
        }
        return $out;
    }

    /**
     * 从数组恢复，返回实际载入的人数。
     *
     * **整表替换**而不是合并：快照代表"那一刻的完整状态"，
     * 合并会让脏数据（已经离开但还留在表里的人）活下来。
     *
     * 容错：非法条目跳过并计数，不抛异常。一份快照里有几条坏数据时，
     * 恢复其余部分比整个房间起不来好。
     */
    public function loadFrom(array $rows): int
    {
        $this->clear();
        $n = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $uid = isset($r["uid"]) ? (int) $r["uid"] : 0;
            if ($uid <= 0 || isset($this->byUid[$uid])) {
                continue;
            }
            $role = isset($r["role"]) ? (int) $r["role"] : Member::PLAYER;
            if ($role < Member::PLAYER || $role > Member::SPECTATOR) {
                $role = Member::PLAYER;
            }
            $seat = isset($r["seat"]) ? (int) $r["seat"] : -1;
            $name = isset($r["name"]) ? (string) $r["name"] : "";

            $m = new Member($uid, $role, $name);
            $m->seat = $seat;

            // 席位冲突（脏快照）：让后来者不占座，而不是覆盖前一个人的座位
            if ($seat >= 0) {
                if ($seat >= $this->capacity || isset($this->bySeat[$seat])) {
                    $m->seat = -1;
                } else {
                    $this->bySeat[$seat] = $uid;
                }
            }

            $this->byUid[$uid] = $m;
            $n++;
        }
        return $n;
    }

    /** 找最小空席位；满了返回 -1 */
    private function freeSeat(): int
    {
        for ($s = 0; $s < $this->capacity; $s++) {
            if (!isset($this->bySeat[$s])) {
                return $s;
            }
        }
        return -1;
    }
}
