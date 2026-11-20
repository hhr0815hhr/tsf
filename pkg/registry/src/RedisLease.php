<?php

namespace TSF\Registry;

use TSF\Handoff\Lease;

/**
 * Redis 版的归属租约：跨节点真正互斥。
 *
 * ## 为什么每次操作都要 Lua
 *
 * 三个动作都必须是**原子**的，而不是"先查后写"：
 *
 * | 动作 | 非原子写法的后果 |
 * |:--|:--|
 * | 取 | 两个节点同时 GET 到 nil，然后都 SET，双方都以为自己拿到了 |
 * | 续 | A 的租约过期后被 B 拿走，A 的 `SET XX PX` 会把**别人的**锁续上 |
 * | 放 | A 过期后 B 拿到，A 直接 DEL 会把 B 的锁删掉 |
 *
 * 续和放看起来"只是善后"，实际上比取更危险：取错了立刻能看出来（两边同时搬），
 * 续/放错了要等下一次事故才暴露。所以这两个也用 Lua 做"值是我的才动"。
 *
 * ## token 是什么
 *
 * `节点号:进程号`。租约的值必须能区分**实例**而不只是节点——同一节点上重启一次，
 * 老进程的续期请求不该被认成新进程的。
 */
final class RedisLease implements Lease
{
    /** 值相等才续期（返回 1/0） */
    private const LUA_RENEW = 'if redis.call("GET", KEYS[1]) == ARGV[1] then '
        . 'return redis.call("PEXPIRE", KEYS[1], ARGV[2]) else return 0 end';

    /** 值相等才删除 */
    private const LUA_RELEASE = 'if redis.call("GET", KEYS[1]) == ARGV[1] then '
        . 'return redis.call("DEL", KEYS[1]) else return 0 end';

    private Resp $r;

    private string $prefix;

    private string $token;

    public function __construct(
        string $host = "127.0.0.1",
        int $port = 6379,
        string $pass = "",
        int $db = 0,
        string $prefix = "tsf",
        int $nodeId = 1
    ) {
        $this->r = new Resp($host, $port, $pass, $db);
        $this->prefix = $prefix === "" ? "tsf" : $prefix;
        $this->token = "n" . $nodeId . ":p" . posix_getpid();
    }

    public function connect(): bool
    {
        return $this->r->connect();
    }

    public function close(): void
    {
        $this->r->close();
    }

    public function token(): string
    {
        return $this->token;
    }

    public function tryAcquire(string $key, int $ttlMs): bool
    {
        $ttl = $ttlMs > 0 ? $ttlMs : 1;
        $out = $this->r->cmd("SET", $this->key($key), $this->token, "NX", "PX", (string) $ttl);
        // 成功返回 +OK；已被占用返回 nil
        return is_string($out) && $out === "OK";
    }

    public function renew(string $key, int $ttlMs): bool
    {
        $ttl = $ttlMs > 0 ? $ttlMs : 1;
        $out = $this->r->cmd("EVAL", self::LUA_RENEW, "1", $this->key($key), $this->token, (string) $ttl);
        return is_int($out) && $out > 0;
    }

    public function release(string $key): bool
    {
        $out = $this->r->cmd("EVAL", self::LUA_RELEASE, "1", $this->key($key), $this->token);
        return is_int($out);
    }

    /** 本地视角：直接问 Redis 这个 key 现在是不是我的 */
    public function holds(string $key): bool
    {
        $v = $this->r->cmd("GET", $this->key($key));
        return is_string($v) && $v === $this->token;
    }

    private function key(string $key): string
    {
        return $this->prefix . ":lease:" . $key;
    }
}
