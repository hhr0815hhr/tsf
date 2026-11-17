<?php

namespace TSF\Gateway;

use TSF\Kernel\Logger;
use TSF\Protocol\Contract\PayloadCodec;
use TSF\Protocol\Msg;
use TSF\Transport\Server;

/**
 * 网关：把"连接生命周期"的四件事组装起来——会话、鉴权、限流、心跳。
 *
 * 业务侧只需要注册消息处理器，参数类型按 payload 选择：
 *   payload=raw  → function (int $fd, int $uid, string $body)
 *   其他 payload → function (int $fd, int $uid, string|array|object $data)
 *
 * 处理顺序（不要随意调整）：
 *   1. touch 会话（任何帧都算活跃，包括心跳）
 *   2. 限流检查（挡在最前面，避免攻击流量进入后续逻辑）
 *   3. 系统消息（PING / LOGIN）
 *   4. 业务消息（要求已登录）
 *
 * 为什么限流在鉴权之前：未登录连接同样会占用 CPU，先挡洪泛再谈业务；
 * 但登录帧本身也要限流，否则一个连接可以暴力撞 token。
 */
final class Gateway
{
    private Server $server;

    private SessionManager $sessions;

    private TokenAuth $auth;

    private RateLimiter $limiter;

    private int $sweepMs;

    private int $heartbeatSec;

    /** @var array<int, \Closure> msgId => handler(fd, uid, body) */
    private array $handlers = [];

    /**
     * 断线回调：`$cb(int $fd, int $uid)`，只在已登录的连接断开时触发。
     *
     * 没有它，业务层无法知道"玩家掉线了"——匹配队列里的票会一直挂着、
     * 对战房间会等到 60 秒超时才结束。会话清理是网关的事，
     * "掉线对玩法意味着什么"是业务的事，所以分成两个钩子。
     *
     * @var \Closure|null
     */
    private ?\Closure $onDisconnect = null;

    /**
     * 消息体编解码。为 null 时按原样把 body 交给处理器（零开销路径）。
     */
    private ?PayloadCodec $payloadCodec = null;

    /** @var array<int, int> fd => 上次发送 TOO_FAST 的时间（避免刷屏） */
    private array $warnAt = [];

    private int $warnIntervalSec = 1;

    /**
     * 缓存的"当前秒"。
     *
     * 每帧调 `time()` 要 129 ns，而心跳判断只到秒级粒度，
     * 所以由 1 秒的定时器统一刷新，热路径上直接读这个字段。
     */
    private int $nowSec = 0;
    /**
     * 背压：连续发送失败的次数。
     *
     * 对端不读数据时 Swoole 的发送缓冲区会满，send 返回 false。
     * 不处理的话消息会无声堆积（内存涨、延迟涨）或直接丢失。
     * 策略：累计 N 次失败就断开——慢客户端不该拖累整个 worker。
     *
     * @var array<int, int> fd => 连续失败次数
     */
    private array $sendFail = [];

    private int $sendFailLimit = 50;

    /**
     * 是否要求登录后才能发业务消息。
     * 关掉只用于压测/内网环境——压测端不带 token，若强制鉴权就无法测吞吐。
     */
    private bool $requireAuth;

    /** @var array<string, int> 统计 */
    private array $stat = [
        "frames" => 0,
        "dropped" => 0,
        "logins" => 0,
        "loginfail" => 0,
        "kicks" => 0,
        "idle_closed" => 0,
        "send_fail" => 0,
        "slow_closed" => 0,
    ];

    public function __construct(
        Server $server,
        SessionManager $sessions,
        TokenAuth $auth,
        RateLimiter $limiter,
        int $heartbeatSec = 30,
        int $sweepMs = 1000,
        bool $requireAuth = true,
        ?PayloadCodec $payloadCodec = null
    ) {
        $this->server = $server;
        $this->sessions = $sessions;
        $this->auth = $auth;
        $this->limiter = $limiter;
        $this->heartbeatSec = $heartbeatSec;
        $this->sweepMs = $sweepMs;
        $this->requireAuth = $requireAuth;
        $this->payloadCodec = $payloadCodec;
        $this->nowSec = time();
    }

    public function on(int $msgId, \Closure $fn): void
    {
        $this->handlers[$msgId] = $fn;
    }

