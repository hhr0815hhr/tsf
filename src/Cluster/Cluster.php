<?php

namespace TSF\Cluster;

use TSF\Kernel\Logger;
use TSF\Metrics\Metrics;
use TSF\Protocol\Contract\PayloadCodec;
use TSF\Protocol\Payload\RawPayload;

/**
 * 跨节点通道：一个监听 socket + 若干条对端链路。
 *
 * 四条设计决定：
 *
 * 1. **地址直接上线**。包里有完整 dst/src（含节点号），所以中间节点也能转发，
 *    不需要另一个"路由表"概念。上层发消息只认地址，不关心本地还是远程。
 *
 * 2. **谁连谁不重要**。只有一端主动 connect 也能双向通信——对方回包走的就是这条
 *    已建立的连接。
 *
 * 3. **连上不等于可用**。每条连接先握手（`魔数 + hello`），hello 里带节点号、
 *    codec 名和 mac，校验通过才认这条链路。旧版靠"第一个包里的 src 节点位"
 *    来认身份，任何本机进程连上就能自称 node 7——Unix socket 时代勉强能接受，
 *    到了 TCP 就是任意人可冒充。
 *
 * 4. **攒批写**。发送只入缓冲，`flush()` 每个 tick 末尾统一写一次。
 *    一轮发 100 条消息只付一次 syscall。延迟代价 < 一个 tick。
 *
 * ## 传输
 *
 * `unix`（默认，裸路径或 `unix://`）与 `tcp://host:port` 二选一，API 完全一致。
 * 跨机必须用 tcp；同机多进程用 unix 更省（不占端口、不受防火墙影响）。
 *
 * ## 安全边界（说清楚，别高估）
 *
 * 握手做的是**身份与密钥校验**，不是加密：mac 用 sha256-HMAC，密钥不走线上，
 * 但载荷本身是明文。它能挡住"随便连上来冒充节点"和"两端配置不一致"，
 * 挡不住能监听链路的人。要求更强就得在传输层加 TLS，那是另一件事。
 * `secret` 为空时只校验结构不校验 mac —— 只应该在单机调试时这么用。
 *
 * 载荷用 PayloadCodec 打包/解包，默认 RawPayload（即只接受字符串）。
 * 要跨进程传结构化数据，两端必须配同一个非 raw 的 codec。
 *
 * ## 断线重连
 *
 * `connect()` 只连一次；`watchPeer()` 会**记住对端地址**，链路断了之后按指数退避重连
 * （0.5 秒起、翻倍、封顶 15 秒，带抖动）。修之前这里什么都没有：`dropLink()` 之后
 * 永久失联，对端重启、网络抖动一次，跨节点消息就全部进 `drop`——节点还活着，
 * 只是在集群里"不存在"了。谁该被 watch 由业务决定（静态配置，或者把注册表的成员变更
 * 喂给 `watchPeer()`）；Cluster 自己不去查注册表，核心不依赖可选包。
 */
final class Cluster
{
    private int $nodeId;

    private string $scheme = "unix";

    private string $target = "";

    /** 握手密钥；空串表示不校验 mac（仅限可信网络/本机） */
    private string $secret;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, Link> 节点号 => 已建立的链路 */
    private array $links = [];

    /** @var array<int, Link> 尚未完成握手的入站连接 */
    private array $inbound = [];

    /** @var array<int, string> 节点号 => 拨号地址；在 watchPeer() 里登记过的对端，断了自动重连 */
    private array $peers = [];

    /** @var array<int, int> 节点号 => 下一次允许拨号的时刻（单调毫秒） */
    private array $retryAt = [];

    /** @var array<int, int> 节点号 => 当前退避（毫秒）；链路稳定一段时间后清掉 */
    private array $backoffMs = [];

    /** @var array<int, int> 节点号 => 最近一次握手完成的时刻；用来判断链路是否"稳定过" */
    private array $upAt = [];

    private int $backoffMinMs = 500;

    private int $backoffMaxMs = 15000;

    /** 一次非阻塞连接多久没动静算失败（见 Link::stepConnect） */
    private int $connectTimeoutMs = 3000;

    /** 链路保持多久之后算"稳定"：稳定过的链路再断，退避从最小值重新开始 */
    private int $stableMs = 30000;

    private PayloadCodec $codec;

