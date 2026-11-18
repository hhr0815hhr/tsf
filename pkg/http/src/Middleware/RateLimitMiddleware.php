<?php

namespace TSF\Http\Middleware;

use TSF\Http\Middleware;
use TSF\Http\Request;
use TSF\Http\Response;

/**
 * 按 IP 的令牌桶限流。
 *
 * ## 为什么按 IP 而不是按 uid
 *
 * 这个中间件在 AuthMiddleware **之前**跑（限流应该在验 token 之前，
 * 否则伪造 token 的请求也会消耗验签 CPU），那时还没有 uid。
 *
 * ## 状态在静态属性里
 *
 * 单 worker 下没问题。多 worker（worker_num > 1）时每个进程各有一份桶，
 * 实际限流是按「worker 数 × rate」放行 —— 需要全局精确限流时应该换成 Redis。
 * 这个实现是给登录服挡刷子用的，模糊一点没关系。
 */
final class RateLimitMiddleware implements Middleware
{
    /** @var array<string, array{tokens: float, at: float}> */
    private static array $buckets = [];

    private static float $lastGc = 0.0;

    public function __construct(
        private int $ratePerSec = 20,
        private int $burst = 40,
    ) {
    }

    public function handle(Request $req): ?Response
    {
        $key = $req->remoteIp !== "" ? $req->remoteIp : "unknown";
        $now = microtime(true);

        $bucket = self::$buckets[$key] ?? ["tokens" => (float) $this->burst, "at" => $now];

        // 按流逝时间补令牌
        $elapsed = $now - $bucket["at"];
        if ($elapsed > 0) {
            $bucket["tokens"] = min((float) $this->burst, $bucket["tokens"] + $elapsed * $this->ratePerSec);
            $bucket["at"] = $now;
        }

        if ($bucket["tokens"] < 1.0) {
            $retry = (int) ceil((1.0 - $bucket["tokens"]) / max(1, $this->ratePerSec));
            return (new Response(
                429,
                [
                    "content-type" => "application/json; charset=utf-8",
                    "retry-after" => (string) max(1, $retry),
                ],
                "{\"code\":42900,\"msg\":\"too many requests\"}"
            ));
        }

        $bucket["tokens"] -= 1.0;
        self::$buckets[$key] = $bucket;

        $this->gc($now);
        return null;
    }

    /** 每隔一段时间清掉已经补满的桶，避免 IP 多了内存一直涨 */
    private function gc(float $now): void
    {
        if ($now - self::$lastGc < 60) {
            return;
        }
        self::$lastGc = $now;
        foreach (self::$buckets as $ip => $b) {
            // 完全补满说明这个 IP 很久没来，删掉不影响限流
            if ($now - $b["at"] > 120) {
                unset(self::$buckets[$ip]);
            }
        }
    }
}