    /**
     * 注册断线回调：`$cb(int $fd, int $uid)`。
     *
     * 只在**已登录**的连接断开时触发（uid > 0）：没登录的连接没有业务身份，
     * 通知业务也没有意义。
     *
     * 和 `SessionManager::close()` 的分工：网关负责清会话/限流状态，
     * 业务在这个钩子里决定"掉线对玩法意味着什么"（退出匹配、判负、存档）。
     */
    public function onDisconnect(\Closure $cb): void
    {
        // 参数类型必须是 \Closure，不能是 callable：
        // callable 在 AOT 里是联合类型（string|array|Closure|...），赋给
        // `?\Closure` 属性时会被转成 stdClass，运行时调用就报
        // `stdClass->{closure}()` —— 而且编译期不报错。
        // `on(int $msgId, \Closure $fn)` 一直正常，正是因为它用了精确类型。
        $this->onDisconnect = $cb;
    }

    public function boot(): void
    {
        $this->server->onConnect(function (int $fd): void {
            $this->sessions->open($fd, $this->nowSec);
            $this->limiter->open($fd);
            Logger::debug("session open", ["fd" => $fd, "total" => $this->sessions->total()]);
        });

        $this->server->onFrame(function (int $fd, int $msgId, string $body, int $flags): void {
            $this->handleFrame($fd, $msgId, $body);
        });

        $this->server->onClose(function (int $fd): void {
            // 必须在 close() 之前取 uid——close 会把会话记录删掉
            $uid = $this->sessions->uidOf($fd);
            $this->sessions->close($fd);
            $this->limiter->close($fd);
            unset($this->warnAt[$fd]);
            unset($this->sendFail[$fd]);

            // 会话清理归网关，业务影响归业务
            if ($uid > 0 && $this->onDisconnect !== null) {
                $cb = $this->onDisconnect;
                $cb($fd, $uid);
            }

            Logger::debug("session close", ["fd" => $fd, "total" => $this->sessions->total()]);
        });

        $this->server->onWorkerStart(function (int $workerId): void {
            // 心跳扫描：每秒一次，断开空闲超过 heartbeatSec 的连接。
            // 同一个 tick 里顺带刷新"当前秒"并批量补充限流令牌——
            // 每帧取 time()/算补充都太贵（实测 129/233 ns），合并到秒级一次更划算。
            \Swoole\Timer::tick($this->sweepMs, function (int $timerId): void {
                $this->nowSec = time();
                $this->limiter->refillAll();
                $this->sweep();
            });
            Logger::info("gateway ready", [
                "worker" => $workerId,
                "heartbeat_sec" => $this->heartbeatSec,
            ]);
        });
    }

    /**
     * 发送业务消息并做背压检查。
     *
     * 载荷类型是 `string|array|object`（与 PayloadCodec 一致），不是 `mixed`：
     * 消息体只可能是这三种形态，写清楚比放任更安全，也让实现方知道该期待什么。
     *
     * payload=raw 时只接受 string——传数组会立刻报错，而不是悄悄发个空包。
     */
    public function send(int $fd, int $msgId, string|array|object $data, int $flags = 0): bool
    {
        if ($this->payloadCodec === null) {
            if (!is_string($data)) {
                throw new \RuntimeException(
                    "payload=raw 时业务消息必须是 string，收到 " . gettype($data) .
                    "（msgId=" . $msgId . "）"
                );
            }
            return $this->sendSystem($fd, $msgId, $data, $flags);
        }
        return $this->sendSystem($fd, $msgId, $this->payloadCodec->pack($msgId, $data), $flags);
    }

    /**
     * 系统消息：原样发送，不经过 payload 编解码。
     *
     * 登录/心跳/踢下线这些握手消息必须与 payload 选择无关——
     * 否则换个 payload 就得同步改客户端握手，毫无必要。
     */
    private function sendSystem(int $fd, int $msgId, string $body, int $flags = 0): bool
    {
        $ok = $this->server->sendFrame($fd, $msgId, $body, $flags);
        if ($ok) {
            if (isset($this->sendFail[$fd])) {
                unset($this->sendFail[$fd]);
            }
            return true;
        }

        $n = ($this->sendFail[$fd] ?? 0) + 1;
        $this->sendFail[$fd] = $n;
        $this->stat["send_fail"]++;
        if ($n >= $this->sendFailLimit) {
            $this->stat["slow_closed"]++;
            Logger::warn("slow consumer, closing", ["fd" => $fd, "fails" => $n]);
            unset($this->sendFail[$fd]);
            $this->server->close($fd);
        }
        return false;
    }