    /** @var array<string, int> */
    private array $stat = [
        "out" => 0,
        "in" => 0,
        "drop" => 0,
        "dup" => 0,
        "links" => 0,
        "hs_fail" => 0,
        /** 收到解不开的数据而废弃链路的次数（见 badLink） */
        "bad_pkt" => 0,
        /** 自动重连成功发起拨号的次数 */
        "reconnects" => 0,
        /** 拨号当场失败的次数（连接超时/被拒不算在这里，它们走 dropLink） */
        "connect_fails" => 0,
    ];

    /**
     * 单条链路待发缓冲的上限。
     *
     * 对端不读（假死、或网络单通）时，写缓冲会一直涨。信箱有上限是为了不被
     * "合法代码"涨到 OOM，这里同理：超过上限就丢新消息并计数，让调用方拿到 false。
     * 16 MB 约是每条链路几十万条业务消息，到了这个量级说明对端早已不健康。
     */
    private const MAX_OUT = 16777216;

    public function __construct(int $nodeId, string $path, ?PayloadCodec $codec = null, string $secret = "")
    {
        if ($nodeId <= 0 || $nodeId > 255) {
            throw new \RuntimeException("cluster: 节点号必须在 1..255（0 表示本地）");
        }
        $this->nodeId = $nodeId;
        $this->secret = $secret;
        $this->codec = $codec ?? new RawPayload();
        $this->applyAddr($path);
        // 运行时指标：导出时现取 stats()。一个进程里有几个节点（测试里常见）就相加
        $self = $this;
        Metrics::provide("tsf_cluster", function () use ($self): array {
            return $self->stats();
        }, [
            "out",
            "in",
            "drop",
            "hs_fail",
            "bad_pkt",
            "dup",
            "reconnects",
            "connect_fails",
        ]);
    }

    /**
     * 解析监听描述。
     *
     * 裸字符串按 unix 路径处理 —— 这是老写法（`/tmp/x.sock`），测试和 bench 都在用，
     * 不能因为加了 TCP 就让它们全部改一遍。
     */
    private function applyAddr(string $spec): void
    {
        $i = strpos($spec, "://");
        if ($i === false) {
            $this->scheme = "unix";
            $this->target = $spec;
            return;
        }
        $s = strtolower(substr($spec, 0, $i));
        $rest = substr($spec, $i + 3);
        if ($s === "unix") {
            $this->scheme = "unix";
            $this->target = $rest;
            return;
        }
        if ($s !== "tcp") {
            throw new \RuntimeException("cluster: 不支持的监听协议 [" . $s . "]，只认 unix / tcp");
        }
        if (strpos($rest, ":") === false) {
            throw new \RuntimeException("cluster: tcp 地址必须带端口 [" . $spec . "]");
        }
        $this->scheme = "tcp";
        $this->target = $rest;
    }

    /** 当前监听描述（排障用） */
    public function endpoint(): string
    {
        return $this->scheme . "://" . $this->target;
    }

    public function nodeId(): int
    {
        return $this->nodeId;
    }

    public function listen(): bool
    {
        $uri = "";
        if ($this->scheme === "unix") {
            @unlink($this->target);
            $uri = "unix://" . $this->target;
        } else {
            $uri = "tcp://" . $this->target;
        }
        $srv = @stream_socket_server($uri, $errno, $errstr);
        if ($srv === false) {
            throw new \RuntimeException("cluster: 监听失败 " . $this->endpoint() . " (" . $errstr . ")");
        }
        stream_set_blocking($srv, false);
        $this->server = $srv;
        return true;
    }

    /**
     * 主动连接某个节点。地址写法与监听一致：裸路径 / `unix://` / `tcp://host:port`。
     *
     * 连上之后先发 hello，**在握手完成前不认这条链路**（`Link::drain()` 会返回空）。
     * 所以"连上了"不等于"能用了"，`hasPeer()` 也要等握手之后才为真。
     */
    public function connect(int $node, string $path): bool
    {
        // unix：同步连。同一个内核里建连是即时的（对方没监听就立刻 ENOENT），
        // 没有阻塞的风险，而且保持老行为——大量测试依赖"connect() 返回 true 之后握手马上开始"。
        // tcp：非阻塞。原来的同步 connect 最长挡住整个 worker 2 秒——而这里是 1ms 一轮的
        // 调度循环，对端一重启所有服务跟着停 2 秒。
        $async = self::isTcp($path);
        $link = $this->dial($path, $async);
        if (!$link->alive()) {
            return false;
        }
        if ($async) {
            $link->markConnecting($this->nowMs());
        }
        $link->node = $node;
        $link->beginHandshake($this->nodeId, $this->codec->name(), $this->secret);
        if (isset($this->links[$node])) {
            // 同一个节点重复 connect：关掉旧的那条，否则它永远没人读（且下次写满缓冲也不摘）
            $this->links[$node]->close();
        }
        $this->links[$node] = $link;
        $this->stat["links"] = count($this->links);
        return true;
    }

