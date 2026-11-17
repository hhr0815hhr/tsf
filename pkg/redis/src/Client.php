<?php

namespace TSF\Redis;

/**
 * Redis 客户端：长连接 + 常用命令。
 *
 * ## 阻塞说明
 *
 * 这是**阻塞式**客户端（fwrite/fread），和 `pkg/db` 的 PDO 一致。
 * 单次调用在局域网里是亚毫秒级，但**不要在 Scheduler 的热路径里一次做几十个命令**——
 * 调度器是单线程的，卡住它就是卡住整个进程。
 *
 * 大量调用有两条路：
 *   1. 放到独立进程（和世界服务分开），用消息通信
 *   2. 用 `Swoole\Runtime::enableCoroutine()` + `go()` 把它挂到协程里
 *
 * ## 连接复用
 *
 * 长连接，命令发完不关。写失败时会尝试重连一次（应对 redis 重启、空闲被踢）。
 */
final class Client
{
    private string $host;

    private int $port;

    private string $pass;

    private int $db;

    private float $timeout;

    /** @var resource|null 裸变量：AOT 里 resource 不能做类型声明，用注释表达 */
    private $fp = null;

    public function __construct(string $host = "127.0.0.1", int $port = 6379, string $pass = "", int $db = 0, float $timeout = 1.5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->pass = $pass;
        $this->db = $db;
        $this->timeout = $timeout;
    }

    public function connect(): bool
    {
        if (is_resource($this->fp)) {
            return true;
        }
        $fp = @fsockopen("tcp://" . $this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!is_resource($fp)) {
            return false;
        }
        stream_set_timeout($fp, (int) $this->timeout);
        // 立刻发出去：redis 命令都是一问一答，攒批没有收益
        stream_set_write_buffer($fp, 0);
        $this->fp = $fp;

        if ($this->pass !== "") {
            $r = $this->cmd("AUTH", $this->pass);
            if ($r === null) {
                $this->close();
                return false;
            }
        }
        if ($this->db > 0) {
            $this->cmd("SELECT", (string) $this->db);
        }
        return true;
    }

    public function close(): void
    {
        if (is_resource($this->fp)) {
            @fclose($this->fp);
        }
        $this->fp = null;
    }

    public function isConnected(): bool
    {
        return is_resource($this->fp);
    }

    /**
     * 发一条命令。
     *
     * @return string|int|array|null
     */
    public function cmd(string ...$args): string|int|array|null
    {
        $r = $this->attempt($args);
        if ($r === false) {
            // 写失败：重连一次再试（redis 重启或空闲被踢都很常见）
            $this->close();
            if (!$this->connect()) {
                return null;
            }
            $r = $this->attempt($args);
            if ($r === false) {
                return null;
            }
        }
        return $r;
    }

    /**
     * @param array<int, string> $args
     * @return string|int|array|null|false false 表示"连不上/写失败"，null 是合法回复（nil）
     */
    private function attempt(array $args): string|int|array|null|bool
    {
        if (!is_resource($this->fp)) {
            if (!$this->connect()) {
                return false;
            }
        }
        $fp = $this->fp;
        if (!is_resource($fp)) {
            return false;
        }
        $payload = Resp::encode($args);
        $n = @fwrite($fp, $payload);
        if (!is_int($n) || $n !== strlen($payload)) {
            return false;
        }
        return Resp::read($fp);
    }

    // ---------------- 字符串 ----------------

    public function get(string $key): ?string
    {
        $r = $this->cmd("GET", $key);
        return is_string($r) ? $r : null;
    }

    public function set(string $key, string $val, int $ttlSec = 0): bool
    {
        if ($ttlSec > 0) {
            return $this->cmd("SETEX", $key, (string) $ttlSec, $val) === "OK";
        }
        return $this->cmd("SET", $key, $val) === "OK";
    }

    public function del(string ...$keys): int
    {
        $args = ["DEL"];
        foreach ($keys as $k) {
            $args[] = $k;
        }
        $r = $this->cmd(...$args);
        return is_int($r) ? $r : 0;
    }

