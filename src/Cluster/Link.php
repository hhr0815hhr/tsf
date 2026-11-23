<?php

namespace TSF\Cluster;

/**
 * 一条对端链路：一个非阻塞 socket + 读写缓冲。
 *
 * 写入不立即落地，先追加到 `out`，由 Cluster 在每个 tick 末尾统一 write 一次——
 * 这就是"攒批"：一轮里发 100 条消息只付一次 syscall，而不是 100 次。
 * 代价是最多攒一个 tick 的延迟（亚毫秒级），换来数量级的 syscall 减少。
 */
final class Link
{
    /**
     * 握手魔数 + 版本。每个连接的第一批字节必须是它。
     *
     * 为什么要有魔数而不是"直接发第一个包"：TCP 上没有连接边界可言，
     * 对方连上来先发半截垃圾（端口扫描、别的服务连错、旧版本客户端）时，
     * 只有魔数能让服务端立刻判定"这不是我的对端"，而不是拿它当包长度解析
     * 然后分配一块几百 MB 的缓冲——那是 `Packet::MAX_PACKET` 挡不住的。
     */
    public const HS_MAGIC = "TSFC\x01";

    /** 对端节点号；0 表示还没认领（握手未完成） */
    public int $node = 0;

    /** @var resource|null */
    private $sock = null;

    private string $in = "";

    private string $out = "";

    /** 握手状态：0 未开始，1 已发本端 hello 等对端，2 完成，3 失败 */
    private int $hs = 0;

    private int $hsPeer = 0;

    private string $hsSecret = "";

    private string $hsCodec = "";

    private int $hsNode = 0;

    /** 握手失败原因（排障用） */
    private string $hsWhy = "";

    private static int $nonceSeq = 0;

    /** @param resource $sock */
    public function __construct($sock)
    {
        $this->sock = $sock;
        stream_set_blocking($sock, false);
        // 关掉写缓冲合并：我们要的是"尽快发出去"，不是"攒够再发"
        stream_set_write_buffer($sock, 0);
    }

    /** @return resource|null */
    public function pendingBytes(): int
    {
        return strlen($this->out);
    }

    public function queue(string $bytes): void
    {
        $this->out .= $bytes;
    }

    /** 把写缓冲尽可能发出去。返回 false 表示连接已断（调用方应摘掉这条链路） */
    public function flush(): bool
    {
        if ($this->out === "") {
            return true;
        }
        if ($this->sock === null) {
            return false;
        }
        $n = @fwrite($this->sock, $this->out);
        if ($n === false) {
            return false;
        }
        if ($n < strlen($this->out)) {
            // 部分写（对端读得慢）：剩下的留到下次，不丢
            $this->out = substr($this->out, $n);
            return true;
        }
        $this->out = "";
        return true;
    }

    /** 读入缓冲。返回读到的字节数；-1 表示对端关闭或出错 */
    public function pull(): int
    {
        if ($this->sock === null) {
            return -1;
        }
        $chunk = @fread($this->sock, 262144);
        if ($chunk === false) {
            return -1;
        }
        if ($chunk === "") {
            if (feof($this->sock)) {
                return -1;
            }
            return 0;
        }
        $this->in .= $chunk;
        return strlen($chunk);
    }

    /**
     * 发起握手：往写缓冲里排 `魔数 + 一个 hello 包`。
     *
     * 必须在任何业务包之前调用（Cluster 在 connect/accept 之后立刻调），
     * 这样 hello 天然排在队首，不依赖调用顺序的自觉。
     *
     * hello 载荷是 `节点号 \x1f codec \x1f nonce \x1f mac` 四段，用 0x1F
     * （单元分隔符）而不是 json：AOT 下字符串拼接最稳，而且省一次序列化。
     * 没配 secret 时 mac 段写 `-`，Cluster 会据此决定是否校验。
     */
    public function beginHandshake(int $nodeId, string $codec, string $secret): void
    {
        $this->hsNode = $nodeId;
        $this->hsCodec = $codec;
        $this->hsSecret = $secret;

        $nonce = self::nonce();
        $body = $nodeId . "\x1f" . $codec . "\x1f" . $nonce;
        $mac = "-";
        if ($secret !== "") {
            $mac = hash_hmac("sha256", $body, $secret);
        }
        $this->queue(self::HS_MAGIC . Packet::encode(0, $nodeId, 0, 0, $body . "\x1f" . $mac));
        $this->hs = 1;
    }