    /**
     * 登记一个要**保持连通**的对端：没连上就连，断了自动重连。
     *
     * 和 `connect()` 的区别：`connect()` 是一次性的，`watchPeer()` 是持续的。
     * 返回值只说明"登记了并立刻发起了一次拨号"，**不代表连上了**——可没用要看 `hasPeer()`。
     */
    public function watchPeer(int $node, string $path): bool
    {
        $this->peers[$node] = $path;
        if (isset($this->links[$node])) {
            return true;
        }
        if (!isset($this->retryAt[$node])) {
            $this->retryAt[$node] = 0;
        }
        // 立刻试一次，不等 poll()
        $this->dialPeer($node, $this->peers[$node]);
        return true;
    }

    /** 不再保持与某个节点的连通（已建立的链路会保留，断了不再重连） */
    public function forgetPeer(int $node): void
    {
        unset($this->peers[$node], $this->retryAt[$node], $this->backoffMs[$node], $this->upAt[$node]);
    }

    /** @return array<int, string> 当前登记的要保持连通的对端：节点号 => 地址 */
    public function peers(): array
    {
        return $this->peers;
    }

    /**
     * 重连退避：起始 / 封顶（毫秒）。`stableMs`：链路保持这么久之后算稳定，
     * 稳定过的链路再断，退避从起始值重新算；没稳定过就断的，继续翻倍。
     */
    public function setReconnect(int $minMs, int $maxMs, int $stableMs = 30000): void
    {
        if ($minMs < 10) {
            $minMs = 10;
        }
        if ($maxMs < $minMs) {
            $maxMs = $minMs;
        }
        $this->backoffMinMs = $minMs;
        $this->backoffMaxMs = $maxMs;
        $this->stableMs = $stableMs > 0 ? $stableMs : 1;
    }

    /** 一次非阻塞连接的超时（毫秒）。不可达地址只能靠它判定失败 */
    public function setConnectTimeout(int $ms): void
    {
        $this->connectTimeoutMs = $ms >= 100 ? $ms : 100;
    }

    private static function isTcp(string $path): bool
    {
        $i = strpos($path, "://");
        return $i !== false && strtolower(substr($path, 0, $i)) === "tcp";
    }

    /** 把"裸路径 / unix:// / tcp://host:port"规范化成 URI；协议不认识直接抛 */
    private static function uriOf(string $path): string
    {
        $i = strpos($path, "://");
        if ($i === false) {
            return "unix://" . $path;
        }
        $scheme = strtolower(substr($path, 0, $i));
        if ($scheme === "unix") {
            return "unix://" . substr($path, $i + 3);
        }
        if ($scheme !== "tcp") {
            throw new \RuntimeException("cluster: 不支持的地址 [" . $path . "]，只认 unix / tcp");
        }
        $rest = substr($path, $i + 3);
        if (strpos($rest, ":") === false) {
            throw new \RuntimeException("cluster: tcp 地址必须带端口 [" . $path . "]");
        }
        return "tcp://" . $rest;
    }

    /**
     * 发起一次拨号。失败时返回一个没有 socket 的 Link（`alive()` 为 false），不抛异常——
     * 重连是在 poll() 里跑的，一次拨号失败不该打断整轮调度。
     */
    private function dial(string $path, bool $async): Link
    {
        $uri = self::uriOf($path);
        $errno = 0;
        $errstr = "";
        // 两个分支各用各的变量，不能写 `$sock = false; ... $sock = stream_socket_client(...)`：
        // AOT 下变量的类型由**第一次赋值**决定，之后往 bool 变量里放资源会被静默转成 true
        // （实测：Link 构造函数收到 `Argument #1 must be of type resource, true given`）。
        if ($async) {
            $sockA = @stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
            if ($sockA === false) {
                return new Link(null);
            }
            return new Link($sockA);
        }
        $sockS = @stream_socket_client($uri, $errno, $errstr, 2.0);
        if ($sockS === false) {
            return new Link(null);
        }
        return new Link($sockS);
    }

