<?php

namespace TSF\Registry;

use TSF\Discovery\Contract\Registry;
use TSF\Discovery\NodeInfo;

/**
 * 用 Redis 当节点注册表，替代只能跑单机的 `FileRegistry`。
 *
 * ## 为什么需要它
 *
 * `FileRegistry` 靠 `scandir` 读一个本地目录。跨机器时那个目录必须共享
 * （NFS/SMB），而共享文件系统的心跳语义很糟：mtime 精度、缓存一致性、
 * 锁竞争都不是为"每秒一次心跳"设计的。评论里那句"上万节点就该换中心化注册表"
 * 说的就是这里。
 *
 * ## 存活判定：TTL 兜底 + lastSeen 精判
 *
 * 两条一起用，各管一半：
 *
 * | 机制 | 作用 | 失效场景 |
 * |:--|:--|:--|
 * | Redis 键 TTL（`PX ttlMs`） | 进程被 `kill -9` 后**自动消失**，无残留 | — |
 * | JSON 里的 `seen` 毫秒时间戳 | 支持"活着但太久没心跳"的精细判定（调用方传更小的 ttl） | 依赖两端时钟同源 |
 *
 * 只靠 TTL 的话，"3 秒没心跳"就只能是"键还在不在"，没法更细；
 * 只靠 seen 的话，崩溃进程的键会永久残留，得另做 GC。
 *
 * ## 心跳是 1 次往返，读是 2 次
 *
 * 心跳不读改写，而是用**本地缓存的 NodeInfo** 重写整个值（`SET ... PX`）：
 * 值只有一百多字节，一次 RTT 换掉一次读，划算。代价是注册表进程自己必须
 * 记住自己注册过什么——所以 `heartbeat()` 在缓存缺失时会退化成读改写。
 *
 * ## 阻塞语义（必须知道）
 *
 * 这里的每条命令都是同步阻塞的。在 `Service::onMessage` 里调它，整个 worker
 * 会被挡住一个 RTT（本机 ~0.2ms，跨机看网络）。所以：
 *
 * - 单机部署：把 `refresh()` 放在低频定时器里（1s 一次），影响可忽略；
 * - 跨机部署：先开 `Scheduler::setCoroutine(true)` + `Runtime::enableCoroutine`，
 *   让阻塞落在协程上，否则一个慢 Redis 会拖死所有服务。
 */
final class RedisRegistry implements Registry
{
    private Resp $r;

    private int $ttlMs;

    private string $prefix;

    /** @var array<int, NodeInfo> 本进程注册过的节点：心跳时用来重建值，省一次读 */
    private array $mine = [];

    /** @var array<int, \Closure> */
    private array $watchers = [];

    /** @var array<int, string> nodeId => 身份指纹（不含 lastSeen） */
    private array $snapshot = [];

    /** @var array<string, int> */
    private array $stat = [
        "writes" => 0,
        "reads" => 0,
        "errors" => 0,
    ];

    public function __construct(
        string $host = "127.0.0.1",
        int $port = 6379,
        string $pass = "",
        int $db = 0,
        int $ttlMs = 3000,
        string $prefix = "tsf"
    ) {
        // 100ms 下限与 FileRegistry 一致：再小的话一次网络往返就能越过它，
        // 判定会变成"随机死亡"
        $this->ttlMs = $ttlMs >= 100 ? $ttlMs : 100;
        $this->prefix = $prefix === "" ? "tsf" : $prefix;
        $this->r = new Resp($host, $port, $pass, $db);
    }

    /** 建连（不自动调，让调用方能决定失败怎么办） */
    public function connect(): bool
    {
        return $this->r->connect();
    }

    public function close(): void
    {
        $this->r->close();
    }

    public function ttlMs(): int
    {
        return $this->ttlMs;
    }

    public function register(NodeInfo $n): bool
    {
        $n->lastSeen = $this->nowMs();
        $json = $this->encode($n);
        if ($json === "") {
            return false;
        }
        try {
            $this->r->cmd("SET", $this->key($n->nodeId), $json, "PX", (string) $this->ttlMs);
            $this->r->cmd("SADD", $this->setKey(), (string) $n->nodeId);
            $this->stat["writes"] += 2;
        } catch (\Throwable $e) {
            $this->stat["errors"]++;
            return false;
        }
        $this->mine[$n->nodeId] = $n;
        return true;
    }

