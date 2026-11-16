<?php

namespace TSF\Discovery;

use TSF\Discovery\Contract\Registry;

/**
 * 文件注册表：每个节点两个文件，**身份和心跳分开存**。
 *
 * | 文件 | 内容 | 写的频率 |
 * |:--|:--|:--|
 * | `{id}.json` | 身份（endpoint / codec / tags） | 注册时一次 |
 * | `{id}.beat` | 毫秒时间戳，20 字节 | 每次心跳 |
 *
 * ## 为什么心跳单独一个文件，而不是 touch 主文件
 *
 * 第一版用 `touch()` 更新主文件的 mtime——极轻，但 **`filemtime()` 只到秒**。
 * 心跳 1 秒、TTL 3 秒时，±1 秒的截断误差意味着：节点在 `t=0.99s` 心跳、mtime 记成 0，
 * 到 `t=3.0s` 时算出来的 age 是 3 秒（实际只过了 2 秒）——**会被误判死亡**。
 *
 * 生产上这就是"节点偶尔被摘除、流量抖一下"，而且极难排查：现象是随机的。
 * 所以心跳内容必须带毫秒。单独一个小文件就够了——写 20 字节比"读-改-写整个身份"
 * 或"写临时文件再 rename"都便宜得多。
 *
 * ## 身份文件必须原子写
 *
 * 身份写的是 endpoint / codec，读者随时可能读到。直接写目标文件的话，
 * 读的一方可能拿到半截 JSON。所以走 `tmp` + `rename`。
 * 心跳文件只有 20 字节、内容是纯数字，写坏了也能被当"无效时间戳"回退到 mtime。
 *
 * ## 轮询的代价
 *
 * `alive()` 每次都要 `scandir` + 逐节点读两个文件。几十个节点无所谓；
 * 上万节点就该换中心化注册表——那时"文件系统当注册表"本身就不成立了
 * （跨机器要 NFS，而 NFS 的 mtime 和性能都不适合做这个）。
 */
final class FileRegistry implements Registry
{
    private string $dir;

    /** 默认存活阈值 */
    private int $ttlMs;

    /** @var array<int, \Closure> */
    private array $watchers = [];

    /** @var array<int, string> nodeId => "endpoint|codec|tags" 的指纹 */
    private array $snapshot = [];