    /**
     * nonce：只求"每次连接不同"，不求密码学随机。
     *
     * 不用 random_bytes 是因为它在 AOT 下的可用性没验证过；而这里的 nonce 只用于
     * 让服务端认出"这条 hello 不是刚才那条的重放"，配合 mac 足够。
     * 真要防历史重放得上 TLS 或窗口缓存，见 Cluster 的说明。
     */
    private static function nonce(): string
    {
        self::$nonceSeq++;
        return substr(
            hash("sha256", hrtime(true) . ":" . posix_getpid() . ":" . self::$nonceSeq),
            0,
            16
        );
    }

    public function handshakeDone(): bool
    {
        return $this->hs === 2;
    }

    public function handshakeFailed(): bool
    {
        return $this->hs === 3;
    }

    /** 握手完成后的对端节点号；未完成时返回 0 */
    public function peerNode(): int
    {
        return $this->hsPeer;
    }

    /** 握手阶段的对端节点号（即使校验失败也能取到，用于日志） */
    public function claimedNode(): int
    {
        return $this->hsPeer;
    }

    /**
     * 推进握手。每次 poll 后调用，直到 `handshakeDone()` 或 `handshakeFailed()`。
     *
     * 失败一律置状态返回，不抛异常：调用方是 poll 循环，一条坏连接不该
     * 打断整轮——但要在日志里说清是哪一项不过（魔数 / 版本 / mac / codec）。
     */
    public function stepHandshake(): void
    {
        if ($this->hs === 2 || $this->hs === 3) {
            return;
        }
        $have = strlen($this->in);
        if ($have < 5) {
            // 前几个字节就已经不一致：不等到攒够 5 字节再判，早断早省内存
            if ($have > 0 && strncmp($this->in, self::HS_MAGIC, $have) !== 0) {
                $this->fail("magic-mismatch");
            }
            return;
        }
        if (strncmp($this->in, self::HS_MAGIC, 5) !== 0) {
            $this->fail("bad-magic");
            return;
        }
        if ($have < 5 + Packet::HEADER_SIZE) {
            return;
        }

        // 先不切缓冲：只有解出完整包才消费，否则半包会被吃掉
        $p = Packet::tryDecode(substr($this->in, 5));
        if ($p === null) {
            return;
        }

        $parts = explode("\x1f", $p->payload);
        if (count($parts) !== 4) {
            $this->fail("bad-hello");
            return;
        }
        $node = (int) $parts[0];
        $codec = $parts[1];
        $nonce = $parts[2];
        $mac = $parts[3];
        $this->hsPeer = $node;

        if ($node < 1 || $node > 255) {
            $this->fail("node-out-of-range");
            return;
        }
        if ($node === $this->hsNode) {
            $this->fail("node-is-self");
            return;
        }
        if ($codec !== $this->hsCodec) {
            $this->fail("codec-mismatch:" . $codec . "!=" . $this->hsCodec);
            return;
        }
        if ($this->hsSecret !== "") {
            $expect = hash_hmac("sha256", $node . "\x1f" . $codec . "\x1f" . $nonce, $this->hsSecret);
            if ($expect !== $mac) {
                $this->fail("bad-mac");
                return;
            }
        }
        if ($nonce === "") {
            $this->fail("empty-nonce");
            return;
        }

        $this->in = substr($this->in, 5 + $p->consumed);
        $this->node = $node;
        $this->hs = 2;
    }

    /**
     * 握手失败。带上原因而不是只置状态：`hs_fail` 计数只能告诉你"有几条被拒"，
     * 真正排障时要知道的是"为什么被拒"——尤其 codec 不匹配和 mac 不对，
     * 处理方式完全不同（改配置 vs 改密钥）。
     */
    private function fail(string $why): void
    {
        $this->hsWhy = $why;
        $this->hs = 3;
    }

    /** 握手失败原因；未失败时返回空串 */
    public function failReason(): string
    {
        return $this->hsWhy;
    }

    /** @return array<int, Packet> 从读缓冲里解出所有完整包 */
    public function drain(): array
    {
        if ($this->hs !== 2) {
            return [];
        }
        $out = [];
        while (true) {
            $p = Packet::tryDecode($this->in);
            if ($p === null) {
                break;
            }
            $this->in = substr($this->in, $p->consumed);
            $out[] = $p;
        }
        return $out;
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
        $this->in = "";
        $this->out = "";
    }
}
