<?php

namespace TSF\Room;

use TSF\Service\Scheduler;

/**
 * 房间管理器：创建/回收 + 承载方式选择。
 *
 * 两种承载方式（T3-03）：
 *
 *   独占（dedicated）：一房一 Service、一个 50 ms 定时器。慢帧只影响自己。
 *                     适合 MMO 副本、MOBA 这类实体多、帧重的房间。
 *
 *   共享（shared）：多个小房间共用一个 RoomHost、一个定时器、一帧 delta。
 *                  5,000 个房间时定时器从 5,000 个降到 25 个（每宿主 200 间）。
 *                  适合棋牌、回合制——人少、无实时位置、帧逻辑轻。
 *
 * 对外只用 **roomId** 寻址：`create()` 返回房间号，`send($roomId, $op, ...)` 投递。
 * 承载方式对调用方透明——这是"换模式不改业务代码"的前提。
 *
 * 早期 `create()` 返回服务地址，共享模式下地址会重复，所以改了。
 *
 * 不做成单例：管理器是业务自己持有的对象（一个进程可能管多种房间池），
 * 生命周期跟着进程走。
 */
final class RoomManager
{
    public const MODE_DEDICATED = 0;

    public const MODE_SHARED = 1;

    public const OP_JOIN = 1;

    public const OP_LEAVE = 2;

    public const OP_START = 3;

    public const OP_FINISH = 4;

    public const OP_CLOSE = 5;

    /** @var array<int, int> roomId => 承载它的服务地址 */
    private array $rooms = [];

    /** @var array<int, int> hostAddr => 已挂房间数（仅共享模式） */
    private array $hostLoad = [];

    private int $nextRoomId = 1;

    private int $created = 0;

    private int $closed = 0;

    private int $mode;

    private int $frameMs;

    private int $maxRoomsPerHost;

    private int $hostAddr = 0;

    public function __construct(int $mode = self::MODE_DEDICATED, int $frameMs = 50, int $maxRoomsPerHost = 200)
    {
        $this->mode = $mode === self::MODE_SHARED ? self::MODE_SHARED : self::MODE_DEDICATED;
        $this->frameMs = $frameMs > 0 ? $frameMs : 50;
        $this->maxRoomsPerHost = $maxRoomsPerHost > 0 ? $maxRoomsPerHost : 200;
    }

    public function mode(): int
    {
        return $this->mode;
    }

    /**
     * 创建房间，返回**房间号**（不是服务地址）。
     *
     * $autoFrame 只在独占模式有意义：共享模式下所有房间由宿主统一驱动，
     * 想关帧就整个宿主都不该存在。
     */
    public function create(int $maxPlayers = 8, bool $autoFrame = true): int
    {
        $roomId = $this->nextRoomId;
        $this->nextRoomId++;

        if ($this->mode === self::MODE_SHARED) {
            $host = $this->ensureHost();
            $this->hostLoad[$host]++;
            $this->rooms[$roomId] = $host;
            // 异步投递。mailbox 是 FIFO，所以 ADD 一定先于后续业务消息被处理。
            Scheduler::instance()->deliver($host, RoomHost::MSG_ADD, [
                "room" => $roomId,
                "max" => $maxPlayers,
            ]);
        } else {
            $mgr = $this;
            $addr = Scheduler::instance()->spawnOf(function () use ($roomId, $maxPlayers, $mgr, $autoFrame) {
                return new RoomService($roomId, $maxPlayers, $mgr, $autoFrame);
            });
            $this->rooms[$roomId] = $addr;
        }

        $this->created++;
        return $roomId;
    }

    /**
     * 给房间发一个操作。
     *
     * 调用方只认 roomId 和 OP_*，不需要知道房间是独占还是共享——
     * 这正是"承载方式是配置项"的落点。
     */
    public function send(int $roomId, int $op, string|array|object $payload = ""): bool
    {
        $addr = $this->rooms[$roomId] ?? 0;
        if ($addr === 0) {
            return false;
        }

        if ($this->mode === self::MODE_SHARED) {
            return Scheduler::instance()->deliver($addr, RoomHost::MSG_TO_ROOM, [
                "room" => $roomId,
                "op" => $op,
                "data" => $payload,
            ]);
        }

        $msgId = $this->opToMsgId($op);
        if ($msgId === 0) {
            return false;
        }
        return Scheduler::instance()->deliver($addr, $msgId, $payload);
    }

