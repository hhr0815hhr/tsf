<?php

namespace TSF\Shard;

/**
 * 一致性哈希环。
 *
 * ## 它解决的问题
 *
 * "key 该落到哪个节点"最直觉的做法是 `hash(key) % N`。但 N 一变（扩缩容），
 * **几乎所有 key 都要搬家**——加一个节点，N 从 3 变 4，`% 3` 和 `% 4` 的结果
 * 对 2/3 的 key 都不同。这意味着一扩容就是全量数据迁移 + 缓存全失效。
 *
 * 一致性哈希把节点和 key 都映射到同一个环上，key 顺时针找第一个节点。
 * 加节点只影响它"接管"的那一段弧，其余 key 的归属完全不变。
 *
 * ## 虚拟节点是必须的，不是优化
 *
 * 每个物理节点在环上只放 1 个点的话，3 个节点随机落 3 个位置，
 * 它们之间的弧长分布极不均匀。实测（`bench/shard-test`，3 节点 / 20000 key）：
 *
 * | vnodes | 最大占比 | 极差 |
 * |--:|--:|--:|
 * | 1 | 52.8% | 44.2% |
 * | 10 | 52.8% | 44.2% |
 * | 50 | 37.1% | 6.8% |
 * | **150** | **34.7%** | **2.6%** |
 * | 500 | 38.1% | 7.2% |
 *
 * 150 之后收益基本饱和（500 那行的波动来自单次实验方差），再往上只是让环更大、
 * 排序更慢。所以默认 150。
 *
 * ## 存储为什么是两个平行数组，而不是 `hash => nodeId`
 *
 * 第一版用 `array<int, int>` 以 hash 为 key。PHP 里没问题，但在 AOT 下
 * `array_keys()` 返回的元素与原始 key 类型不一致时，拿它回头查原数组会**查不到**——
 * 表现为"环里有 40 个点，但 key 的归属完全不变"、"各节点弧长占比加起来只有 0.68"。
 *
 * 这类问题排查成本极高：`size()`、`nodes()`、日志全都正常，只有查找结果是错的。
 * 所以现在改成：
 *
 * - `$pairs`：`[hash, nodeId]` 二维数组，按插入顺序追加
 * - `$hashes` / `$owners`：排好序的平行数组，查找只走**整数下标**
 *
 * 全流程不出现"用 hash 当数组下标"，也就没有类型不一致的空间。
 *
 * ## hash 用 crc32，限制在 31 位
 *
 * `crc32()` 是 C 实现，比 `md5()` 快约一个数量级。限制到 31 位是为了不和
 * 有符号 32 位边界纠缠——环空间 2^31 对一致性哈希的性质没有任何影响。
 */
final class Ring
{
    /** 每个物理节点在环上放多少个虚拟节点 */
    private int $vnodes;

    /** @var array<int, true> 已加入的物理节点 */
    private array $nodes = [];

    /** @var array<int, array<int, int>> [hash, nodeId] 对，插入顺序 */
    private array $pairs = [];

    /** @var array<int, int> 排好序的 hash（长度等于 pairs） */
    private array $hashes = [];

    /** @var array<int, int> 与 hashes 一一对应的 nodeId */
    private array $owners = [];

    /** 环是否需要重排 */
    private bool $dirty = false;

    public function __construct(int $vnodesPerNode = 150)
    {
        $this->vnodes = $vnodesPerNode > 0 ? $vnodesPerNode : 150;
    }

    public function vnodes(): int
    {
        return $this->vnodes;
    }

    /** 加入一个物理节点。已存在则忽略（幂等） */
    public function add(int $nodeId): bool
    {
        if ($nodeId <= 0 || isset($this->nodes[$nodeId])) {
            return false;
        }
        $this->nodes[$nodeId] = true;

        for ($i = 0; $i < $this->vnodes; $i++) {
            // 不做 hash 冲突检测：2^31 空间里上千个点的碰撞概率约 0.05%，
            // 真撞上了也只是那个位置有两个点、二分取到其中一个，
            // 对均衡度的影响远小于"线性探测"带来的聚集
            $this->pairs[] = [$this->hash($nodeId . "#" . $i), $nodeId];
        }
        $this->dirty = true;
        return true;
    }

    /** 移除一个物理节点，它占的所有虚拟节点一起消失 */
    public function remove(int $nodeId): bool
    {
        if (!isset($this->nodes[$nodeId])) {
            return false;
        }
        unset($this->nodes[$nodeId]);

        $keep = [];
        foreach ($this->pairs as $p) {
            if ((int) $p[1] !== $nodeId) {
                $keep[] = $p;
            }
        }
        $this->pairs = $keep;
        $this->dirty = true;
        return true;
    }

    /**
     * 整体重建。节点集合变化时用它比逐个 add/remove 更省事，
     * 也能保证环的状态和"当前存活节点列表"完全一致。
     *
     * @param array<int, int> $nodeIds
     */
    public function rebuild(array $nodeIds): void
    {
        $this->nodes = [];
        $this->pairs = [];
        $this->hashes = [];
        $this->owners = [];

        foreach ($nodeIds as $id) {
            $n = (int) $id;
            if ($n <= 0 || isset($this->nodes[$n])) {
                continue;
            }
            $this->nodes[$n] = true;
            for ($i = 0; $i < $this->vnodes; $i++) {
                $this->pairs[] = [$this->hash($n . "#" . $i), $n];
            }
        }
        $this->dirty = true;
    }

