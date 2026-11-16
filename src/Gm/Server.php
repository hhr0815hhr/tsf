<?php

namespace TSF\Gm;

use TSF\Kernel\Logger;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 独立的 GM / 后台管理通道：一个只监听本机的迷你 HTTP 服务。
 *
 * ```php
 * Scheduler::instance()->spawnOf(function (): Server {
 *     return new Server("127.0.0.1", 9701, $token);
 * });
 * ```
 *
 * ```bash
 * curl -s -X POST http://127.0.0.1:9701/gm \
 *      -H "X-GM-Token: $TOKEN" \
 *      -d '{"cmd":"player.ban","args":{"uid":1001,"reason":"外挂"}}'
 * ```
 *
 * ## 为什么不复用游戏的传输层
 *
 * 游戏的 `Transport` 走 Swoole（自己的进程模型和事件循环），而 GM 指令**必须能改游戏状态**
 * ——它得跑在 `Scheduler` 这同一个事件循环里。跨进程发指令要再造一套 RPC，还得处理
 * 目标节点是谁、玩家在哪个 shard。所以这里直接照 `Cluster` 的方式用裸 socket，
 * 在 tick 里 poll。
 *
 * ## 四条安全约束（都是"默认安全"而不是"记得配"）
 *
 * **1. token 为空就拒绝启动。** 不提供"方便调试的空口令模式"——那种模式一定会被
 * 带到线上，然后变成"谁扫到这个端口谁就能发奖"。
 *
 * **2. 默认只绑 127.0.0.1。** 要对外必须显式改 host，改的时候会看到这行注释。
 *
 * **3. token 走请求头，不走 URL。** URL 会出现在访问日志、浏览器历史、`ps` 的
 * 命令行里（`curl ...?token=xxx` 在 ps 里是明文）。
 *
 * **4. 鉴权失败要限流。** 否则这个端口就是个可以无限猜 token 的口令框。
 * 同一 IP 一分钟失败 10 次就拒绝（429），并记一条 warn。
 *
 * ## 为什么是"迷你 HTTP"而不是完整实现
 *
 * 只支持 POST /gm + GET /health，一律 `Connection: close`，不解析 chunked、
 * 不支持 keep-alive、不支持 HTTP/2。管理操作是低频的人手操作（一天几十次），
 * 完整 HTTP 带来的复杂度（状态机、分块、超时）没有收益。
 *
 * 代价要说清楚：**它不适合放在会收到公网流量的地方**。要那样用，前面应该挂 nginx。
 */
final class Server extends Service
{
    private const MSG_POLL = 60501;

    /** 请求体上限：管理指令的参数不该有 64 KB */
    private const MAX_BODY = 65536;

    private string $host;

    private int $port;

    private string $token;

    private int $pollMs;

    /** @var resource|null */
    private $server = null;

    /** @var Conn[] */
    private array $conns = [];

    private int $requests = 0;

    private int $okCount = 0;

    private int $failCount = 0;

    private int $authFailCount = 0;

    private int $rejectedCount = 0;

    private int $shortWrite = 0;

    /** @var array<string, array{0: int, 1: int}> ip => [失败次数, 窗口起点 ms] */
    private array $failByIp = [];

    private int $failWindowMs = 60000;

    private int $maxFailPerWindow = 10;

    private string $listenError = "";

    public function __construct(string $host, int $port, string $token, int $pollMs = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->token = $token;
        $this->pollMs = $pollMs > 0 ? $pollMs : 5;
    }

    /** 调整爆破阈值（测试用；默认 1 分钟 10 次） */
    public function setAuthFailLimit(int $maxPerWindow, int $windowMs): void
    {
        $this->maxFailPerWindow = $maxPerWindow > 0 ? $maxPerWindow : 1;
        $this->failWindowMs = $windowMs > 0 ? $windowMs : 60000;
    }

