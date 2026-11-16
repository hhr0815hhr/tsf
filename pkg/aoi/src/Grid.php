<?php

namespace TSF\Aoi;

/**
 * 九宫格：把场景切成固定大小的格子，实体按坐标落到格子里。
 *
 * 为什么需要它：1000 个实体两两算距离是 50 万次比较，一帧就要几十毫秒；
 * 九宫格把"和谁比较"从"全体"缩小到"相邻 9 个格子里的实体"，通常只有几十个。
 *
 * 三个实现细节值得说明：
 *
 * 1. **格子内的实体用 map（uid => uid）而不是 list**。
 *    换格时要从旧格子摘掉一个实体：list 得 `array_search`（O(格子人数)），
 *    而 map 的 `unset` 是 O(1)。密集场景下这个差别是数量级的。
 *    遍历邻居时 `foreach ($cell as $uid => $_)` 直接拿 key，不需要 `array_keys` 再分配。
 *
 * 2. **不存坐标**。本层只关心"在哪个格子"，坐标由上层 Scene 持有。
 *    少一次写就少约 150 ns，而这条路径每帧要走 1000 次。
 *
 * 3. **不换格时什么都不写**。绝大多数帧里实体只在格内移动，这条路只有
 *    "读一次 cellOf + 两次整除取模 + 比较"，没有任何写操作。
 */
final class Grid
{
    private int $cellSize;

    private int $cols;

    private int $rows;

    /** @var array<int, array<int, int>> 格子 key => (uid => uid) */
    private array $cells = [];

    /** @var array<int, int> uid => 当前格子 key */
    private array $cellOf = [];

    /** 坐标系上限（超出就夹到边界，不让实体跑到不存在的格子里） */
    private int $maxX;

    private int $maxY;

    public function __construct(int $cellSize, int $cols, int $rows, int $maxX = 10000, int $maxY = 10000)
    {
        if ($cellSize <= 0 || $cols <= 0 || $rows <= 0) {
            throw new \RuntimeException("aoi: cellSize/cols/rows 必须 > 0");
        }
        $this->cellSize = $cellSize;
        $this->cols = $cols;
        $this->rows = $rows;
        $this->maxX = $maxX;
        $this->maxY = $maxY;
    }

    public function cellSize(): int
    {
        return $this->cellSize;
    }

    public function entityCount(): int
    {
        return count($this->cellOf);
    }

    public function cellCount(): int
    {
        return count($this->cells);
    }

    /** 坐标 → 格子 key；越界夹到边界 */
    public function keyOf(int $x, int $y): int
    {
        if ($x < 0) {
            $x = 0;
        } elseif ($x > $this->maxX) {
            $x = $this->maxX;
        }
        if ($y < 0) {
            $y = 0;
        } elseif ($y > $this->maxY) {
            $y = $this->maxY;
        }
        $cx = intdiv($x, $this->cellSize);
        $cy = intdiv($y, $this->cellSize);
        if ($cx >= $this->cols) {
            $cx = $this->cols - 1;
        }
        if ($cy >= $this->rows) {
            $cy = $this->rows - 1;
        }
        return $cy * $this->cols + $cx;
    }

    public function insert(int $uid, int $x, int $y): bool
    {
        if (isset($this->cellOf[$uid])) {
            return false;
        }
        $key = $this->keyOf($x, $y);
        $this->cells[$key][$uid] = $uid;
        $this->cellOf[$uid] = $key;
        return true;
    }

    /** 当前所在格子；不存在返回 -1 */
    public function cellKeyOf(int $uid): int
    {
        return $this->cellOf[$uid] ?? -1;
    }

    /** 两个格子是否相邻（含同一格）——视野判定就是这个，不需要额外的"谁能看到谁"表 */
    public function isAdjacent(int $a, int $b): bool
    {
        $ax = $a % $this->cols;
        $ay = intdiv($a, $this->cols);
        $bx = $b % $this->cols;
        $by = intdiv($b, $this->cols);
        $dx = $ax - $bx;
        $dy = $ay - $by;
        if ($dx < 0) {
            $dx = -$dx;
        }
        if ($dy < 0) {
            $dy = -$dy;
        }
        return $dx <= 1 && $dy <= 1;
    }

