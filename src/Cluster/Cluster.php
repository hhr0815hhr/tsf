<?php

namespace TSF\Cluster;

use TSF\Protocol\Contract\PayloadCodec;
use TSF\Protocol\Payload\RawPayload;

/**
 * 跨节点通道：一个监听 socket + 若干条对端链路。
 *
 * 三条设计决定：
 *
 * 1. **地址直接上线**。包里有完整 dst/src（含节点号），所以中间节点也能转发，
 *    不需要另一个"路由表"概念。上层发消息只认地址，不关心本地还是远程。
 *
 * 2. **谁连谁不重要**。入站连接在收到第一个包后才"认领"成某个节点的链路
 *    （包里的 src 带节点号）。所以只有一端主动 connect 也能双向通信——
 *    对方回包走的就是这条已建立的连接。
 *
 * 3. **攒批写**。发送只入缓冲，`flush()` 每个 tick 末尾统一写一次。
 *    一轮发 100 条消息只付一次 syscall。延迟代价 < 一个 tick。
 *
 * 载荷用 PayloadCodec 打包/解包，默认 RawPayload（即只接受字符串）。
 * 要跨进程传结构化数据，两端必须配同一个非 raw 的 codec。
 */
final class Cluster
{
    private int $nodeId;

    private string $path;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, Link> 节点号 => 已建立的链路 */
    private array $links = [];

    /** @var array<int, Link> 尚未认领的入站连接 */
    private array $inbound = [];

    private PayloadCodec $codec;

    /** @var array<string, int> */
    private array $stat = [
        "out" => 0,
        "in" => 0,
        "drop" => 0,
        "dup" => 0,
        "links" => 0,
    ];

    public function __construct(int $nodeId, string $path, ?PayloadCodec $codec = null)
    {
        if ($nodeId <= 0 || $nodeId > 255) {
            throw new \RuntimeException("cluster: 节点号必须在 1..255（0 表示本地）");
        }
        $this->nodeId = $nodeId;
        $this->path = $path;
        $this->codec = $codec ?? new RawPayload();
    }

    public function nodeId(): int
    {
        return $this->nodeId;
    }

    public function listen(): bool
    {
        @unlink($this->path);
        $srv = @stream_socket_server("unix://" . $this->path, $errno, $errstr);
        if ($srv === false) {
            throw new \RuntimeException("cluster: 监听失败 " . $this->path . " (" . $errstr . ")");
        }
        stream_set_blocking($srv, false);
        $this->server = $srv;
        return true;
    }

    /** 主动连接某个节点（对方 listen 的路径） */
    public function connect(int $node, string $path): bool
    {
        $sock = @stream_socket_client("unix://" . $path, $errno, $errstr, 2.0);
        if ($sock === false) {
            return false;
        }
        stream_set_blocking($sock, false);
        stream_set_write_buffer($sock, 0);
        $link = new Link($sock);
        $link->node = $node;
        $this->links[$node] = $link;
        $this->stat["links"] = count($this->links);
        return true;
    }

    public function hasPeer(int $node): bool
    {
        return isset($this->links[$node]);
    }

    /** @return array<int> 已建链的节点号（排障用） */
    public function peerNodes(): array
    {
        $out = [];
        foreach ($this->links as $node => $link) {
            $out[] = $node;
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

    /** 收取所有可读数据，返回解好的包（data 已按 codec 还原） */
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
            if ($got === 0) {
                continue;
            }
            foreach ($link->drain() as $p) {
                $p->data = $this->codec->unpack($p->msgId, $p->payload);
                $packets[] = $p;
                $this->stat["in"]++;
            }
        }

        // 入站连接：收到第一个包才知道它是哪个节点
        $kept = [];
        foreach ($this->inbound as $link) {
            $got = $link->pull();
            if ($got < 0) {
                $link->close();
                continue;
            }
            $drained = $link->drain();
            if ($drained === []) {
                $kept[] = $link;
                continue;
            }
            $node = ($drained[0]->src >> Addr::ID_BITS) & 0xFF;
            if (isset($this->links[$node])) {
                // 双向都主动连了：保留先到的那条，这条关掉
                $this->stat["dup"]++;
                $link->close();
                continue;
            }
            $link->node = $node;
            $this->links[$node] = $link;
            $this->stat["links"] = count($this->links);
            foreach ($drained as $p) {
                $p->data = $this->codec->unpack($p->msgId, $p->payload);
                $packets[] = $p;
                $this->stat["in"]++;
            }
        }
        $this->inbound = $kept;

        return $packets;
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
        @unlink($this->path);
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
            $this->inbound[] = new Link($conn);
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