    public function onStart(): void
    {
        if ($this->token === "") {
            // 宁可起不来，也不要以空口令跑起来
            throw new \RuntimeException(
                "GM 通道必须配置 token。空口令的调试模式一旦上线，等于把发奖接口挂在公网上"
            );
        }

        $addr = "tcp://" . $this->host . ":" . $this->port;
        $srv = @stream_socket_server($addr, $errno, $errstr);
        if ($srv === false) {
            $this->listenError = $errstr . " (" . $errno . ")";
            throw new \RuntimeException("GM 通道监听失败 " . $addr . "：" . $this->listenError);
        }
        stream_set_blocking($srv, false);
        $this->server = $srv;
        $this->interval($this->pollMs, self::MSG_POLL);
        Logger::info("gm listening", ["addr" => $addr, "poll_ms" => $this->pollMs]);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== self::MSG_POLL) {
            return;
        }
        $this->poll();
    }

    public function onStop(): void
    {
        foreach ($this->conns as $c) {
            if ($c->sock !== null) {
                @fclose($c->sock);
            }
        }
        $this->conns = [];
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    public function stats(): array
    {
        return [
            "requests" => $this->requests,
            "ok" => $this->okCount,
            "fail" => $this->failCount,
            "auth_fail" => $this->authFailCount,
            "rejected" => $this->rejectedCount,
            "short_write" => $this->shortWrite,
            "conns" => count($this->conns),
            "listen_error" => $this->listenError,
        ];
    }

    // ───────────────────── 轮询 ─────────────────────

    private function poll(): void
    {
        if ($this->server === null) {
            return;
        }
        $now = Scheduler::nowMs();
        $this->acceptNew($now);

        foreach ($this->conns as $id => $c) {
            if ($c->sock === null) {
                unset($this->conns[$id]);
                continue;
            }
            $data = @fread($c->sock, 8192);
            if ($data === false || ($data === "" && feof($c->sock))) {
                $this->drop($id, $c);
                continue;
            }
            if ($data === "") {
                continue;
            }
            if (strlen($c->buf) + strlen($data) > self::MAX_BODY * 2) {
                $this->respond($c, 413, "{\"ok\":false,\"error\":\"请求太大\"}");
                $this->drop($id, $c);
                continue;
            }
            $c->buf .= $data;
            if ($this->tryServe($c)) {
                $this->drop($id, $c);
            }
        }

        // 只连不发：10 秒还没发完一个请求就踢掉，避免半开连接占着
        foreach ($this->conns as $id => $c) {
            if ($now - $c->at > 10000) {
                $this->drop($id, $c);
            }
        }
    }

    private function acceptNew(int $now): void
    {
        if ($this->server === null) {
            return;
        }
        // 一次最多接 32 个，避免被大量连接打满这一轮 tick
        for ($i = 0; $i < 32; $i++) {
            $sock = @stream_socket_accept($this->server, 0);
            if ($sock === false) {
                return;
            }
            $peer = @stream_socket_get_name($sock, true);
            $this->conns[] = new Conn($sock, $peer === false ? "?" : (string) $peer, $now);
        }
    }

    /** @param Conn $c */
    private function drop(int $id, $c): void
    {
        if ($c->sock !== null) {
            @fclose($c->sock);
            $c->sock = null;
        }
        unset($this->conns[$id]);
    }

    // ───────────────────── HTTP ─────────────────────

    /** 缓冲区里已经有完整请求就处理并返回 true */
    private function tryServe(Conn $c): bool
    {
        $end = strpos($c->buf, "\r\n\r\n");
        if ($end === false) {
            return false;
        }
        $head = substr($c->buf, 0, $end);
        $lines = explode("\r\n", $head);
        $first = isset($lines[0]) ? (string) $lines[0] : "";

        $parts = explode(" ", $first);
        if (count($parts) < 3) {
            $this->respond($c, 400, "{\"ok\":false,\"error\":\"请求行不合法\"}");
            return true;
        }
        $method = strtoupper($parts[0]);
        $path = $parts[1];

        $headers = [];
        $n = count($lines);
        for ($i = 1; $i < $n; $i++) {
            $line = $lines[$i];
            $p = strpos($line, ":");
            if ($p === false) {
                continue;
            }
            $k = strtolower(trim(substr($line, 0, $p)));
            $headers[$k] = trim(substr($line, $p + 1));
        }

        $len = 0;
        if (isset($headers["content-length"])) {
            $raw = $headers["content-length"];
            $len = ctype_digit($raw) ? (int) $raw : -1;
            if ($len < 0) {
                $this->respond($c, 400, "{\"ok\":false,\"error\":\"Content-Length 不合法\"}");
                return true;
            }
        }
        if ($len > self::MAX_BODY) {
            $this->respond($c, 413, "{\"ok\":false,\"error\":\"请求太大\"}");
            return true;
        }
        $bodyStart = $end + 4;
        if (strlen($c->buf) < $bodyStart + $len) {
            return false;   // body 还没收全，等下一轮
        }
        $body = substr($c->buf, $bodyStart, $len);

        $this->handle($c, $method, $path, $headers, $body);
        return true;
    }

    private function handle(Conn $c, string $method, string $path, array $headers, string $body): void
    {
        if ($path === "/health") {
            $this->respond($c, 200, "{\"ok\":true,\"data\":{\"up\":true}}");
            return;
        }
        if ($path !== "/gm") {
            $this->respond($c, 404, "{\"ok\":false,\"error\":\"没有这个接口\"}");
            return;
        }
        if ($method !== "POST") {
            $this->respond($c, 405, "{\"ok\":false,\"error\":\"只接受 POST\"}");
            return;
        }

        $ip = $this->ipOf($c->peer);
        $now = Scheduler::nowMs();
        if ($this->isBlocked($ip, $now)) {
            $this->rejectedCount++;
            $this->respond($c, 429, "{\"ok\":false,\"error\":\"鉴权失败次数过多，稍后再试\"}");
            return;
        }

        $given = isset($headers["x-gm-token"]) ? (string) $headers["x-gm-token"] : "";
        if ($given === "" && isset($headers["authorization"])) {
            $auth = (string) $headers["authorization"];
            if (strncmp($auth, "Bearer ", 7) === 0) {
                $given = substr($auth, 7);
            }
        }
        if (!$this->tokenMatches($given)) {
            $this->authFailCount++;
            $this->noteAuthFail($ip, $now);
            // 响应里不区分"没带 token"和"token 不对"：少给爆破者一点信息
            Logger::warn("gm auth failed", ["peer" => $c->peer]);
            $this->respond($c, 401, "{\"ok\":false,\"error\":\"鉴权失败\"}");
            return;
        }

        $this->requests++;
        // 必须先看**原文**是不是以 `{` 开头：`json_decode($body, true)` 把 `{}` 和 `[]`
        // 都解成数组，光看返回值分不出来，于是 `[1,2,3]` 会被当成"没有 cmd 的合法请求"，
        // 回 200 + ok:false。发数组过来是协议错误，应当是 400
        $trimmed = ltrim($body);
        if ($trimmed === "" || $trimmed[0] !== "{") {
            $this->failCount++;
            $this->respond($c, 400, "{\"ok\":false,\"error\":\"body 必须是 JSON 对象，形如 {\\\"cmd\\\":\\\"x\\\"}\"}");
            return;
        }
        $req = json_decode($body, true);
        if (!is_array($req)) {
            $this->failCount++;
            $this->respond($c, 400, "{\"ok\":false,\"error\":\"body 不是合法 JSON\"}");
            return;
        }
        $cmd = isset($req["cmd"]) && is_string($req["cmd"]) ? $req["cmd"] : "";
        $args = isset($req["args"]) && is_array($req["args"]) ? $req["args"] : [];

        $r = Registry::dispatch($cmd, $args, $c->peer);
        if ($r->ok) {
            $this->okCount++;
            $this->respond($c, 200, $r->body());
            return;
        }
        $this->failCount++;
        $this->respond($c, 200, $r->body());
    }

    private function respond(Conn $c, int $status, string $body): void
    {
        $reason = "OK";
        if ($status === 400) {
            $reason = "Bad Request";
        } elseif ($status === 401) {
            $reason = "Unauthorized";
        } elseif ($status === 404) {
            $reason = "Not Found";
        } elseif ($status === 405) {
            $reason = "Method Not Allowed";
        } elseif ($status === 413) {
            $reason = "Payload Too Large";
        } elseif ($status === 429) {
            $reason = "Too Many Requests";
        }

        $resp = "HTTP/1.1 " . $status . " " . $reason . "\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "Cache-Control: no-store\r\n"
            . "\r\n"
            . $body;

        if ($c->sock === null) {
            return;
        }
        $written = @fwrite($c->sock, $resp);
        if ($written === false || $written < strlen($resp)) {
            // 非阻塞 socket 上写小响应基本不会短写，但短写必须记下来——
            // 悄悄丢掉一半响应，运维看到的是"curl 卡住"而不是"服务端有问题"
            $this->shortWrite++;
        }
        @fflush($c->sock);
    }

    // ───────────────────── 鉴权与限流 ─────────────────────

    /**
     * 定长比较，不提前返回。
     *
     * `===` 在 PHP 里对字符串是"先比长度、再逐字节"，命中第一个不同字节就返回——
     * 理论上能被按字节爆破。这里用异或累加消除提前返回。
     * 长度本身会泄漏（不同长度直接返回 false），但那只泄漏"长度"，没有意义。
     */
    private function tokenMatches(string $given): bool
    {
        $want = $this->token;
        if (strlen($given) !== strlen($want)) {
            return false;
        }
        $diff = 0;
        $n = strlen($want);
        for ($i = 0; $i < $n; $i++) {
            $diff |= ord($given[$i]) ^ ord($want[$i]);
        }
        return $diff === 0;
    }

    private function ipOf(string $peer): string
    {
        $p = strrpos($peer, ":");
        return $p === false ? $peer : substr($peer, 0, $p);
    }

    private function isBlocked(string $ip, int $nowMs): bool
    {
        $e = $this->failByIp[$ip] ?? null;
        if ($e === null) {
            return false;
        }
        if ($nowMs - $e[1] > $this->failWindowMs) {
            unset($this->failByIp[$ip]);
            return false;
        }
        return $e[0] >= $this->maxFailPerWindow;
    }

    private function noteAuthFail(string $ip, int $nowMs): void
    {
        $e = $this->failByIp[$ip] ?? null;
        if ($e === null || $nowMs - $e[1] > $this->failWindowMs) {
            $this->failByIp[$ip] = [1, $nowMs];
            return;
        }
        $c = $e[0] + 1;
        $this->failByIp[$ip] = [$c, $e[1]];
        if ($c === $this->maxFailPerWindow) {
            Logger::warn("gm ip blocked", ["ip" => $ip, "fails" => $c]);
        }
    }
}
