<?php

namespace TSF\Entity;

/**
 * 实体集合：管坐标、推进、以及「位置变了」这件事。
 *
 * ## 它解决的是什么问题
 *
 * 坐标只有一份事实来源（`Entity::$x/$y`），但视野索引（`pkg/aoi` 的格子）
 * 必须跟着变。如果让玩法层自己"推进完再挨个调 scene->move()"，
 * 迟早有人漏写——漏一次就是"人走了但视野里还在"。
 *
 * 所以把同步挂在推进上：**移动了就通知，不用调用方记得**。
 *
 * ```php
 * $ents = new EntitySet(function (int $uid, int $x, int $y) use ($scene): void {
 *     $scene->place($uid, $x, $y);   // 不存在就插入，存在就挪格
 * });
 * ```
 *
 * ## 为什么不直接依赖 pkg/aoi
 *
 * 因为不是所有带坐标的玩法都要视野（回合制、单机模拟、纯计算的格子地图）。
 * 用闭包而不是类型依赖，`entity` 包就能保持零依赖——要不要 AOI 由玩法决定。
 */
final class EntitySet
{
    /** @var array<int, Entity> uid => 实体 */
    private array $items = [];

    /** @var \Closure|null (int $uid, int $x, int $y): void */
    private ?\Closure $onMove;

    public function __construct(?\Closure $onMove = null)
    {
        $this->onMove = $onMove;
    }

    /** 后设回调（构造时拿不到 Scene 的情况） */
    public function onMove(\Closure $cb): void
    {
        $this->onMove = $cb;
    }

    /** 造一个实体并放进集合；首个位置会立即通知（让视野索引把它收进去） */
    public function spawn(int $uid, int $x = 0, int $y = 0, int $vx = 0, int $vy = 0): Entity
    {
        $e = new Entity($uid, $x, $y, $vx, $vy);
        $this->items[$uid] = $e;
        $this->notify($uid, $x, $y);
        return $e;
    }

    /** 放进一个已建好的实体 */
    public function add(Entity $e): bool
    {
        if (isset($this->items[$e->uid])) {
            return false;
        }
        $this->items[$e->uid] = $e;
        $this->notify($e->uid, $e->x, $e->y);
        return true;
    }

    public function get(int $uid): ?Entity
    {
        return $this->items[$uid] ?? null;
    }

    public function has(int $uid): bool
    {
        return isset($this->items[$uid]);
    }

    /** 移除。调用方负责同步自己的索引（视野在移除时是"看不见了"，与移动不同） */
    public function remove(int $uid): bool
    {
        if (!isset($this->items[$uid])) {
            return false;
        }
        unset($this->items[$uid]);
        return true;
    }

    /** @return array<int, Entity> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * 推进一帧，返回推进的实体数。
     *
     * 只在**位置真的变了**时才回调——没动的实体不该触发一次视野重算。
     */
    public function tick(int $deltaMs): int
    {
        if ($deltaMs < 0) {
            $deltaMs = 0;
        }
        $cb = $this->onMove;
        $n = 0;
        foreach ($this->items as $e) {
            $ox = $e->x;
            $oy = $e->y;
            $e->update($deltaMs);
            if ($cb !== null && ($e->x !== $ox || $e->y !== $oy)) {
                $cb($e->uid, $e->x, $e->y);
            }
            $n++;
        }
        return $n;
    }

    /** 手动挪一个实体（客户端输入、传送等），会通知回调 */
    public function moveTo(int $uid, int $x, int $y): bool
    {
        $e = $this->items[$uid] ?? null;
        if (!$e instanceof Entity) {
            return false;
        }
        $e->moveTo($x, $y);
        $this->notify($uid, $e->x, $e->y);
        return true;
    }

    /** 改速度，不改变位置（不通知：位置没变） */
    public function setVelocity(int $uid, int $vx, int $vy): bool
    {
        $e = $this->items[$uid] ?? null;
        if (!$e instanceof Entity) {
            return false;
        }
        $e->vx = $vx;
        $e->vy = $vy;
        return true;
    }

    /**
     * 导出成可序列化的数组，顺序是加入顺序（恢复时保持一致，哈希才稳定）。
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->items as $e) {
            $out[] = [
                "uid" => $e->uid,
                "x" => $e->x,
                "y" => $e->y,
                "vx" => $e->vx,
                "vy" => $e->vy,
            ];
        }
        return $out;
    }

    /**
     * 从数组恢复。返回载入数量。
     *
     * **恢复时不触发位置回调**：视野索引应该由调用方用整份坐标一次性重建
     * （`Scene::add` 逐个插入），而不是在这里对每个实体发一次"移动"事件——
     * 那会把"进入视野"统计打乱，而且对一堆本就在索引里的实体重复计算。
     */
    public function loadFrom(array $rows): int
    {
        $this->items = [];
        $n = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $uid = isset($r["uid"]) ? (int) $r["uid"] : 0;
            if ($uid <= 0 || isset($this->items[$uid])) {
                continue;
            }
            $e = new Entity(
                $uid,
                isset($r["x"]) ? (int) $r["x"] : 0,
                isset($r["y"]) ? (int) $r["y"] : 0,
                isset($r["vx"]) ? (int) $r["vx"] : 0,
                isset($r["vy"]) ? (int) $r["vy"] : 0
            );
            $this->items[$uid] = $e;
            $n++;
        }
        return $n;
    }

    private function notify(int $uid, int $x, int $y): void
    {
        $cb = $this->onMove;
        if ($cb === null) {
            return;
        }
        $cb($uid, $x, $y);
    }
}
