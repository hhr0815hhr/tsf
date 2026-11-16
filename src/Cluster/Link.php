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
    /** 对端节点号；0 表示还没认领（入站连接在收到第一个包后才知道对方是谁） */
    public int $node = 0;

    /** @var resource|null */
    private $sock = null;

    private string $in = "";

    private string $out = "";

    /** @param resource $sock */
    public function __construct($sock)
    {
        $this->sock = $sock;
        stream_set_blocking($sock, false);
        // 关掉写缓冲合并：我们要的是"尽快发出去"，不是"攒够再发"
        stream_set_write_buffer($sock, 0);
    }

    /** @return resource|null */
    public function socket()
    {
        return $this->sock;
    }

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

    /** @return array<int, Packet> 从读缓冲里解出所有完整包 */
    public function drain(): array
    {
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