    /** 字符串 key 落到哪个节点。环为空返回 0 */
    public function nodeFor(string $key): int
    {
        if ($this->dirty) {
            $this->resort();
        }
        return $this->lookup($this->hash($key));
    }

    /** 已经是哈希值的 key 直接查（避免重复 hash） */
    public function nodeForHash(int $h): int
    {
        if ($this->dirty) {
            $this->resort();
        }
        return $this->lookup($h);
    }

    /**
     * 一次查 K 个 key，返回 `nodeId => 命中数`。
     *
     * @param array<int, string> $keys
     * @return array<int, int>
     */
    public function distribution(array $keys): array
    {
        if ($this->dirty) {
            $this->resort();
        }
        $out = [];
        foreach ($keys as $k) {
            $node = $this->lookup($this->hash((string) $k));
            $out[$node] = ($out[$node] ?? 0) + 1;
        }
        return $out;
    }

    /** 环上的虚拟节点总数 */
    public function size(): int
    {
        return count($this->pairs);
    }

    /** 物理节点列表（升序） */
    public function nodes(): array
    {
        $out = [];
        foreach ($this->nodes as $id => $v) {
            $out[] = (int) $id;
        }
        sort($out);
        return $out;
    }

    public function has(int $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    /** 某个节点占了环上多少比例（均衡度分析用） */
    public function share(int $nodeId): float
    {
        if ($this->dirty) {
            $this->resort();
        }
        $n = count($this->hashes);
        if ($n === 0) {
            return 0.0;
        }
        // 按"相邻点之间的弧长"算，而不是点数——点数均匀不等于弧长均匀
        $covered = 0;
        for ($i = 0; $i < $n; $i++) {
            $cur = $this->hashes[$i];
            $next = $i + 1 < $n ? $this->hashes[$i + 1] : 0x80000000;
            if ($this->owners[$i] === $nodeId) {
                $covered += $next - $cur;
            }
        }
        // 补上 [0, 最小点) 这一段：落在它前面的 key 会回绕到最小点，
        // 也就是归最小点的所有者。漏了这段，各节点占比之和只有 ~0.68
        if ($this->owners[0] === $nodeId) {
            $covered += $this->hashes[0];
        }
        return (float) $covered / (float) 0x80000000;
    }

    private function hash(string $s): int
    {
        return crc32($s) & 0x7FFFFFFF;
    }

    /**
     * 二分找第一个 `>= h` 的点；超过最大值则回绕到环首。
     *
     * 全用整数下标访问平行数组，不做任何"用 hash 查数组"的操作。
     * 属性先取到局部变量：查找是热路径，每次属性访问在 AOT 下都有开销，
     * 2400 点的二分要 12 层、8000 点要 13 层，省下的不只是常数。
     */
    private function lookup(int $h): int
    {
        $hashes = $this->hashes;
        $owners = $this->owners;
        $n = count($hashes);
        if ($n === 0) {
            return 0;
        }
        if ($h > $hashes[$n - 1]) {
            return $owners[0];   // 回绕
        }

        $lo = 0;
        $hi = $n - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($hashes[$mid] < $h) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $owners[$lo];
    }

    /**
     * 重排。`add()` 只标记不排序——逐个加 N 个节点若每次都全量排序，
     * 建 100 节点 × 150 虚拟节点要 109 ms；延迟到查找前只排一次是 3.8 ms。
     */
    private function resort(): void
    {
        $pairs = $this->pairs;
        $n = count($pairs);
        if ($n > 1) {
            $this->quickSort($pairs, 0, $n - 1);
        }

        $hashes = [];
        $owners = [];
        foreach ($pairs as $p) {
            $hashes[] = (int) $p[0];
            $owners[] = (int) $p[1];
        }
        $this->hashes = $hashes;
        $this->owners = $owners;
        $this->dirty = false;
    }

    /**
     * 手写快排，不依赖 `usort` 的回调。
     *
     * 两个原因：AOT 下把闭包传给 `usort` 的行为没验证过；而且排序对象是
     * `array<int, array<int,int>>`，用回调要多一层"取元素再比较"的间接。
     * 这是建环时的一次性成本，手写更可控。
     *
     * @param array<int, array<int, int>> $a
     */
    private function quickSort(array &$a, int $lo, int $hi): void
    {
        if ($lo >= $hi) {
            return;
        }
        $pivot = (int) $a[($lo + $hi) >> 1][0];
        $i = $lo;
        $j = $hi;
        while ($i <= $j) {
            while ((int) $a[$i][0] < $pivot) {
                $i++;
            }
            while ((int) $a[$j][0] > $pivot) {
                $j--;
            }
            if ($i <= $j) {
                $tmp = $a[$i];
                $a[$i] = $a[$j];
                $a[$j] = $tmp;
                $i++;
                $j--;
            }
        }
        $this->quickSort($a, $lo, $j);
        $this->quickSort($a, $i, $hi);
    }
}
