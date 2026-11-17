<?php

namespace TSF\Redis;

/**
 * Redis 客户端：包装 php-redis 扩展（`\Redis`）。
 *
 * ## 为什么用扩展而不是手写 RESP
 *
 * 一开始这里是手写的 RESP2 实现（因为不确定 AOT 运行时有没有 redis 扩展）。
 * 实测（tests/ext-probe）发现扩展在 AOT 产物里是可用的：
 *
 *     class Redis              → 有
 *     connect/auth/ping        → 全部正常
 *
 * 那就没必要维护两套协议实现了 —— 扩展更成熟（连接池、pipeline、集群、错误码），
 * 手写版只在"运行时没有扩展"时才有意义，而那种环境我们不会部署。
 *
 * ## 阻塞说明（重要）
 *
 * `\Redis` 的调用是**同步阻塞**的。实测在公网 redis（RTT 31ms）上，
 * 一次 PING 会让 1ms 的 Scheduler tick 错过 31 轮 —— 也就是说**整个 worker 卡 31ms**。
 *
 * 有人会想"开 `Swoole\Runtime::enableCoroutine` 不就行了吗"。实测过，**不行**：
 *
 *     Timer 回调（非协程）→ Scheduler::tick → $redis->ping()
 *     ↑ hook 只对**协程内**的阻塞调用生效，Timer 回调不是协程
 *     实测：15 次 redis 调用 → 14 次 >20ms 的间隔尖峰，最大 42.99ms（RTT 31.2ms）
 *
 * 所以在当前架构下：
 *   - redis **与游戏服同机/同内网**（RTT < 0.5ms）→ 直接用，没问题
 *   - redis **跨公网** → 不要在消息热路径里调用，要么走独立进程，要么等框架支持协程化
 *
 * 单次调用的成本约等于一次 RTT。批量场景请用 pipeline（见 pipeline 方法）。
 */
final class Client
{
    private string $host;

    private int $port;

    private string $pass;

    private int $db;

    private float $timeout;

    private \Redis $r;

    private bool $connected = false;

    public function __construct(string $host = "127.0.0.1", int $port = 6379, string $pass = "", int $db = 0, float $timeout = 1.5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->pass = $pass;
        $this->db = $db;
        $this->timeout = $timeout;
        $this->r = new \Redis();
    }

