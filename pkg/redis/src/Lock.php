<?php

namespace TSF\Redis;

/**
 * 基于 redis 的分布式锁。
 *
 * ## 比"自己写 SETNX + DEL"多出来的三件事
 *
 * 1. **释放前校验持有者**。`GET` 再 `DEL` 不是原子的：两步之间锁可能已经过期、
 *    被另一个人拿到，你的 `DEL` 就删了别人的锁。这里用 Lua 把比较和删除合成一次。
 * 2. **续期同样校验持有者**。长任务不能在持有期被抢走。
 * 3. **租期由 redis 的 `PX` 保证**。进程崩了锁自己会消失，不需要额外的心跳清理。
 *
 * 这三条就是全部价值，少了任何一条都不叫分布式锁。
 *
 * ## 不提供"等锁"
 *
 * `acquire(waitMs)` 这种接口在同步客户端上等于**阻塞 worker**——一次 redis 调用
 * 就是一次 RTT，跨公网时会把 1 ms 的 tick 拖成 31 ms（见 `Client` 的注释）。
 * 所以这里只做"试一次"：拿不到就让调用方用定时器重试，不会把进程挂住。
 *
 * ## 租期怎么定
 *
 * 比"最长一次持有"长，比"进程崩了之后愿意等多久"短。500 ms 的临界区配 5000 ms
 * 租期是常见比例；超过租期的任务用 `extend()` 续，续失败说明已经不再持有，
 * 应该放弃这次临界区而不是硬写。
 *
 * ## 一个仍然存在的窗口
 *
 * redis 主从切换时，锁可能"在旧主上加了、新主上没有"（这是 redis 锁的经典问题，
 * Redlock 也只是缓解）。这里的定位是**防止并发的重复结算/重复发奖**，不是强一致的
 * 互斥原语；真正不能重复的操作，最终仍要靠数据库唯一索引兜底。
 */
final class Lock
{
    /** 比较 token 后删除：只有自己加的锁才删得掉 */
    private const UNLOCK = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";

    /** 比较 token 后改租期 */
    private const EXTEND = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('PEXPIRE', KEYS[1], ARGV[2]) else return 0 end";

    private Client $redis;

    /** token 的唯一性来自 pid + 微秒 + 自增序号，不依赖 random_*（AOT 下未验证） */
    private static int $seq = 0;

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    /**
     * 试一次。成功返回 token（释放/续期要带上），失败返回空串。
     *
     * 空串是"没拿到"，不是"出错了"——redis 不可达时 `cmd()` 也返回 null，同样按没拿到处理。
     */
    public function acquire(string $key, int $ttlMs = 5000): string
    {
        if ($ttlMs <= 0) {
            throw new \InvalidArgumentException("锁的租期必须 > 0，收到 " . $ttlMs);
        }
        if ($key === "") {
            throw new \InvalidArgumentException("锁的 key 不能为空");
        }

        $token = $this->newToken();
        // NX 与 PX 必须在同一条 SET 里才是原子的；分成两条命令中间会留窗口。
        // 用 cmdBool 而不是 cmd：rawCommand 对 SET 返回 true，cmd 的类型收窄会把它
        // 吞成 null，看起来和"连不上"一样（第一版就是在这里永远拿不到锁）。
        $ok = $this->redis->cmdBool("SET", $key, $token, "NX", "PX", (string) $ttlMs);
        return $ok ? $token : "";
    }

    /**
     * 只释放自己持有的锁。
     *
     * 返回 false 有两种情况，都意味着"你手上的锁已经不在了"：过期了，或被别人拿到了。
     * **不要**因为 false 就重试删除——那会删掉别人的锁。
     */
    public function release(string $key, string $token): bool
    {
        if ($key === "" || $token === "") {
            return false;
        }
        return $this->redis->cmd("EVAL", self::UNLOCK, "1", $key, $token) === 1;
    }

    /**
     * 续期。返回 false 表示已经不再持有（过期或被抢），调用方应放弃当前临界区。
     */
    public function extend(string $key, string $token, int $ttlMs = 5000): bool
    {
        if ($key === "" || $token === "" || $ttlMs <= 0) {
            return false;
        }
        return $this->redis->cmd("EVAL", self::EXTEND, "1", $key, $token, (string) $ttlMs) === 1;
    }

    /** 当前持有者的 token（空串 = 没人持有）。只用于排障，不要拿它做判断。 */
    public function owner(string $key): string
    {
        $v = $this->redis->get($key);
        return is_string($v) ? $v : "";
    }

    private function newToken(): string
    {
        self::$seq = self::$seq + 1;
        return md5(getmypid() . ":" . (string) microtime(true) . ":" . (string) self::$seq);
    }
}