    private function opToMsgId(int $op): int
    {
        if ($op === self::OP_JOIN) {
            return RoomService::MSG_JOIN;
        }
        if ($op === self::OP_LEAVE) {
            return RoomService::MSG_LEAVE;
        }
        if ($op === self::OP_START) {
            return RoomService::MSG_START;
        }
        if ($op === self::OP_FINISH) {
            return RoomService::MSG_FINISH;
        }
        if ($op === self::OP_CLOSE) {
            return RoomService::MSG_CLOSE;
        }
        return 0;
    }

    /** 选一个还有余量的宿主，没有就开新的 */
    private function ensureHost(): int
    {
        if ($this->hostAddr !== 0) {
            $load = isset($this->hostLoad[$this->hostAddr]) ? $this->hostLoad[$this->hostAddr] : 0;
            if ($load < $this->maxRoomsPerHost) {
                return $this->hostAddr;
            }
        }

        $mgr = $this;
        $frameMs = $this->frameMs;
        $max = $this->maxRoomsPerHost;
        $addr = Scheduler::instance()->spawnOf(function () use ($mgr, $frameMs, $max) {
            return new RoomHost($mgr, $frameMs, $max);
        });
        $this->hostAddr = $addr;
        $this->hostLoad[$addr] = 0;
        return $addr;
    }

    /** 房间实际所在的服务地址（排障/直投用；业务投递请用 send()） */
    public function addrOf(int $roomId): int
    {
        return $this->rooms[$roomId] ?? 0;
    }

    public function has(int $roomId): bool
    {
        return isset($this->rooms[$roomId]);
    }

    public function count(): int
    {
        return count($this->rooms);
    }

    /** @return array<int> 当前所有房间号（排障/巡检用） */
    public function roomIds(): array
    {
        $out = [];
        foreach ($this->rooms as $id => $addr) {
            $out[] = (int) $id;
        }
        return $out;
    }

    /** 主动关房：先摘表再销毁，避免 onStop 回调造成重复计数 */
    public function destroy(int $roomId): bool
    {
        if (!isset($this->rooms[$roomId])) {
            return false;
        }
        $addr = $this->rooms[$roomId];
        unset($this->rooms[$roomId]);
        $this->closed++;

        if ($this->mode === self::MODE_SHARED) {
            if (isset($this->hostLoad[$addr])) {
                $this->hostLoad[$addr]--;
            }
            Scheduler::instance()->deliver($addr, RoomHost::MSG_DROP, (string) $roomId);
        } else {
            Scheduler::instance()->kill($addr);
        }
        return true;
    }

    /** 房间自己关闭时回调（RoomService 和 RoomHost 都会调） */
    public function onClosed(int $roomId): void
    {
        if (!isset($this->rooms[$roomId])) {
            return; // destroy() 已经摘过表，不重复计数
        }
        $addr = $this->rooms[$roomId];
        unset($this->rooms[$roomId]);
        $this->closed++;

        if ($this->mode === self::MODE_SHARED && isset($this->hostLoad[$addr])) {
            $this->hostLoad[$addr]--;
        }
    }

    /**
     * 回收所有空宿主。返回回收数量。
     *
     * 不自动回收：空宿主只是一个定时器在转（每秒 20 次，每次数一下房间数），
     * 而自动 kill 会和"刚投了 ADD 还没被处理"产生竞态，可能把新房间投给已死的宿主。
     * 交给巡检定期调用更安全。
     */
    public function closeIdleHosts(): int
    {
        $n = 0;
        foreach ($this->hostLoad as $key => $load) {
            if ($load > 0) {
                continue;
            }
            $addr = (int) $key;
            unset($this->hostLoad[$addr]);
            if ($this->hostAddr === $addr) {
                $this->hostAddr = 0;
            }
            Scheduler::instance()->kill($addr);
            $n++;
        }
        return $n;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $maxLoad = 0;
        foreach ($this->hostLoad as $load) {
            if ($load > $maxLoad) {
                $maxLoad = $load;
            }
        }
        return [
            "mode" => $this->mode,
            "live" => count($this->rooms),
            "created" => $this->created,
            "closed" => $this->closed,
            "hosts" => count($this->hostLoad),
            "max_host_load" => $maxLoad,
        ];
    }
}
