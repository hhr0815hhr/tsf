<?php

namespace TSF\Gateway;

/**
 * 会话表：fd ↔ uid 双向索引 + 空闲扫描。
 *
 * 为什么需要 uid → fd 的反向索引：
 *   - 顶号（同账号新登录踢掉旧连接）必须能按 uid 找到旧 fd；
 *   - 服务端主动推送/踢人也要按 uid 定位。
 *
 * 注意：这是**进程内**状态。多 worker 时同一个 uid 可能连在不同 worker 上，
 * 所以网关层必须按 uid 做粘性路由（uid → worker），否则顶号会漏。
 * 当前 M1 是单 worker 基线，多 worker 的粘性路由留到 M5 集群层。
 */
final class SessionManager
{
    /** @var array<int, Session> fd => Session */
    private array $byFd = [];

    /** @var array<int, int> uid => fd */
    private array $byUid = [];

    private int $idleTimeoutSec;

    public function __construct(int $idleTimeoutSec = 30)
    {
        $this->idleTimeoutSec = $idleTimeoutSec;
    }

    public function open(int $fd, int $now): void
    {
        $s = new Session();
        $s->fd = $fd;
        $s->openAt = $now;
        $s->lastActive = $now;
        $this->byFd[$fd] = $s;
    }

    /**
     * 绑定 uid。若该 uid 已有连接，返回旧 fd（调用方负责断开），否则返回 0。
     */
    public function bind(int $fd, int $uid, string $token, int $now): int
    {
        if (!isset($this->byFd[$fd])) {
            return 0;
        }
        $oldFd = 0;
        if (isset($this->byUid[$uid])) {
            $oldFd = $this->byUid[$uid];
            if ($oldFd === $fd) {
                $oldFd = 0;
            }
        }
        $s = $this->byFd[$fd];
        $s->uid = $uid;
        $s->token = $token;
        $s->authed = true;
        $s->lastActive = $now;
        $this->byUid[$uid] = $fd;
        if ($oldFd !== 0 && isset($this->byFd[$oldFd])) {
            $this->byFd[$oldFd]->authed = false;
        }
        return $oldFd;
    }

    public function get(int $fd): ?Session
    {
        return $this->byFd[$fd] ?? null;
    }

    public function uidOf(int $fd): int
    {
        if (!isset($this->byFd[$fd])) {
            return 0;
        }
        return $this->byFd[$fd]->uid;
    }

    public function fdOf(int $uid): int
    {
        return $this->byUid[$uid] ?? 0;
    }

    /**
     * 每帧记账：把 touch + 计数合并成一次调用。
     *
     * 分开写要多一次方法调用 + 两次数组查找（实测各约 150~200 ns），
     * 在每帧都跑的路径上不值得。
     */
    public function touchFrame(int $fd, int $nowSec): void
    {
        if (isset($this->byFd[$fd])) {
            $s = $this->byFd[$fd];
            $s->lastActive = $nowSec;
            $s->frames++;
        }
    }

    public function touch(int $fd, int $now): void
    {
        if (isset($this->byFd[$fd])) {
            $this->byFd[$fd]->lastActive = $now;
        }
    }

    public function countFrame(int $fd): void
    {
        if (isset($this->byFd[$fd])) {
            $this->byFd[$fd]->frames++;
        }
    }

    public function countDrop(int $fd): void
    {
        if (isset($this->byFd[$fd])) {
            $this->byFd[$fd]->dropped++;
        }
    }

    public function close(int $fd): void
    {
        if (!isset($this->byFd[$fd])) {
            return;
        }
        $s = $this->byFd[$fd];
        if ($s->uid !== 0 && isset($this->byUid[$s->uid]) && $this->byUid[$s->uid] === $fd) {
            unset($this->byUid[$s->uid]);
        }
        unset($this->byFd[$fd]);
    }

    /**
     * 清扫空闲连接。
     *
     * @return array<int, int> 超时的 fd 列表
     */
    public function sweep(int $now): array
    {
        $dead = [];
        foreach ($this->byFd as $fd => $s) {
            if ($now - $s->lastActive > $this->idleTimeoutSec) {
                $dead[] = (int) $fd;
            }
        }
        return $dead;
    }

    public function total(): int
    {
        return count($this->byFd);
    }

    public function authedTotal(): int
    {
        return count($this->byUid);
    }
}
