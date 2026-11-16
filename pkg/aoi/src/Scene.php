<?php

namespace TSF\Aoi;

/**
 * 场景视野：九宫格上的一层薄封装，负责"进出视野"的事件统计。
 *
 * **不维护"谁能看到谁"的表**。这一点值得说明，因为第一版就是那么写的：
 * 1000 个实体、每人平均 78 个邻居，等于存了 77,940 条有向边；
 * 每次换格还要往 78 个人的表里各写一次。内存和维护成本都随"人数×邻居数"平方级膨胀，
 * 1 万人的场景直接不可用。
 *
 * 但网格视野本来就是**格子坐标的纯函数**：
 *   A 能看到 B  ⟺  两个格子是同一个或相邻（切比雪夫距离 ≤ 1）
 * 所以完全不用存。查询时按需扫 9 个格子即可，`sees()` 甚至只是一次格子坐标比较。
 *
 * 代价是视野不能"增量维护"，每次问都要重扫 9 格。但换格本来就要重算，
 * 而格内移动（绝大多数帧）连重算都不需要——这才是真正的热路径。
 *
 * 事件计数口径：`enters` / `leaves` 统计**有向边**。一对互见关系建立 = 2。
 */
final class Scene
{
    private Grid $grid;

    private int $enters = 0;

    private int $leaves = 0;

    private int $crossings = 0;

    public function __construct(int $cellSize, int $cols, int $rows, int $maxX = 10000, int $maxY = 10000)
    {
        $this->grid = new Grid($cellSize, $cols, $rows, $maxX, $maxY);
    }

    public function grid(): Grid
    {
        return $this->grid;
    }

    public function entityCount(): int
    {
        return $this->grid->entityCount();
    }

    public function enters(): int
    {
        return $this->enters;
    }

    public function leaves(): int
    {
        return $this->leaves;
    }

    /** 换格次数（每次都会产生视野重算） */
    public function crossings(): int
    {
        return $this->crossings;
    }

    public function add(int $uid, int $x, int $y): bool
    {
        if (!$this->grid->insert($uid, $x, $y)) {
            return false;
        }
        // neighbors 含自己，所以减 1；每对互见算 2 条有向边
        $n = count($this->grid->neighbors($uid)) - 1;
        if ($n > 0) {
            $this->enters += $n * 2;
        }
        return true;
    }

    /**
     * 放置：不在就插入，在就挪格。
     *
     * 给 `EntitySet` 的位置回调用——那边只发「这个 uid 现在在 (x,y)」，
     * 不区分首次还是移动。让调用方自己去判断"是不是第一次"很容易漏。
     *
     * @return int 1 = 新插入，>1 同 move（换格），0 = 格内移动，-1 = 出错
     */
    public function place(int $uid, int $x, int $y): int
    {
        if ($this->grid->cellKeyOf($uid) < 0) {
            return $this->add($uid, $x, $y) ? 1 : -1;
        }
        return $this->move($uid, $x, $y);
    }

    /**
     * 移动。返回 1 表示换格（已统计 enter/leave），0 表示仍在原格（没做任何事）。
     */
    public function move(int $uid, int $x, int $y): int
    {
        $oldKey = $this->grid->move($uid, $x, $y);
        if ($oldKey === -2) {
            return -1; // 实体不存在
        }
        if ($oldKey < 0) {
            // 格内移动：不查邻居、不写任何表——这是每帧 1000 次的热路径
            return 0;
        }

        // 移动前的邻居：用 move 返回的旧 key 反查。
        // 注意两侧都要排除自己：旧格邻域是在移动"之后"算的，若新格与旧格相邻，
        // 自己会出现在旧格的邻域里，不排除就会记一条"自己离开了自己"的假事件。
        $old = $this->grid->neighborsOfKey($oldKey);
        unset($old[$uid]);
        $new = $this->grid->neighbors($uid);
        unset($new[$uid]);

        foreach ($new as $other => $u) {
            if (!isset($old[$other])) {
                $this->enters += 2;
            }
        }
        foreach ($old as $other => $u) {
            if (!isset($new[$other])) {
                $this->leaves += 2;
            }
        }
        $this->crossings++;
        return 1;
    }

    public function remove(int $uid): void
    {
        $n = count($this->grid->neighbors($uid)) - 1;
        if ($n > 0) {
            $this->leaves += $n * 2;
        }
        $this->grid->remove($uid);
    }

    /** @return array<int, int> uid => uid，不含自己 */
    public function viewOf(int $uid): array
    {
        $nb = $this->grid->neighbors($uid);
        unset($nb[$uid]);
        return $nb;
    }

    /** A 能否看到 B：只比格子坐标，不查任何表 */
    public function sees(int $a, int $b): bool
    {
        $ka = $this->grid->cellKeyOf($a);
        if ($ka < 0) {
            return false;
        }
        $kb = $this->grid->cellKeyOf($b);
        if ($kb < 0) {
            return false;
        }
        return $this->grid->isAdjacent($ka, $kb);
    }
}
