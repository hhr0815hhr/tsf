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

    /**
     * 读缓冲上限（8 MB）。
     *
     * 正常情况下 `in` 永远很小：drain 每个 tick 都把完整包取走，剩下的只是半包尾巴。
     * 只有两种情况会让它涨：对端发得比本端处理得快（持续几秒才会积压到 MB 级），
     * 或者队首卡着一个坏包——坏长度让 tryDecode 永远返回 null，后面的包全都解不出来。
     * 实测后者：一个 18 字节的坏头让这条链路变成黑洞，25.6 万个合法包无一处理，
     * 缓冲无界增长，而 `hasPeer` 仍为 true、`send` 仍返回 true。
     * 所以超限即判定这条链路已不可恢复，由 Cluster 关掉重连，而不是继续吃内存。
     */
    public const MAX_IN = 8388608;

    /** 对端节点号；0 表示还没认领（握手未完成） */
    public int $node = 0;

    /** @var resource|null */
    private $sock = null;

    private string $in = "";

    private string $out = "";

    /** 读缓冲已超限（overrun）；Cluster 据此区分"对端关闭"和"本端主动丢弃" */
    private bool $over = false;

    /** 写缓冲是否处于"满"状态；只用于让满/恢复各记一次日志，不逐条刷屏 */
    private bool $outFull = false;

    /** 这条链路是对端连进来的（true）还是本端主动连出去的（false）；只用于日志 */
    public bool $inbound = false;

    /** 握手状态：0 未开始，1 已发本端 hello 等对端，2 完成，3 失败 */
    private int $hs = 0;

    private int $hsPeer = 0;

    private string $hsSecret = "";

    private string $hsCodec = "";

    private int $hsNode = 0;

    /** 握手失败原因（排障用） */
    private string $hsWhy = "";

    private static int $nonceSeq = 0;

    /**
     * 还在 TCP 连接阶段（非阻塞 connect 尚未完成）。
     *
     * 这个状态下 pull() 不读、flush() 不写：对一个没连上的 socket 读写只会立刻失败，
     * 然后被当成"写失败"把链路摘掉——而它只是还没连上而已。
     */
    private bool $connecting = false;

    /** 发起拨号的时刻（单调毫秒），用来判定连接超时 */
    private int $dialedAt = 0;

    /**
     * @param resource|null $sock 传 null 表示拨号就已经失败（见 Cluster::dial）
     */
    public function __construct($sock)
    {
        $this->sock = $sock;
        if ($sock === null) {
            return;
        }
        stream_set_blocking($sock, false);
        // 关掉写缓冲合并：我们要的是"尽快发出去"，不是"攒够再发"
        stream_set_write_buffer($sock, 0);
    }

    /** socket 还在（拨号没有当场失败，也没有被关掉） */
    public function alive(): bool
    {
        return $this->sock !== null;
    }

    /** 标记为"连接中"：由 Cluster 在发起非阻塞 connect 之后调用 */
    public function markConnecting(int $nowMs): void
    {
        $this->connecting = true;
        $this->dialedAt = $nowMs;
    }

    public function connecting(): bool
    {
        return $this->connecting;
    }

    /**
     * 推进非阻塞连接。返回 1 = 连上了，0 = 还在连，-1 = 失败。
     *
     * 只看"是否可写"，**不在这里判断连接到底成没成**：连上的 socket 会变成可写，
     * 被拒绝的 socket 在多数平台上也会（带着一个待读的错误），要区分得查 SO_ERROR，
     * 那需要 ext/sockets。所以连上之后紧接着的 flush() 写 hello：真被拒了 fwrite 会失败，
     * 走"写失败"把链路摘掉，和这里的 -1 殊途同归——都会进重连退避。
     *
     * 超时是必须的：不可达地址（以及某些网络栈下的被拒连接）**永远不会有任何事件**。
     * 实测（WSL2，2026-11-25）：对没人监听的回环端口 connect，流的 select 和
     * ext/sockets 的 select 都是 2 秒内零事件，连 bash 的 /dev/tcp 都会挂住。
     */
    public function stepConnect(int $nowMs, int $timeoutMs): int
    {
        if (!$this->connecting) {
            return 1;
        }
        if ($this->sock === null) {
            return -1;
        }
        $r = [];
        $w = [$this->sock];
        $e = [$this->sock];
        $n = @stream_select($r, $w, $e, 0);
        if ($n === false) {
            return -1;
        }
        if ($n > 0) {
            $this->connecting = false;
            return 1;
        }
        if ($nowMs - $this->dialedAt >= $timeoutMs) {
            return -1;
        }
        return 0;
    }

    /** @return resource|null */
    public function pendingBytes(): int
    {
        return strlen($this->out);
    }

    /**
     * 写缓冲满状态迁移：刚进入"满"返回 true（调用方记一次 warn），
     * 仍满或已回落返回 false。避免每条消息都打一行"缓冲已满"。
     */
    public function noteOut(int $pending, int $limit): bool
    {
        if ($pending <= $limit) {
            $this->outFull = false;
            return false;
        }
        if ($this->outFull) {
            return false;
        }
        $this->outFull = true;
        return true;
    }

    public function overrun(): bool
    {
        return $this->over;
    }

    public function queue(string $bytes): void
    {
        $this->out .= $bytes;
    }

    /** 把写缓冲尽可能发出去。返回 false 表示连接已断（调用方应摘掉这条链路） */
    public function flush(): bool
    {
        if ($this->out === "" || $this->connecting) {
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
        if ($this->connecting) {
            return 0;
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
        if (strlen($this->in) > self::MAX_IN) {
            // 队首坏包或对端洪水：这条链路已不可恢复，交给 Cluster 摘掉
            $this->over = true;
            return -1;
        }
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
            if (!hash_equals($expect, $mac)) {
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
