<?php

namespace TSF\Http;

/**
 * HTTP 出站客户端（调第三方：支付回调、实名、推送、运营后台）。
 *
 * ## 为什么不是"Swoole 的协程客户端"
 *
 * 游戏服的主循环是 `Scheduler` 的 tick，不是协程。在 tick 里 `\Swoole\Coroutine\Http\Client`
 * 会因为没有协程上下文而报错；而 `Scheduler::tick` 恰恰是绝大多数业务代码跑的地方。
 * 所以这里用 curl —— 同步、阻塞、和 `pkg/redis` 的取舍一致。
 *
 * ## 阻塞说明（重要）
 *
 * 一次调用 = 一次网络往返，**整个 worker 都停在那里**。所以：
 *   - 调内网服务（< 1 ms）→ 可以用，但别在"每人每次操作"的路径上
 *   - 调公网（几十 ~ 几百 ms）→ **不要在消息热路径调**。要么丢到独立进程，
 *     要么接受"这一帧被卡住"
 *
 * 这条和 redis 是同一个道理，见 `pkg/redis` 里的实测注释。
 *
 * ## 一定要知道的一件事：环境代理
 *
 * curl 默认读 `http_proxy` / `https_proxy` 环境变量。实测（2026-11-27）在有
 * `http_proxy=http://127.0.0.1:7897` 的机器上，一个本机请求返回 **502、耗时 1.8 秒**，
 * 看起来像"curl 在 AOT 下不能用"。所以这里**默认显式清空代理**，
 * 要代理就自己传 `$proxy`。
 *
 * ## 不重试
 *
 * 重试会把阻塞时间翻倍，而调用方通常更清楚"这个请求值不值得重试"。需要就自己循环，
 * 或者用 `Scheduler::after()` 把重试挪到下一个 tick。
 */
final class Client
{
    private int $connectTimeoutMs;

    private int $timeoutMs;

    private string $proxy;

    public function __construct(int $connectTimeoutMs = 500, int $timeoutMs = 3000, string $proxy = "")
    {
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->timeoutMs = $timeoutMs;
        $this->proxy = $proxy;
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): Result
    {
        return $this->request("GET", $url, "", $headers);
    }

    /** @param array<string, string> $headers */
    public function post(string $url, string $body = "", array $headers = []): Result
    {
        return $this->request("POST", $url, $body, $headers);
    }

    /**
     * 发一个请求。失败不抛异常——返回的 `Result` 里 `error` 非空、`status` 为 0。
     *
     * 不抛异常是刻意的：调用方几乎总要写 `if (!$r->ok()) { 记日志 + 降级 }`，
     * 用异常会让每个调用点套一个 try，反而更容易漏。
     *
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, string $body = "", array $headers = []): Result
    {
        $ch = curl_init($url);
        if (!is_object($ch)) {
            return new Result(0, "", "curl_init 失败（URL 非法？）", 0);
        }

        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ": " . $v;
        }
        if ($body !== "" && !isset($headers["Content-Type"])) {
            $lines[] = "Content-Type: application/x-www-form-urlencoded";
        }

        // 用 microtime 而不是 hrtime：后者在 AOT 下的可用性没验证过
        $started = microtime(true);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->connectTimeoutMs);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
        // 默认清空代理：curl 会读环境变量，在配了代理的机器上会把内网请求也带出去
        curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        // 不跟随跳转：支付/回调这类接口跟着 302 走会命中错误的地址，让调用方自己判断
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($lines !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
        }
        if ($body !== "") {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $elapsedMs = (int) ((microtime(true) - $started) * 1000.0);

        $text = is_string($raw) ? $raw : "";

        // 空 body 不是错误（204 就是空的）；只有 curl_error 非空才是传输层失败
        return new Result(is_int($status) ? $status : 0, $text, $err, $elapsedMs);
    }
}