    /**
     * @param string $dir   注册文件目录（不存在会自动创建）
     * @param int    $ttlMs 存活阈值（毫秒）
     */
    public function __construct(string $dir, int $ttlMs = 3000)
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("discovery: 无法创建目录 [" . $dir . "]");
            }
        }
        $this->dir = rtrim($dir, "/");
        // 100ms 是下限：再小的话一次 write+stat 的耗时就能越过它，
        // 判定会变成"随机死亡"。传了非法值也收敛到这个下限，而不是悄悄用默认值
        $this->ttlMs = $ttlMs >= 100 ? $ttlMs : 100;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function ttlMs(): int
    {
        return $this->ttlMs;
    }

    public function register(NodeInfo $n): bool
    {
        $path = $this->path($n->nodeId);
        $json = json_encode($n->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $tmp = $path . ".tmp";
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }
        if (!rename($tmp, $path)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }
        // 注册也算一次"我活着"，顺手把心跳文件建出来——
        // 否则从注册到第一次心跳之间（最长一个心跳周期）会被判成"没有心跳"
        $this->writeBeat($n->nodeId);
        return true;
    }

    /**
     * 心跳 = 覆写一个 20 字节的时间戳文件。
     *
     * 不校验节点身份是否还在（`is_file` 检查只是避免给不存在的节点留孤儿文件）：
     * 一个已经过期的节点文件还在，它复活时心跳一下就能重新算活着——这正是想要的，
     * 进程重启后不必重新注册。
     */
    public function heartbeat(int $nodeId): bool
    {
        if (!is_file($this->path($nodeId))) {
            return false;
        }
        return $this->writeBeat($nodeId);
    }

    /**
     * 心跳 = `touch`。**不校验节点是否注册过**——一个已经过期的节点文件还在，
     * 它复活时 touch 一下就重新算活着，这正是我们想要的（进程重启后不必重新注册）。
     */
    public function unregister(int $nodeId): bool
    {
        $path = $this->path($nodeId);
        $beat = $this->beatPath($nodeId);
        $ok = false;
        if (is_file($path)) {
            $ok = unlink($path);
        }
        if (is_file($beat)) {
            unlink($beat);
        }
        return $ok;
    }

    public function get(int $nodeId): ?NodeInfo
    {
        $path = $this->path($nodeId);
        if (!is_file($path)) {
            return null;
        }
        $info = $this->read($path);
        if ($info !== null) {
            $info->lastSeen = $this->seenMs($nodeId, $path);
        }
        return $info;
    }

    public function alive(int $ttlMs = 0): array
    {
        $ttl = $ttlMs > 0 ? $ttlMs : $this->ttlMs;
        $now = (int) (microtime(true) * 1000);
        $out = [];

        foreach ($this->files() as $path) {
            // 先从文件名拿 nodeId：过期的节点不必读身份文件，
            // 节点多时这一步省掉的 IO 是主要的
            $id = $this->idFromPath($path);
            if ($id <= 0) {
                continue;
            }
            $seen = $this->seenMs($id, $path);
            if ($now - $seen >= $ttl) {
                continue;   // 过期
            }
            $info = $this->read($path);
            if ($info === null) {
                continue;   // 坏文件：跳过而不是让整张表读不出来
            }
            $info->lastSeen = $seen;
            $out[] = $info;
        }
        return $out;
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->files() as $path) {
            $info = $this->read($path);
            if ($info === null) {
                continue;
            }
            $info->lastSeen = $this->seenMs($info->nodeId, $path);
            $out[] = $info;
        }
        return $out;
    }

    public function refresh(): bool
    {
        $cur = [];
        foreach ($this->alive() as $n) {
            // 指纹只含"身份"字段，不含 lastSeen——否则每次心跳都算变更，
            // 回调会被无意义地刷爆
            $cur[$n->nodeId] = $n->endpoint . "|" . $n->codec . "|" . $this->tagFingerprint($n);
        }

        if ($cur === $this->snapshot) {
            return false;
        }
        $this->snapshot = $cur;

        $cbs = $this->watchers;
        foreach ($cbs as $cb) {
            if ($cb instanceof \Closure) {
                $cb();
            }
        }
        return true;
    }

    public function onChanged(\Closure $cb): void
    {
        $this->watchers[] = $cb;
    }

    public function known(): int
    {
        return count($this->snapshot);
    }

    /** 当前快照的节点 id 列表（观测/调试用） */
    public function knownIds(): array
    {
        $out = [];
        foreach ($this->snapshot as $id => $fp) {
            $out[] = (int) $id;
        }
        return $out;
    }

    // ---------------------------------------------------------------- 内部

    /** @return array<int, string> 文件路径列表 */
    private function files(): array
    {
        $names = scandir($this->dir);
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $n) {
            $f = (string) $n;
            if (!str_ends_with($f, ".json")) {
                continue;   // 跳过 .tmp：写了一半的文件不是有效注册
            }
            $out[] = $this->dir . "/" . $f;
        }
        return $out;
    }

    private function read(string $path): ?NodeInfo
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === "") {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return null;
        }
        return NodeInfo::fromArray($d);
    }

    private function path(int $nodeId): string
    {
        return $this->dir . "/" . $nodeId . ".json";
    }

    /** 心跳文件：只有一个毫秒时间戳，独立于身份文件 */
    private function beatPath(int $nodeId): string
    {
        return $this->dir . "/" . $nodeId . ".beat";
    }

    private function writeBeat(int $nodeId): bool
    {
        $ms = (int) (microtime(true) * 1000);
        return file_put_contents($this->beatPath($nodeId), (string) $ms) !== false;
    }

    /**
     * 最后一次心跳（毫秒）。
     *
     * 优先读 `.beat`；读不出来（文件缺失/内容坏了）就回退到身份文件的 mtime。
     * 回退路径的精度只有秒，但那是"心跳文件都没了"的异常情况——
     * 宁可粗一点，也不要当作 0（那会立刻判死）。
     */
    private function seenMs(int $nodeId, string $jsonPath): int
    {
        $beat = $this->beatPath($nodeId);
        if (is_file($beat)) {
            $raw = file_get_contents($beat);
            if (is_string($raw)) {
                $t = trim($raw);
                if ($t !== "" && preg_match('/^\d+$/', $t) === 1) {
                    $v = (int) $t;
                    if ($v > 0) {
                        return $v;
                    }
                }
            }
        }
        $m = filemtime($jsonPath);
        return is_int($m) ? $m * 1000 : 0;
    }

    /** 从 `{id}.json` 反解 nodeId，避免为了判断过期而读文件内容 */
    private function idFromPath(string $path): int
    {
        $base = basename($path);
        if (!str_ends_with($base, ".json")) {
            return 0;
        }
        $num = substr($base, 0, -5);
        if ($num === "" || preg_match('/^\d+$/', $num) !== 1) {
            return 0;
        }
        return (int) $num;
    }

    private function tagFingerprint(NodeInfo $n): string
    {
        $out = "";
        foreach ($n->tags as $k => $v) {
            $out .= $k . "=" . $v . ";";
        }
        return $out;
    }
}