    /**
     * 移动。返回值有三种：
     *   >= 0  旧格子 key（换了格子，调用方可用它取旧邻居）
     *   -1    没换格（还在原格）
     *   -2    实体不存在
     *
     * 为什么返回旧 key 而不是 bool：换格时要算"进出了谁"，必须在移动**之后**
     * 还能拿到移动**之前**的邻居。如果只返回 true，上层就得先查一次当前 key、
     * 再算一次新 key——白白多两次调用（实测 1098 vs 868 ns）。
     *
     * 未换格时**不做任何写操作**——这是每帧 1000 次的热路径。
     */
    public function move(int $uid, int $x, int $y): int
    {
        if (!isset($this->cellOf[$uid])) {
            return -2;
        }
        $key = $this->keyOf($x, $y);
        $old = $this->cellOf[$uid];
        if ($key === $old) {
            return -1;
        }
        unset($this->cells[$old][$uid]);
        if ($this->cells[$old] === []) {
            unset($this->cells[$old]);
        }
        $this->cells[$key][$uid] = $uid;
        $this->cellOf[$uid] = $key;
        return $old;
    }

    public function remove(int $uid): bool
    {
        if (!isset($this->cellOf[$uid])) {
            return false;
        }
        $key = $this->cellOf[$uid];
        unset($this->cells[$key][$uid]);
        if ($this->cells[$key] === []) {
            unset($this->cells[$key]);
        }
        unset($this->cellOf[$uid]);
        return true;
    }

    /**
     * 取某个实体的邻居（含自己，调用方自行排除；这里不排除是因为
     * "排除自己"在调用方只是一次比较，而在这里要额外判断+可能复制数组）。
     *
     * @return array<int, int> uid => uid
     */
    public function neighbors(int $uid): array
    {
        if (!isset($this->cellOf[$uid])) {
            return [];
        }
        return $this->neighborsOfKey($this->cellOf[$uid]);
    }

    /**
     * 批量算邻居：**同一格的玩家共享一次 9 格扫描**。
     *
     * 为什么值得：同一格里的所有玩家，3×3 范围完全一样。逐人调 neighbors() 时，
     * 同格 N 个玩家要把同一批格子扫 N 遍；这里每个格子只扫一遍，N 个玩家共用结果。
     * 收益直接等于「同格玩家数」——出生点、战斗区这类密集场景收益最大；
     * 全散开时每格只有 1 人，收益为零（此时不如逐个调，省了遍历 uids 的开销）。
     *
     * 返回的是**共享数组**（PHP 数组赋值是写时复制）。调用方只读没问题；
     * 要排除自己或做任何修改，先自己拷一份。
     *
     * @param array<int, int> $uids uid => uid（与 neighborsOfKey() 的返回同构，可直接喂）
     * @return array<int, array<int, int>> uid => 邻居集合
     */
    public function neighborsShared(array $uids): array
    {
        $cache = [];
        $out = [];
        foreach ($uids as $uid => $_) {
            if (!isset($this->cellOf[$uid])) {
                $out[$uid] = [];
                continue;
            }
            $k = $this->cellOf[$uid];
            if (!isset($cache[$k])) {
                $cache[$k] = $this->neighborsOfKey($k);
            }
            $out[$uid] = $cache[$k];
        }
        return $out;
    }

    /**
     * 按格子 key 取 3×3 范围内的所有实体（含该格自己）。
     *
     * 单独开这个方法是为了"移动后还能拿到移动前的邻居"：move() 返回旧 key，
     * 上层用它调这里即可。
     *
     * @return array<int, int> uid => uid
     */
    public function neighborsOfKey(int $key): array
    {
        $cx = $key % $this->cols;
        $cy = intdiv($key, $this->cols);

        $out = [];
        $y0 = $cy > 0 ? $cy - 1 : 0;
        $y1 = $cy + 1 < $this->rows ? $cy + 1 : $this->rows - 1;
        $x0 = $cx > 0 ? $cx - 1 : 0;
        $x1 = $cx + 1 < $this->cols ? $cx + 1 : $this->cols - 1;

        for ($y = $y0; $y <= $y1; $y++) {
            $base = $y * $this->cols;
            for ($x = $x0; $x <= $x1; $x++) {
                $k = $base + $x;
                if (!isset($this->cells[$k])) {
                    continue;
                }
                foreach ($this->cells[$k] as $other => $u) {
                    $out[$other] = $u;
                }
            }
        }
        return $out;
    }

    /** 某个格子里的人数（排障用） */
    public function cellSizeOf(int $key): int
    {
        return isset($this->cells[$key]) ? count($this->cells[$key]) : 0;
    }
}