    public function exists(string $key): bool
    {
        return $this->cmd("EXISTS", $key) === 1;
    }

    public function expire(string $key, int $sec): bool
    {
        return $this->cmd("EXPIRE", $key, (string) $sec) === 1;
    }

    public function incrBy(string $key, int $n): int
    {
        $r = $this->cmd("INCRBY", $key, (string) $n);
        return is_int($r) ? $r : 0;
    }

    // ---------------- 哈希 ----------------

    public function hSet(string $key, string $field, string $val): int
    {
        $r = $this->cmd("HSET", $key, $field, $val);
        return is_int($r) ? $r : 0;
    }

    public function hGet(string $key, string $field): ?string
    {
        $r = $this->cmd("HGET", $key, $field);
        return is_string($r) ? $r : null;
    }

    /** @return array<string, string> */
    public function hGetAll(string $key): array
    {
        $r = $this->cmd("HGETALL", $key);
        $out = [];
        if (is_array($r)) {
            $n = count($r);
            $i = 0;
            while ($i + 1 < $n) {
                $f = $r[$i];
                $v = $r[$i + 1];
                if (is_string($f) && is_string($v)) {
                    $out[$f] = $v;
                }
                $i += 2;
            }
        }
        return $out;
    }

    public function hDel(string $key, string $field): int
    {
        $r = $this->cmd("HDEL", $key, $field);
        return is_int($r) ? $r : 0;
    }

    public function hIncrBy(string $key, string $field, int $n): int
    {
        $r = $this->cmd("HINCRBY", $key, $field, (string) $n);
        return is_int($r) ? $r : 0;
    }

    // ---------------- 有序集合（排行榜） ----------------

    public function zAdd(string $key, float $score, string $member): int
    {
        $r = $this->cmd("ZADD", $key, (string) $score, $member);
        return is_int($r) ? $r : 0;
    }

    public function zIncrBy(string $key, float $n, string $member): float
    {
        $r = $this->cmd("ZINCRBY", $key, (string) $n, $member);
        return is_string($r) ? (float) $r : 0.0;
    }

    /**
     * 排名区间。
     *
     * @return array<int, string> 只要成员；$withScores 时返回 ["member" => score] 形式改用 zRangeWithScores
     */
    public function zRange(string $key, int $start, int $stop): array
    {
        $r = $this->cmd("ZRANGE", $key, (string) $start, (string) $stop);
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

    /** 从高到低（排行榜常用） */
    public function zRevRange(string $key, int $start, int $stop): array
    {
        $r = $this->cmd("ZREVRANGE", $key, (string) $start, (string) $stop);
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

    /** @return array<int, array<int, string>> [[member, score], ...]，已按分数从高到低 */
    public function zRevRangeWithScores(string $key, int $start, int $stop): array
    {
        $r = $this->cmd("ZREVRANGE", $key, (string) $start, (string) $stop, "WITHSCORES");
        $out = [];
        if (is_array($r)) {
            $n = count($r);
            $i = 0;
            while ($i + 1 < $n) {
                $m = $r[$i];
                $s = $r[$i + 1];
                if (is_string($m) && is_string($s)) {
                    $out[] = [$m, $s];
                }
                $i += 2;
            }
        }
        return $out;
    }

    public function zScore(string $key, string $member): ?float
    {
        $r = $this->cmd("ZSCORE", $key, $member);
        return is_string($r) ? (float) $r : null;
    }

    /** 我的排名（从高到低，0 起） */
    public function zRevRank(string $key, string $member): ?int
    {
        $r = $this->cmd("ZREVRANK", $key, $member);
        return is_int($r) ? $r : null;
    }

    /** 区间内的成员数 */
    public function zCard(string $key): int
    {
        $r = $this->cmd("ZCARD", $key);
        return is_int($r) ? $r : 0;
    }

    // ---------------- 其他 ----------------

    public function ping(): bool
    {
        return $this->cmd("PING") === "PONG";
    }
}
