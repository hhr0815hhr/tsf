<?php

namespace TSF\Entity;

/**
 * 可移动实体：位置 + 速度，按 delta 推进，撞墙反弹。
 *
 * 放在独立的包里而不是塞进 room：只要"有坐标的东西"就需要它，
 * 但不需要房间。比如单机模拟、视野测试、甚至一个纯计算的格子地图。
 *
 * 坐标用整数而不是浮点：帧循环每帧要跑一遍全部实体，浮点在 AOT 下不比整数快，
 * 而且比较/取整会引入误差。游戏内坐标按 1 单位 = 1 厘米足够。
 */
final class Entity
{
    /** 场景边界（0..MAX） */
    public const MAX = 10000;

    public int $uid;

    public int $x;

    public int $y;

    /** 速度（单位/秒），这样 delta 换算后不受帧率影响 */
    public int $vx;

    public int $vy;

    public function __construct(int $uid, int $x = 0, int $y = 0, int $vx = 0, int $vy = 0)
    {
        $this->uid = $uid;
        $this->x = $x;
        $this->y = $y;
        $this->vx = $vx;
        $this->vy = $vy;
    }

    /** 按可变 delta 推进（毫秒），撞墙反弹 */
    public function update(int $deltaMs): void
    {
        $x = $this->x + intdiv($this->vx * $deltaMs, 1000);
        $y = $this->y + intdiv($this->vy * $deltaMs, 1000);

        if ($x < 0) {
            $x = 0;
            $this->vx = -$this->vx;
        } elseif ($x > self::MAX) {
            $x = self::MAX;
            $this->vx = -$this->vx;
        }
        if ($y < 0) {
            $y = 0;
            $this->vy = -$this->vy;
        } elseif ($y > self::MAX) {
            $y = self::MAX;
            $this->vy = -$this->vy;
        }

        $this->x = $x;
        $this->y = $y;
    }

    public function moveTo(int $x, int $y): void
    {
        $this->x = $x;
        $this->y = $y;
    }
}