    public function setSendFailLimit(int $n): void
    {
        $this->sendFailLimit = $n > 0 ? $n : 50;
    }
    private function handleFrame(int $fd, int $msgId, string $body): void
    {
        $now = $this->nowSec;
        $this->sessions->touchFrame($fd, $now);
        $this->stat["frames"]++;

        // 1) 限流（每帧只做"读-判断-写"，补充由每秒的 tick 批量完成）
        if (!$this->limiter->take($fd)) {
            $this->stat["dropped"]++;
            $this->sessions->countDrop($fd);
            $last = $this->warnAt[$fd] ?? 0;
            if ($now - $last >= $this->warnIntervalSec) {
                $this->warnAt[$fd] = $now;
                $this->sendSystem($fd, Msg::TOO_FAST, "too many messages");
            }
            return;
        }

        // 2) 系统消息
        if ($msgId === Msg::PING) {
            $this->sendSystem($fd, Msg::PONG, $body);
            return;
        }
        if ($msgId === Msg::LOGIN) {
            $this->handleLogin($fd, $body);
            return;
        }

        // 3) 业务消息：按配置决定是否要求已登录
        $session = $this->sessions->get($fd);
        if ($session === null) {
            $this->sendSystem($fd, Msg::ERR, "no session");
            return;
        }
        if ($this->requireAuth && !$session->authed) {
            $this->sendSystem($fd, Msg::ERR, "login required");
            return;
        }

        $h = $this->handlers[$msgId] ?? null;
        if ($h === null) {
            $this->sendSystem($fd, Msg::ERR, "unknown msg: " . $msgId);
            return;
        }
        if ($this->payloadCodec !== null) {
            // 消息体解码放在派发前：这里才知道 msgId 对应的业务类型，
            // 登录前的系统消息也就不必解析业务体。
            //
            // 注意：不能写成 $data = $body; $data = unpack(...);
            // AOT 会把 $data 推断成 string，后赋数组会被静默转成 "Array"（踩过）。
            $h($fd, $session->uid, $this->payloadCodec->unpack($msgId, $body));
            return;
        }
        $h($fd, $session->uid, $body);
    }

    private function handleLogin(int $fd, string $token): void
    {
        $uid = $this->auth->resolve($token);
        if ($uid === 0) {
            $this->stat["loginfail"]++;
            $this->sendSystem($fd, Msg::LOGIN_FAIL, "invalid token");
            Logger::warn("login failed", ["fd" => $fd]);
            return;
        }
        $oldFd = $this->sessions->bind($fd, $uid, $token, time());
        if ($oldFd !== 0) {
            // 顶号：通知旧连接并断开
            $this->stat["kicks"]++;
            $this->sendSystem($oldFd, Msg::KICKED, "kicked by new login");
            $this->server->close($oldFd);
            Logger::info("kick old session", ["uid" => $uid, "oldFd" => $oldFd, "newFd" => $fd]);
        }
        $this->stat["logins"]++;
        $this->sendSystem($fd, Msg::LOGIN_OK, (string) $uid);
        Logger::debug("login ok", ["fd" => $fd, "uid" => $uid]);
    }

    private function sweep(): void
    {
        $dead = $this->sessions->sweep($this->nowSec);
        $n = count($dead);
        if ($n === 0) {
            return;
        }
        foreach ($dead as $fd) {
            $this->stat["idle_closed"]++;
            Logger::debug("idle close", ["fd" => $fd, "timeout" => $this->heartbeatSec]);
            $this->server->close($fd);
            // close 回调会清理会话；这里兜底，防止回调未触发时残留
            $this->sessions->close($fd);
            $this->limiter->close($fd);
        }
        Logger::info("idle sweep", ["closed" => $n, "total" => $this->sessions->total()]);
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["sessions"] = $this->sessions->total();
        $out["authed"] = $this->sessions->authedTotal();
        return $out;
    }
}