    public function connect(): bool
    {
        if ($this->connected) {
            try {
                if ($this->r->isConnected()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // 扩展在某些状态会抛，落到下面重连
            }
            $this->connected = false;
        }

        try {
            $ok = $this->r->connect($this->host, $this->port, $this->timeout);
        } catch (\Throwable $e) {
            return false;
        }
        if ($ok !== true) {
            return false;
        }

        if ($this->pass !== "") {
            try {
                $this->r->auth($this->pass);
            } catch (\Throwable $e) {
                return false;
            }
        }
        if ($this->db > 0) {
            try {
                $this->r->select($this->db);
            } catch (\Throwable $e) {
                return false;
            }
        }
        $this->connected = true;
        return true;
    }

    public function close(): void
    {
        try {
            $this->r->close();
        } catch (\Throwable $e) {
            // 忽略：关连接失败不影响后续（下次 connect 会重建）
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        if (!$this->connected) {
            return false;
        }
        try {
            return $this->r->isConnected();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 发一条原始命令。
     *
     * 兜底入口：扩展没封装成方法的命令都走这里（比如 SETNX 带复杂选项、MODULE、自定义命令）。
     *
     * @return string|int|array|null
     */
    public function cmd(string ...$args): string|int|array|null
    {
        if (!$this->connect()) {
            return null;
        }
        try {
            $r = $this->r->rawCommand(...$args);
        } catch (\Throwable $e) {
            return null;
        }
        if (is_string($r) || is_int($r) || is_array($r)) {
            return $r;
        }
        return null;
    }

    // ---------------- 字符串 ----------------

    public function get(string $key): ?string
    {
        if (!$this->connect()) {
            return null;
        }
        try {
            $r = $this->r->get($key);
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($r) ? $r : null;
    }

    public function set(string $key, string $val, int $ttlSec = 0): bool
    {
        if (!$this->connect()) {
            return false;
        }
        try {
            if ($ttlSec > 0) {
                return $this->r->setex($key, $ttlSec, $val) === true;
            }
            return $this->r->set($key, $val) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** SETNX：不存在才设，返回是否设置成功（做锁的基础） */
    public function setNx(string $key, string $val, int $ttlSec = 0): bool
    {
        if (!$this->connect()) {
            return false;
        }
        try {
            if ($ttlSec > 0) {
                $r = $this->r->set($key, $val, ["nx", "ex" => $ttlSec]);
            } else {
                $r = $this->r->setnx($key, $val);
            }
        } catch (\Throwable $e) {
            return false;
        }
        return $r === true;
    }

    public function del(string ...$keys): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->del(...$keys);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    public function exists(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }
        try {
            return $this->r->exists($key) >= 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function expire(string $key, int $sec): bool
    {
        if (!$this->connect()) {
            return false;
        }
        try {
            return $this->r->expire($key, $sec) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function incrBy(string $key, int $n): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->incrBy($key, $n);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    // ---------------- 哈希 ----------------

    public function hSet(string $key, string $field, string $val): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->hSet($key, $field, $val);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    public function hGet(string $key, string $field): ?string
    {
        if (!$this->connect()) {
            return null;
        }
        try {
            $r = $this->r->hGet($key, $field);
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($r) ? $r : null;
    }

    /** @return array<string, string> */
    public function hGetAll(string $key): array
    {
        if (!$this->connect()) {
            return [];
        }
        try {
            $r = $this->r->hGetAll($key);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($r)) {
            foreach ($r as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    public function hDel(string $key, string $field): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->hDel($key, $field);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    public function hIncrBy(string $key, string $field, int $n): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->hIncrBy($key, $field, $n);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    // ---------------- 有序集合（排行榜） ----------------

    public function zAdd(string $key, float $score, string $member): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->zAdd($key, $score, $member);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    public function zIncrBy(string $key, float $n, string $member): float
    {
        if (!$this->connect()) {
            return 0.0;
        }
        try {
            $r = $this->r->zIncrBy($key, $n, $member);
        } catch (\Throwable $e) {
            return 0.0;
        }
        if (is_float($r)) {
            return $r;
        }
        return is_string($r) ? (float) $r : 0.0;
    }

    /** @return array<int, string> 从低到高 */
    public function zRange(string $key, int $start, int $stop): array
    {
        if (!$this->connect()) {
            return [];
        }
        try {
            $r = $this->r->zRange($key, $start, $stop);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($r)) {
            foreach ($r as $v) {
                if (is_string($v)) {
                    $out[] = $v;
                }
            }
        }
        return $out;
    }

    /** @return array<int, string> 从高到低（排行榜常用） */
    public function zRevRange(string $key, int $start, int $stop): array
    {
        if (!$this->connect()) {
            return [];
        }
        try {
            $r = $this->r->zRevRange($key, $start, $stop);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($r)) {
            foreach ($r as $v) {
                if (is_string($v)) {
                    $out[] = $v;
                }
            }
        }
        return $out;
    }

    /** @return array<int, array<int, string>> [[member, score], ...]，从高到低 */
    public function zRevRangeWithScores(string $key, int $start, int $stop): array
    {
        if (!$this->connect()) {
            return [];
        }
        try {
            $r = $this->r->zRevRange($key, $start, $stop, true);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($r)) {
            // 扩展在 withscores 模式下返回 [member => score]
            foreach ($r as $member => $score) {
                if (is_string($member)) {
                    $out[] = [$member, (string) $score];
                }
            }
        }
        return $out;
    }

    public function zScore(string $key, string $member): ?float
    {
        if (!$this->connect()) {
            return null;
        }
        try {
            $r = $this->r->zScore($key, $member);
        } catch (\Throwable $e) {
            return null;
        }
        if (is_float($r)) {
            return $r;
        }
        return is_string($r) ? (float) $r : null;
    }

    public function zRevRank(string $key, string $member): ?int
    {
        if (!$this->connect()) {
            return null;
        }
        try {
            $r = $this->r->zRevRank($key, $member);
        } catch (\Throwable $e) {
            return null;
        }
        return is_int($r) ? $r : null;
    }

    public function zCard(string $key): int
    {
        if (!$this->connect()) {
            return 0;
        }
        try {
            $r = $this->r->zCard($key);
        } catch (\Throwable $e) {
            return 0;
        }
        return is_int($r) ? $r : 0;
    }

    // ---------------- 其他 ----------------

    public function ping(): bool
    {
        if (!$this->connect()) {
            return false;
        }
        try {
            $r = $this->r->ping();
        } catch (\Throwable $e) {
            return false;
        }
        return $r === true || $r === "+PONG" || $r === "PONG";
    }

    /**
     * 批量执行：把一批命令一次发出去、一次读回来。
     *
     * 跨公网时这一条比什么都重要 —— 10 个 GET 走 pipeline 只付一次 RTT，
     * 不走 pipeline 就是 10 次 RTT（310ms vs 31ms）。
     *
     * @param array<int, array<int, string>> $commands 每项是一条命令的参数列表
     * @return array<int, string|int|array|null>
     */
    public function pipeline(array $commands): array
    {
        if (!$this->connect()) {
            return [];
        }
        try {
            $pipe = $this->r->multi(\Redis::PIPELINE);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$pipe instanceof \Redis) {
            return [];
        }
        $i = 0;
        foreach ($commands as $one) {
            $parts = [];
            foreach ($one as $a) {
                $parts[] = (string) $a;
            }
            if ($parts === []) {
                continue;
            }
            try {
                $pipe->rawCommand(...$parts);
            } catch (\Throwable $e) {
                // 单条失败不影响整批
            }
            $i++;
        }
        try {
            $res = $pipe->exec();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($res)) {
            foreach ($res as $v) {
                if (is_string($v) || is_int($v) || is_array($v)) {
                    $out[] = $v;
                } else {
                    $out[] = null;
                }
            }
        }
        return $out;
    }
}