    /** 单调毫秒。这里不取墙钟：系统时间被 NTP 调整会让退避瞬间跳变 */
    private function nowMs(): int
    {
        return intdiv(hrtime(true), 1000000);
    }

    /** 对一个登记过的对端发起一次非阻塞拨号，并安排好下一次尝试 */
    private function dialPeer(int $node, string $path): void
    {
        $now = $this->nowMs();
        $link = $this->dial($path, true);
        if ($link->alive()) {
            $link->markConnecting($now);
            $link->node = $node;
            $link->beginHandshake($this->nodeId, $this->codec->name(), $this->secret);
            $this->links[$node] = $link;
            $this->stat["links"] = count($this->links);
            $this->stat["reconnects"]++;
        } else {
            $this->stat["connect_fails"]++;
            $this->backoffMs[$node] = $this->nextBackoff($node);
        }
        // 连上也先安排下一次：真的握手成功时再把退避清掉。这样"连上→握手→马上断"
        // 不会变成每个 tick 都拨一次的热循环
        $this->scheduleRetry($node, $now);
    }

    /** 当前退避值（毫秒）：没退避过就是起始值 */
    private function nextBackoff(int $node): int
    {
        $b = $this->backoffMs[$node] ?? $this->backoffMinMs;
        $b *= 2;
        if ($b > $this->backoffMaxMs) {
            $b = $this->backoffMaxMs;
        }
        return $b;
    }

    /** 链路断了：如果它是登记过的对端，安排一次重连 */
    private function scheduleRetry(int $node, int $now): void
    {
        if (!isset($this->peers[$node])) {
            return;
        }
        $b = $this->backoffMs[$node] ?? $this->backoffMinMs;
        if ($b < $this->backoffMinMs) {
            $b = $this->backoffMinMs;
        }
        // 抖动：两端用同一套退避节奏时，同时互拨会一直撞在一起（见 adopt 的裁决）。
        // 不用 random_int——AOT 下的可用性没验证过；取一个和计数相关的量就够散
        $span = intdiv($b, 4) + 1;
        $jitter = ($this->stat["reconnects"] * 6151) % $span;
        $this->retryAt[$node] = $now + $b + $jitter;
    }

    /**
     * 每 tick 末尾对一遍：登记过的对端里，谁现在没有链路、又到了可以拨号的时间。
     * `peers` 为空（没人用 watchPeer）时零开销。
     */
    private function reconcile(): void
    {
        if ($this->peers === []) {
            return;
        }
        $now = $this->nowMs();
        foreach ($this->peers as $node => $path) {
            if (isset($this->links[$node])) {
                continue;
            }
            $at = $this->retryAt[$node] ?? 0;
            if ($now < $at) {
                continue;
            }
            $this->dialPeer($node, $path);
        }
    }

    /**
     * 这条链路是否**可用**（已建链且握手完成）。
     *
     * 刻意不返回"socket 连上了"：握手没完成时消息发不出去（对端会当垃圾丢掉），
     * 那种状态下告诉调用方"通"只会让它以为投递成功。
     */
    public function hasPeer(int $node): bool
    {
        if (!isset($this->links[$node])) {
            return false;
        }
        return $this->links[$node]->handshakeDone();
    }

