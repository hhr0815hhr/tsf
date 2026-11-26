<?php

namespace TSF\Gateway;

use TSF\Kernel\Config;
use TSF\Kernel\Logger;
use TSF\Metrics\Metrics;
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
        /** 业务 handler 或载荷解码抛异常的帧数（见 dispatch） */
        "handler_errors" => 0,
        /** 连上之后超过登录时限仍未登录而被断开 */
        "login_timeout_closed" => 0,
        /** 同一条连接上登录失败次数达到上限而被断开 */
        "login_abuse_closed" => 0,
        /** 来源 IP 处于封禁期，登录请求被直接拒绝 */
        "login_banned" => 0,
        /** 未登录流量触发了按来源的限流而被丢弃的帧 */
        "peer_dropped" => 0,
    ];

    /**
     * 登录时限（秒）：连上之后这么久还没登录就断开。只在 `requireAuth` 时生效。
     *
     * 默认 30 秒。它存在的理由是：未登录的连接可以靠一直发心跳躲开空闲清扫，
     * 一个连接名额（以及一个限流桶、一个会话）就被无限期占着。
     * 默认值比常见的心跳周期长得多，正常的"连上就登录"客户端永远碰不到它；
     * 确实有"先连着、过一会儿再登录"的客户端，把 `server.login_timeout_sec` 调大或设 0 关掉。
     */
    private int $loginTimeoutSec = 30;

    /** 同一条连接上连续登录失败这么多次就断开 */
    private int $loginFailPerConn = 5;

    /** 同一个来源 IP 在窗口内登录失败这么多次就封禁 */
    private int $loginFailPerIp = 10;

    private int $loginFailWindowSec = 60;

    /** 来源 IP 被封禁多久（秒） */
    private int $loginBanSec = 600;

    /**
     * 未登录流量**按来源 IP** 的限流桶；null = 关闭。
     *
     * 为什么要有它：`$limiter` 是按连接的，攻击者断开重连就拿到一个满的新桶——
     * 限流等于没有。同一个来源的未登录连接共用一个桶，重连就没有用了。
     * 登录成功之后不再过这个桶：同一个网吧里的玩家共用出口 IP，登录后按 IP 限会误伤。
     */
    private ?RateLimiter $peerLimiter = null;

    /** @var array<string, int> 来源 IP => 内部编号 */
    private array $peerIds = [];

    /** @var array<int, string> 内部编号 => 来源 IP */
    private array $peerAddr = [];

    /** @var array<int, int> fd => 来源编号 */
    private array $fdPeer = [];

    /** @var array<int, int> 来源编号 => 当前来自它的连接数 */
    private array $peerRefs = [];

    /** @var array<int, int> 来源编号 => 自它最后一个连接断开以来的秒数起点 */
    private array $peerIdleSince = [];

    /** @var array<int, int> fd => 连续登录失败次数 */
    private array $loginFails = [];

    /** @var array<int, int> 来源编号 => 窗口内登录失败次数 */
    private array $peerFails = [];

    /** @var array<int, int> 来源编号 => 窗口起点 */
    private array $peerFailSince = [];

    /** @var array<int, int> 来源编号 => 封禁到（秒） */
    private array $peerBannedUntil = [];

    /** 一个来源最后一个连接断开之后，它的记录（限流桶 / 失败计数）再保留多久（秒） */
    private int $peerLingerSec = 300;

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
        // 运行时指标：导出时现取 stats()。多个网关实例相加（总会话数本来就该求和）
        $self = $this;
        Metrics::provide("tsf_gateway", function () use ($self): array {
            return $self->stats();
        }, [
            "frames",
            "dropped",
            "logins",
            "loginfail",
            "kicks",
            "idle_closed",
            "send_fail",
            "slow_closed",
            "handler_errors",
            "login_timeout_closed",
            "login_abuse_closed",
            "login_banned",
            "peer_dropped",
        ]);
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
            $this->trackPeer($fd);
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
            unset($this->loginFails[$fd]);
            $this->untrackPeer($fd);

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
                // 来源桶也要补。漏掉这一行的后果（第一版实测）：来源桶的 20 个令牌耗尽之后
                // 永远不回补，这个 IP 之后所有未登录流量全被丢——客户端看到的就是"连上了但没有任何响应"
                if ($this->peerLimiter !== null) {
                    $this->peerLimiter->refillAll();
                }
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

    /** 登录时限（秒）。0 = 不限时（只靠空闲清扫）。只在 requireAuth 时生效 */
    public function setLoginTimeout(int $sec): void
    {
        $this->loginTimeoutSec = $sec > 0 ? $sec : 0;
    }

    public function loginTimeout(): int
    {
        return $this->loginTimeoutSec;
    }

    /**
     * 登录失败的处置。
     *
     * @param int $perConn  同一条连接连续失败这么多次就断开（0 = 不断）
     * @param int $perIp    同一来源在 `$windowSec` 内失败这么多次就封禁（0 = 不封）
     * @param int $banSec   封禁多久
     */
    public function setLoginFailLimit(int $perConn, int $perIp, int $windowSec, int $banSec): void
    {
        $this->loginFailPerConn = $perConn > 0 ? $perConn : 0;
        $this->loginFailPerIp = $perIp > 0 ? $perIp : 0;
        $this->loginFailWindowSec = $windowSec > 0 ? $windowSec : 60;
        $this->loginBanSec = $banSec > 0 ? $banSec : 1;
    }

    /**
     * 开启"未登录流量按来源 IP 限流"。传 null 关闭。
     *
     * 只在 `requireAuth` 时、只对未登录的连接生效。
     */
    public function setPeerLimiter(?RateLimiter $l): void
    {
        $this->peerLimiter = $l;
    }

    /** 来源记录在最后一个连接断开之后保留多久（秒） */
    public function setPeerLinger(int $sec): void
    {
        $this->peerLingerSec = $sec > 0 ? $sec : 1;
    }

    /** 一个来源的内部编号；没见过的来源现编一个 */
    private function peerIdOf(string $ip): int
    {
        if ($ip === "") {
            return 0;
        }
        if (!isset($this->peerIds[$ip])) {
            $id = count($this->peerIds) + 1;
            $this->peerIds[$ip] = $id;
            $this->peerAddr[$id] = $ip;
            return $id;
        }
        return $this->peerIds[$ip];
    }

    /**
     * 按配置装配一个带默认值的网关：会话 / 鉴权 / 限流 / 登录时限 / 登录失败处置 / 来源限流。
     *
     * ## 为什么需要它
     *
     * 每个应用的 `main.php` 都手写 `TcpServer + SessionManager + TokenAuth + RateLimiter + Gateway`
     * 五件套。漏掉任何一个都不会报错，只是少一层防护——而且后来加的准入策略（登录时限、
     * 封禁、按来源限流）在这些 `main.php` 里**全部缺席**，因为它们没有地方可配。
     * 这个入口让"安全默认值"只有一份，加新策略时所有应用一起生效。
     *
     * 全部读 `server.*` 配置，没配的用默认值：
     *
     * | 键 | 默认 | 说明 |
     * |:--|:--|:--|
     * | `heartbeat_sec` | 30 | 空闲多久断开 |
     * | `rate_per_sec` / `rate_burst` | 50 / 100 | 每连接限流 |
     * | `peer_rate_per_sec` / `peer_rate_burst` | 同上 / 同上 | 未登录流量按来源 IP 限流；0 关闭 |
     * | `auth.require` | true | 是否要求登录 |
     * | `auth.secret` / `auth.tokens` | "" / [] | 签名密钥 / 静态表 |
     * | `auth.allow_legacy` | true | 是否接受永不过期的 2 段 token（生产应当 false） |
     * | `send_fail_limit` | 50 | 连续发送失败多少次断开 |
     * | `login_timeout_sec` | 30 | 连上之后多久必须登录；0 关闭 |
     * | `login_fail_per_conn` | 5 | 同一连接连续登录失败上限 |
     * | `login_fail_per_ip` / `login_fail_window_sec` | 10 / 60 | 同一来源窗口内失败上限 |
     * | `login_ban_sec` | 600 | 来源被封禁多久 |
     */
    public static function assemble(Server $server, ?PayloadCodec $payloadCodec = null): Gateway
    {
        $heartbeat = Config::getInt("server.heartbeat_sec", 30);
        $auth = new TokenAuth(
            Config::getMap("server.auth.tokens"),
            Config::getString("server.auth.secret", ""),
            false,
            Config::getBool("server.auth.allow_legacy", true)
        );
        $rate = Config::getInt("server.rate_per_sec", 50);
        $burst = Config::getInt("server.rate_burst", 100);
        $gw = new Gateway(
            $server,
            new SessionManager($heartbeat),
            $auth,
            new RateLimiter($rate, $burst),
            $heartbeat,
            1000,
            Config::getBool("server.auth.require", true),
            $payloadCodec
        );
        $gw->setSendFailLimit(Config::getInt("server.send_fail_limit", 50));
        $gw->setLoginTimeout(Config::getInt("server.login_timeout_sec", 30));
        $gw->setLoginFailLimit(
            Config::getInt("server.login_fail_per_conn", 5),
            Config::getInt("server.login_fail_per_ip", 10),
            Config::getInt("server.login_fail_window_sec", 60),
            Config::getInt("server.login_ban_sec", 600)
        );
        // 默认和每连接限流同档：未登录阶段正常客户端只发一帧登录，同来源几十个连接共享绰绰有余
        $peerRate = Config::has("server.peer_rate_per_sec") ? Config::getInt("server.peer_rate_per_sec") : $rate;
        if ($peerRate > 0) {
            $peerBurst = Config::has("server.peer_rate_burst") ? Config::getInt("server.peer_rate_burst") : $burst;
            $gw->setPeerLimiter(new RateLimiter($peerRate, $peerBurst));
        }
        return $gw;
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

        // 1b) 未登录流量按来源限流：同一个来源 IP 的未登录连接共用一个桶，
        //     断开重连拿不到新的桶。登录之后不再过它（共用出口 IP 的玩家不该互相拖累）
        if ($this->requireAuth && $this->peerLimiter !== null) {
            $pre = $this->sessions->get($fd);
            if ($pre !== null && !$pre->authed) {
                $pid = $this->fdPeer[$fd] ?? 0;
                if ($pid > 0 && !$this->peerLimiter->take($pid)) {
                    $this->stat["peer_dropped"]++;
                    $this->sessions->countDrop($fd);
                    return;
                }
            }
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
        $this->dispatch($fd, $session, $msgId, $body, $h);
    }

    /**
     * 解码 + 调用业务 handler。**异常在这里止步，不往外抛。**
     *
     * ## 为什么必须有这一层
     *
     * Gateway 的 handler 直接跑在 Swoole 的收包回调里，外面没有别人兜：
     * 异常一路穿到 Swoole，它打一行 `PHP Fatal error` 到 stderr 然后继续跑。
     * 实测（2026-11-25，tests/gw-guard）后果有三条：
     *
     * 1. 同一次收包里排在后面的帧**全部静默丢失**——异常打断了拆包循环，
     *    而剩余字节还没写回连接缓冲，直接被丢掉。客户端表现为"后几条请求等到超时"。
     * 2. 那行 Fatal 不走 `Logger`：没有 fd / uid / msgId，不受 `log.level` 控制，
     *    也进不了日志采集。一个畸形包就能无限刷。
     * 3. 没有任何计数，监控上完全看不到。
     *
     * 同仓库里 `Rpc\Router::handle`、`Gm\Registry::dispatch`、`Http\App` 都做了同样的
     * 兜底——只有面向客户端的这个入口漏了，而它恰恰是唯一人人都能打到的地方。
     *
     * ## 出错后怎么处置：回一帧 ERR，连接不断
     *
     * - 回 ERR：让调用方立刻知道这条请求失败了，而不是等到超时。
     * - 不断开：handler 抛异常只说明**这一条**没处理成，连接本身和帧边界都是好的
     *   （异常被接住之后，拆包循环照常继续）。真正的状态不一致发生在业务对象里，
     *   断开连接并不能恢复它，只会让玩家平白掉一次线。
     *
     * ## 成本
     *
     * 每帧进一次 try（AOT 实测约 300 ns）。按网关路径每帧约 29 µs 算是 1%，
     * 换的是上面三条。放在这里而不是 `Transport\Server::feed()` 的循环里，
     * 是因为 feed 还要服务 gateway=off 的压测路径，那条路不接业务代码。
     */
    private function dispatch(int $fd, Session $session, int $msgId, string $body, \Closure $h): void
    {
        $uid = $session->uid;
        try {
            if ($this->payloadCodec !== null) {
                // 消息体解码放在派发前：这里才知道 msgId 对应的业务类型，
                // 登录前的系统消息也就不必解析业务体。
                //
                // 注意：不能写成 $data = $body; $data = unpack(...);
                // AOT 会把 $data 推断成 string，后赋数组会被静默转成 "Array"（踩过）。
                $h($fd, $uid, $this->payloadCodec->unpack($msgId, $body));
            } else {
                $h($fd, $uid, $body);
            }
        } catch (\Throwable $e) {
            $this->stat["handler_errors"]++;
            Logger::error("gateway handler error", [
                "fd" => $fd,
                "uid" => $uid,
                "msgId" => $msgId,
                "len" => strlen($body),
                "err" => $e->getMessage(),
                "class" => $e::class,
            ]);
            $this->sendSystem($fd, Msg::ERR, "handler error");
        }
    }

    /**
     * 连接建立时登记它的来源：拿不到来源 IP（假传输 / 某些代理）就只按连接算。
     */
    private function trackPeer(int $fd): void
    {
        if (!$this->requireAuth) {
            return;
        }
        $pid = $this->peerIdOf($this->server->peerOf($fd));
        if ($pid === 0) {
            return;
        }
        $this->fdPeer[$fd] = $pid;
        $this->peerRefs[$pid] = ($this->peerRefs[$pid] ?? 0) + 1;
        // 限流桶**不在这里建**：由 RateLimiter::take() 第一次用到时建。
        // 这里建的话，一个来源关掉最后一条连接时桶要不要删就成了两难——
        // 删了等于重连送新桶，不删又没人回收
        unset($this->peerIdleSince[$pid]);
    }

    /** 连接断开时注销来源；最后一条连接断开后开始计时，linger 期满由 sweep() 回收 */
    private function untrackPeer(int $fd): void
    {
        $pid = $this->fdPeer[$fd] ?? 0;
        if ($pid === 0) {
            return;
        }
        unset($this->fdPeer[$fd]);
        $n = ($this->peerRefs[$pid] ?? 1) - 1;
        if ($n <= 0) {
            unset($this->peerRefs[$pid]);
            $this->peerIdleSince[$pid] = $this->nowSec;
            return;
        }
        $this->peerRefs[$pid] = $n;
    }

    /** 记一次登录失败；返回 true 表示这条连接应当被断开 */
    private function noteLoginFail(int $fd, int $pid): bool
    {
        $n = ($this->loginFails[$fd] ?? 0) + 1;
        $this->loginFails[$fd] = $n;
        if ($this->loginFailPerConn > 0 && $n >= $this->loginFailPerConn) {
            $this->stat["login_abuse_closed"]++;
            Logger::warn("too many login failures on one connection, closing", [
                "fd" => $fd,
                "peer" => $this->peerAddr[$pid] ?? "",
                "fails" => $n,
            ]);
            return true;
        }
        if ($pid > 0 && $this->loginFailPerIp > 0) {
            $since = $this->peerFailSince[$pid] ?? 0;
            if ($since === 0 || $this->nowSec - $since > $this->loginFailWindowSec) {
                $this->peerFailSince[$pid] = $this->nowSec;
                $this->peerFails[$pid] = 0;
            }
            $this->peerFails[$pid] = ($this->peerFails[$pid] ?? 0) + 1;
            if ($this->peerFails[$pid] >= $this->loginFailPerIp) {
                $until = $this->nowSec + $this->loginBanSec;
                $this->peerBannedUntil[$pid] = $until;
                // 封禁期间不再累计：否则解封的瞬间又因为旧账被立刻封回去
                unset($this->peerFails[$pid], $this->peerFailSince[$pid]);
                Logger::warn("login failures from one peer, banning", [
                    "peer" => $this->peerAddr[$pid] ?? "",
                    "sec" => $this->loginBanSec,
                ]);
            }
        }
        return false;
    }

    private function handleLogin(int $fd, string $token): void
    {
        $pid = $this->fdPeer[$fd] ?? 0;
        $bannedUntil = $this->peerBannedUntil[$pid] ?? 0;
        if ($pid > 0 && $bannedUntil > $this->nowSec) {
            // 封禁期内：连 token 对不对都不看。响应和普通的登录失败一模一样，
            // 不让对方知道"我已经被封了"（那等于告诉他该换 IP 了）
            $this->stat["login_banned"]++;
            $this->stat["loginfail"]++;
            $this->sendSystem($fd, Msg::LOGIN_FAIL, "invalid token");
            return;
        }
        if ($bannedUntil > 0) {
            unset($this->peerBannedUntil[$pid]);
        }

        $uid = $this->auth->resolve($token);
        if ($uid === 0) {
            $this->stat["loginfail"]++;
            $this->sendSystem($fd, Msg::LOGIN_FAIL, "invalid token");
            Logger::warn("login failed", ["fd" => $fd, "peer" => $this->peerAddr[$pid] ?? ""]);
            if ($this->noteLoginFail($fd, $pid)) {
                $this->server->close($fd);
            }
            return;
        }
        unset($this->loginFails[$fd]);
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
        $this->sweepUnauthed();
        $this->gcPeers();
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

    /** 连上之后超过登录时限还没登录的连接：断开 */
    private function sweepUnauthed(): void
    {
        if (!$this->requireAuth || $this->loginTimeoutSec <= 0) {
            return;
        }
        $dead = $this->sessions->sweepUnauthed($this->nowSec, $this->loginTimeoutSec);
        $n = count($dead);
        if ($n === 0) {
            return;
        }
        foreach ($dead as $fd) {
            $this->stat["login_timeout_closed"]++;
            // 来源要先取：close() 会同步触发 onClose，那里就把这条连接的来源记录清掉了
            $peerIp = $this->peerAddr[$this->fdPeer[$fd] ?? 0] ?? "";
            Logger::info("login timeout, closing", [
                "fd" => $fd,
                "peer" => $peerIp,
                "timeout" => $this->loginTimeoutSec,
            ]);
            $this->server->close($fd);
            // close 回调会清理会话；这里兜底，防止回调未触发时残留
            $this->sessions->close($fd);
            $this->limiter->close($fd);
        }
    }

    /**
     * 回收已经没有任何连接、且 linger 期满的来源记录（限流桶 / 失败计数 / 封禁）。
     *
     * linger 是为了"断开重连不能拿到新的限流桶"：最后一条连接断开之后桶还在，
     * 立刻重连的连接接着用旧桶里剩下的令牌。linger 到期才删，表才不会无限长。
     */
    private function gcPeers(): void
    {
        if ($this->peerIdleSince === []) {
            return;
        }
        foreach ($this->peerIdleSince as $pid => $since) {
            if ($this->nowSec - $since < $this->peerLingerSec) {
                continue;
            }
            if ($this->peerLimiter !== null) {
                $this->peerLimiter->close($pid);
            }
            unset(
                $this->peerIdleSince[$pid],
                $this->peerFails[$pid],
                $this->peerFailSince[$pid],
                $this->peerBannedUntil[$pid]
            );
            $ip = $this->peerAddr[$pid] ?? "";
            if ($ip !== "") {
                unset($this->peerIds[$ip], $this->peerAddr[$pid]);
            }
        }
    }

    /** 某个来源当前是否处于登录封禁期（自省 / 测试用）。没见过的来源返回 false */
    public function isPeerBanned(string $ip): bool
    {
        $pid = $this->peerIds[$ip] ?? 0;
        if ($pid === 0) {
            return false;
        }
        return ($this->peerBannedUntil[$pid] ?? 0) > $this->nowSec;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["sessions"] = $this->sessions->total();
        $out["authed"] = $this->sessions->authedTotal();
        $out["peers"] = count($this->peerIds);
        $out["banned_peers"] = count($this->peerBannedUntil);
        return $out;
    }
}
