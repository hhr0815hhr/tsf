<?php

namespace TSF\Cluster;

use TSF\Kernel\Logger;
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

    private PayloadCodec $codec;

    /** @var array<string, int> */
    private array $stat = [
        "out" => 0,
        "in" => 0,
        "drop" => 0,
        "dup" => 0,
        "links" => 0,
        "hs_fail" => 0,
    ];

    public function __construct(int $nodeId, string $path, ?PayloadCodec $codec = null, string $secret = "")
    {
        if ($nodeId <= 0 || $nodeId > 255) {
            throw new \RuntimeException("cluster: 节点号必须在 1..255（0 表示本地）");
        }
        $this->nodeId = $nodeId;
        $this->secret = $secret;
        $this->codec = $codec ?? new RawPayload();
        $this->applyAddr($path);
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
        $i = strpos($path, "://");
        $scheme = "unix";
        $target = $path;
        if ($i !== false) {
            $scheme = strtolower(substr($path, 0, $i));
            $target = substr($path, $i + 3);
        }
        $uri = $scheme === "unix" ? "unix://" . $target : "tcp://" . $target;

        $sock = @stream_socket_client($uri, $errno, $errstr, 2.0);
        if ($sock === false) {
            return false;
        }
        stream_set_blocking($sock, false);
        stream_set_write_buffer($sock, 0);
        $link = new Link($sock);
        $link->node = $node;
        $link->beginHandshake($this->nodeId, $this->codec->name(), $this->secret);
        $this->links[$node] = $link;
        $this->stat["links"] = count($this->links);
        return true;
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
        $payload = $this->codec->pack($msgId, $data);
        $src = Addr::pack($this->nodeId, $srcLocalId);
        $this->links[$node]->queue(Packet::encode($dst, $src, $session, $msgId, $payload));
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

        $nodes = [];
        foreach ($this->links as $n => $l) {
            $nodes[] = $n;
        }
        foreach ($nodes as $node) {
            if (!isset($this->links[$node])) {
                continue;
            }
            $link = $this->links[$node];
            $got = $link->pull();
            if ($got < 0) {
                $this->dropLink($node);
                continue;
            }

            if (!$link->handshakeDone()) {
                $link->stepHandshake();
                if ($link->handshakeFailed()) {
                    $this->reject($link, "out", $node);
                    $this->dropLink($node);
                    continue;
                }
                if (!$link->handshakeDone()) {
                    continue;
                }
                if ($link->peerNode() !== $node) {
                    // 连的是 node 3，对方自称 node 5：地址和身份对不上，不能凑合
                    $this->reject($link, "peer-mismatch", $node);
                    $this->dropLink($node);
                    continue;
                }
            }

            if ($got <= 0) {
                continue;
            }
            foreach ($link->drain() as $p) {
                $p->data = $this->codec->unpack($p->msgId, $p->payload);
                $packets[] = $p;
                $this->stat["in"]++;
            }
        }

        // 入站连接：握手完成后才知道它是哪个节点
        $kept = [];
        foreach ($this->inbound as $link) {
            $got = $link->pull();
            if ($got < 0) {
                $link->close();
                continue;
            }
            $link->stepHandshake();
            if ($link->handshakeFailed()) {
                $this->reject($link, "in", 0);
                continue;
            }
            if (!$link->handshakeDone()) {
                $kept[] = $link;
                continue;
            }
            $node = $link->peerNode();
            if ($node <= 0 || $node === $this->nodeId) {
                $this->reject($link, "bad-node", 0);
                continue;
            }
            if (isset($this->links[$node])) {
                // 双向都主动连了：保留先到的那条，这条关掉
                $this->stat["dup"]++;
                $link->close();
                continue;
            }
            $this->links[$node] = $link;
            $this->stat["links"] = count($this->links);
            foreach ($link->drain() as $p) {
                $p->data = $this->codec->unpack($p->msgId, $p->payload);
                $packets[] = $p;
                $this->stat["in"]++;
            }
        }
        $this->inbound = $kept;

        return $packets;
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
                $this->dropLink($node);
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

    private function dropLink(int $node): void
    {
        if (!isset($this->links[$node])) {
            return;
        }
        $this->links[$node]->close();
        unset($this->links[$node]);
        $this->stat["links"] = count($this->links);
    }
}