    /** @return array<int> 已完成握手的节点号（排障用） */
    public function peerNodes(): array
    {
        $out = [];
        foreach ($this->links as $node => $link) {
            if ($link->handshakeDone()) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * 把消息排进对应节点的发送缓冲。返回 false 表示对端不可达（没建链）。
     *
     * @param int $srcLocalId 发送方在本节点的服务 id（本方法负责补上节点号）
     */
    public function send(int $dst, int $srcLocalId, int $msgId, string|array|object $data, int $session = 0): bool
    {
        $node = ($dst >> Addr::ID_BITS) & 0xFF;
        if (!isset($this->links[$node])) {
            $this->stat["drop"]++;
            return false;
        }
        $link = $this->links[$node];
        $pending = $link->pendingBytes();
        if ($link->noteOut($pending, self::MAX_OUT)) {
            // 对端不读：不再往里堆（见 MAX_OUT）。丢弃是可见的——返回 false 并计数，
            // 而"入队成功但永远发不出去"才是静默丢消息。
            // 日志只在"刚进入满状态"时记一次：持续满的时候每条消息都打一行，
            // 那本身就是一次日志洪泛
            Logger::warn("cluster: send buffer full, dropping messages to this node", [
                "node" => $node,
                "pending" => $pending,
            ]);
        }
        if ($pending > self::MAX_OUT) {
            $this->stat["drop"]++;
            return false;
        }
        $payload = $this->codec->pack($msgId, $data);
        $src = Addr::pack($this->nodeId, $srcLocalId);
        $link->queue(Packet::encode($dst, $src, $session, $msgId, $payload));
        $this->stat["out"]++;
        return true;
    }

    /**
     * 收取所有可读数据，返回解好的包（data 已按 codec 还原）。
     *
     * 每条连接先过握手：**连上了不等于能用了**。没握手完成前 `Link::drain()`
     * 一律返回空，所以垃圾连接最多浪费一次 read，不会进业务。
     */
    public function poll(): array
    {
        $packets = [];
        $this->acceptNew();
        $now = $this->nowMs();

        $nodes = [];
        foreach ($this->links as $n => $l) {
            $nodes[] = $n;
        }
        foreach ($nodes as $node) {
            if (!isset($this->links[$node])) {
                continue;
            }
            $link = $this->links[$node];
            if ($link->connecting()) {
                $st = $link->stepConnect($now, $this->connectTimeoutMs);
                if ($st < 0) {
                    $this->dropLink($node, "connect-failed");
                }
                continue;
            }
            $got = $link->pull();
            if ($got < 0) {
                $this->dropLink($node, $link->overrun() ? "read-buffer-overflow" : "peer-closed");
                continue;
            }

            // 已握手且没有新数据：直接过。这样的链路每个 tick 都会走到这里，
            // 不能让它们为下面的异常保护付钱——只有"有数据"或"还在握手"的才进 try
            if ($got <= 0 && $link->handshakeDone()) {
                continue;
            }

            // 从这里开始，任何一步抛异常都意味着这条链路的读缓冲已经不可信
            // （见 badLink 的注释），所以整段包住
            $usable = $link->handshakeDone();
            try {
                if ($usable) {
                    if ($got > 0) {
                        $list = $this->pump($link);
                        foreach ($list as $p) {
                            $packets[] = $p;
                        }
                    }
                } else {
                    $link->stepHandshake();
                    if ($link->handshakeFailed()) {
                        $this->reject($link, "out", $node);
                        $this->dropLink($node, "handshake-rejected");
                    } elseif ($link->handshakeDone()) {
                        if ($link->peerNode() !== $node) {
                            // 连的是 node 3，对方自称 node 5：地址和身份对不上，不能凑合
                            $this->reject($link, "peer-mismatch", $node);
                            $this->dropLink($node, "peer-mismatch");
                        } else {
                            Logger::info("cluster: link up", ["node" => $node, "side" => "out"]);
                            $this->linkUp($node, $now);
                            if ($got > 0) {
                                $list = $this->pump($link);
                                foreach ($list as $p) {
                                    $packets[] = $p;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->badLink($link, "out", $node, $e);
            }
        }

        // 入站连接：握手完成后才知道它是哪个节点
        $kept = [];
        foreach ($this->inbound as $link) {
            $got = $link->pull();
            if ($got < 0) {
                // 还没握手就断的连接（端口扫描、连上不发）是常态，不值得记日志；
                // 读缓冲超限才异常，那个要留痕
                if ($link->overrun()) {
                    Logger::warn("cluster: inbound read buffer overflow before handshake, closing", [
                        "limit" => Link::MAX_IN,
                    ]);
                }
                $link->close();
                continue;
            }
            try {
                $link->stepHandshake();
                if ($link->handshakeFailed()) {
                    $this->reject($link, "in", 0);
                } elseif (!$link->handshakeDone()) {
                    $kept[] = $link;
                } elseif ($this->adopt($link, $link->peerNode())) {
                    if ($got > 0) {
                        $list = $this->pump($link);
                        foreach ($list as $p) {
                            $packets[] = $p;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->badLink($link, "in", $link->peerNode(), $e);
            }
        }
        $this->inbound = $kept;

        $this->reconcile();

        return $packets;
    }

    /** 握手完成：记下时刻。链路稳定过（保持 ≥ stableMs）之后再断，退避才清零 */
    private function linkUp(int $node, int $now): void
    {
        $this->upAt[$node] = $now;
    }

    private function linkDown(int $node, int $now): void
    {
        $up = $this->upAt[$node] ?? 0;
        if ($up > 0 && $now - $up >= $this->stableMs) {
            // 稳定过：退避从头算
            unset($this->backoffMs[$node]);
        } else {
            // 没稳定过（没握上手、或者握上很快就断）：翻倍
            $cur = $this->backoffMs[$node] ?? 0;
            $cur *= 2;
            if ($cur < $this->backoffMinMs) {
                $cur = $this->backoffMinMs;
            }
            if ($cur > $this->backoffMaxMs) {
                $cur = $this->backoffMaxMs;
            }
            $this->backoffMs[$node] = $cur;
        }
        unset($this->upAt[$node]);
        $this->scheduleRetry($node, $now);
    }

    /**
     * 把一条链路读缓冲里的完整包取出来、解包。包本身解不出来时抛异常，由调用方处置链路。
     *
     * 单独成方法是为了让 poll() 的两处（出站 / 入站）只有一份"取包 + 解包"。
     */
    private function pump(Link $link): array
    {
        $out = [];
        foreach ($link->drain() as $p) {
            $p->data = $this->codec->unpack($p->msgId, $p->payload);
            $out[] = $p;
            $this->stat["in"]++;
        }
        return $out;
    }

    /**
     * 入站链路握手完成：认领为对端节点的链路。返回是否认领成功（成功后它已不在 inbound 里）。
     *
     * 不成功的三种情况都已经把连接关掉了：节点号非法、和本节点重号、以及"这个节点已经有链路"。
     */
    private function adopt(Link $link, int $node): bool
    {
        if ($node <= 0 || $node === $this->nodeId) {
            $this->reject($link, "bad-node", 0);
            return false;
        }
        if (isset($this->links[$node])) {
            $cur = $this->links[$node];
            if ($cur->handshakeDone() || $node > $this->nodeId) {
                // 已有可用链路：保留先到的那条，这条关掉。
                // 或者两条都还没通，而**对端的节点号比我们大**：保留自己发起的那条——
                // 对端那边会做相反的判断，关掉它的出站、留下我们这条。
                //
                // 为什么需要这条裁决：两端同时互拨时，如果双方都关掉"收到的"、保留"发起的"，
                // 结果是两条出站都被对端关掉，谁也不剩。没有自动重连时这只是"同时拨号就连不上"，
                // 有了重连，两边按同一套退避节奏会反复撞在一起。按节点号裁决，两端结论必然一致。
                $this->stat["dup"]++;
                $link->close();
                return false;
            }
            // 两条都还没通，而且这条入站来自节点号更小的一端：用它，关掉自己的出站
            $cur->close();
            unset($this->links[$node]);
            Logger::info("cluster: both sides dialed, keeping the one from the lower node id", ["node" => $node]);
        }
        $link->inbound = true;
        $this->links[$node] = $link;
        $this->stat["links"] = count($this->links);
        Logger::info("cluster: link up", ["node" => $node, "side" => "in"]);
        $this->linkUp($node, $this->nowMs());
        return true;
    }

    /**
     * 链路上出现了解不开的数据：**废弃这条链路**。
     *
     * ## 为什么不是"跳过这个包"
     *
     * 帧头非法时，坏字节会一直留在读缓冲的队首——后面的包永远排在它后面。
     * 跳过异常只会让下个 tick 再抛一次，对端继续发、缓冲就一直涨。
     * 没有办法在字节流里"重新对齐"，所以唯一能做的是断开：对端会发现，
     * 重连之后从干净的状态开始。
     *
     * ## 修之前是什么样
     *
     * 异常从 `Scheduler::tick()` 一路穿到 Swoole，它打一行 `PHP Fatal error`（带堆栈）
     * 然后继续。实测（2026-11-25，tests/cluster-guard）：
     *
     * - 坏包堵在队首后，对端再发 25.6 万个合法包**一个都没被处理**；
     *   而这边 `hasPeer()` 仍是 true、`send()` 仍然返回成功——一条"黑洞"链路；
     * - 那些包全部堆在读缓冲里，没有上限（实测涨到约 8.7 MB，对端不停就继续涨）；
     * - 链路上每来一次新数据就多一行 Fatal：2 秒 1028 行，不经 `Logger`、不受级别控制。
     *
     * `Transport\Server::feed()` 对客户端的坏帧一直是"关连接"，这里应该一致。
     */
    private function badLink(Link $link, string $where, int $node, \Throwable $e): void
    {
        $this->stat["bad_pkt"]++;
        Logger::error("cluster: dropping link on bad data", [
            // links 里的出站/入站链路混在一起，只有 Link 自己知道它是哪一侧
            "side" => $link->inbound ? "in" : $where,
            "node" => $node,
            "err" => $e->getMessage(),
        ]);
        $link->close();
        if ($node > 0 && isset($this->links[$node]) && $this->links[$node] === $link) {
            // 只摘自己：入站链路被 dup 拒绝时节点号可能和已存在的那条相同
            unset($this->links[$node]);
            $this->stat["links"] = count($this->links);
            // 对端发了坏数据也可能是"它刚重启、版本不对"：登记过的照样重连
            $this->linkDown($node, $this->nowMs());
        }
    }

    /**
     * 记一次握手拒绝：计数 + 带原因的日志。
     *
     * 只记数不记原因等于没记：`bad-mac`（密钥配错）和 `codec-mismatch`
     * （两端 payload 协议不一致）的处理方式完全不同，看到计数只能猜。
     */
    private function reject(Link $link, string $where, int $expect): void
    {
        $this->stat["hs_fail"]++;
        Logger::warn("cluster: handshake rejected", [
            "side" => $where,
            "expect" => $expect,
            "claimed" => $link->claimedNode(),
            "why" => $link->failReason(),
            "target" => $this->endpoint(),
        ]);
        $link->close();
    }

    /** 把攒下的写缓冲发出去；断掉的链路顺手摘除 */
    public function flush(): void
    {
        $nodes = [];
        foreach ($this->links as $n => $l) {
            $nodes[] = $n;
        }
        foreach ($nodes as $node) {
            if (!isset($this->links[$node])) {
                continue;
            }
            if (!$this->links[$node]->flush()) {
                $this->dropLink($node, "write-failed");
            }
        }
    }

    public function closeAll(): void
    {
        foreach ($this->links as $node => $link) {
            $link->close();
        }
        $this->links = [];
        foreach ($this->inbound as $link) {
            $link->close();
        }
        $this->inbound = [];
        // 登记也要清：closeAll() 的语义是"整个通道都不要了"，
        // 留着 peers 的话下一次 poll() 就会把它们全拨回去
        $this->peers = [];
        $this->retryAt = [];
        $this->backoffMs = [];
        $this->upAt = [];
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
        // 只有 unix 需要删 socket 文件；tcp 的 target 是 host:port，删不得
        if ($this->scheme === "unix") {
            @unlink($this->target);
        }
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["links"] = count($this->links);
        $out["inbound"] = count($this->inbound);
        $out["peers"] = count($this->peers);
        return $out;
    }

    private function acceptNew(): void
    {
        if ($this->server === null) {
            return;
        }
        while (true) {
            $conn = @stream_socket_accept($this->server, 0);
            if ($conn === false) {
                return;
            }
            $link = new Link($conn);
            // 入站也要先发 hello：握手是双向的，两边都得证明自己是谁
            $link->beginHandshake($this->nodeId, $this->codec->name(), $this->secret);
            $this->inbound[] = $link;
        }
    }

    /**
     * 一条已认领的链路断了：关掉并摘除。
     *
     * 记一条 warn。节点失联、网络抖动都会走到这里，而在此之前它是**静默**的——
     * 只有 `links` 计数减一，没有任何日志说明"什么时候、哪个节点、为什么"断的。
     * 节点掉线后发往它的消息全部计入 `drop`，却没人知道是从哪一刻开始的。
     *
     * 一条链路只会触发一次（摘除之后就不在 `links` 里了），不会被刷。
     */
    private function dropLink(int $node, string $why): void
    {
        if (!isset($this->links[$node])) {
            return;
        }
        $this->links[$node]->close();
        unset($this->links[$node]);
        $this->stat["links"] = count($this->links);
        $now = $this->nowMs();
        Logger::warn("cluster: link down", [
            "node" => $node,
            "why" => $why,
            "links" => $this->stat["links"],
            "retry_ms" => isset($this->peers[$node]) ? ($this->backoffMs[$node] ?? $this->backoffMinMs) : -1,
        ]);
        $this->linkDown($node, $now);
    }
}