    public function heartbeat(int $nodeId): bool
    {
        $info = $this->mine[$nodeId] ?? null;
        if ($info === null) {
            // 本进程没注册过（比如重启后接手旧键）：读回来再写，保持 joinedAt
            $info = $this->get($nodeId);
            if ($info === null) {
                return false;
            }
        }
        $info->lastSeen = $this->nowMs();
        $json = $this->encode($info);
        if ($json === "") {
            return false;
        }
        try {
            $this->r->cmd("SET", $this->key($nodeId), $json, "PX", (string) $this->ttlMs);
            $this->stat["writes"]++;
        } catch (\Throwable $e) {
            $this->stat["errors"]++;
            return false;
        }
        $this->mine[$nodeId] = $info;
        return true;
    }

    public function unregister(int $nodeId): bool
    {
        try {
            $n = $this->r->cmd("DEL", $this->key($nodeId));
            $this->r->cmd("SREM", $this->setKey(), (string) $nodeId);
            $this->stat["writes"] += 2;
        } catch (\Throwable $e) {
            $this->stat["errors"]++;
            return false;
        }
        unset($this->mine[$nodeId]);
        return is_int($n) && $n > 0;
    }

    public function get(int $nodeId): ?NodeInfo
    {
        $raw = $this->read("GET", $this->key($nodeId));
        if (!is_string($raw) || $raw === "") {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return null;
        }
        return NodeInfo::fromArray($d);
    }

    public function alive(int $ttlMs = 0): array
    {
        $ttl = $ttlMs > 0 ? $ttlMs : $this->ttlMs;
        $now = $this->nowMs();
        $out = [];
        foreach ($this->all() as $n) {
            if ($now - $n->lastSeen >= $ttl) {
                continue;
            }
            $out[] = $n;
        }
        return $out;
    }

    public function all(): array
    {
        $ids = $this->members();
        if ($ids === []) {
            return [];
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[] = $this->key($id);
        }
        $vals = $this->read("MGET", ...$keys);
        if (!is_array($vals)) {
            return [];
        }

        $out = [];
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $raw = $vals[$i] ?? null;
            if (!is_string($raw) || $raw === "") {
                continue;   // 键已过期
            }
            $d = json_decode($raw, true);
            if (!is_array($d)) {
                continue;   // 坏值：跳过而不是让整张表读不出来
            }
            $info = NodeInfo::fromArray($d);
            if ($info !== null) {
                $out[] = $info;
            }
        }
        return $out;
    }

    public function refresh(): bool
    {
        $cur = [];
        foreach ($this->alive() as $n) {
            // 指纹不含 lastSeen：否则每次心跳都算变更，回调会被无意义地刷爆
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

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["known"] = count($this->snapshot);
        $out["mine"] = count($this->mine);
        return $out;
    }

    // ---------------------------------------------------------------- 内部

    /** @return array<int> */
    private function members(): array
    {
        $raw = $this->read("SMEMBERS", $this->setKey());
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v) && $v !== "") {
                $id = (int) $v;
                if ($id > 0) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }

    /**
     * 读命令统一入口。
     *
     * 读失败**抛异常而不是返回空表**：空表会被上层理解成"所有节点都死了"，
     * 于是路由把所有请求判为 no-node——这比"报错"危险得多，因为它看起来
     * 像一次正常的集群缩容。
     *
     * @return string|int|array|null
     */
    private function read(string $cmd, string ...$args): string|int|array|null
    {
        try {
            $out = $this->r->cmd($cmd, ...$args);
            $this->stat["reads"]++;
            return $out;
        } catch (\Throwable $e) {
            $this->stat["errors"]++;
            throw new \RuntimeException("registry: " . $cmd . " 失败 —— " . $e->getMessage());
        }
    }

    private function key(int $nodeId): string
    {
        return $this->prefix . ":node:" . $nodeId;
    }

    private function setKey(): string
    {
        return $this->prefix . ":nodes";
    }

    private function encode(NodeInfo $n): string
    {
        $json = json_encode($n->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : "";
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
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
