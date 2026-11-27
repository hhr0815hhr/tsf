<?php

namespace TSF\Gm;

/**
 * GM 通道的一条连接。
 *
 * socket 属性不写类型（`resource` 不是合法类型声明），用 docblock 标注——
 * 和 `Cluster\Link` 的做法一致。
 */
final class Conn
{
    /** @var resource|null */
    public $sock = null;

    public string $buf = "";

    public string $peer = "";

    /** 建立时间（ms），用来踢掉只连不发的连接 */
    public int $at = 0;

    /**
     * 非 0：已经回了响应并半关闭，正在排空对端没发完的请求体；值是放弃排空的时刻（ms）。
     * 见 Server::respondAndDrain()——413 之后直接 close 会变成 RST，对端读不到响应。
     */
    public int $drainUntil = 0;

    /**
     * 排空时还差多少字节对端就发完了（按它声明的 Content-Length 算）。
     * -1 = 不知道（请求头都没收全）；那就等到它关连接或超时。
     * 归零就可以 close：接收缓冲区是空的，发出去的是 FIN，不是 RST。
     */
    public int $drainLeft = -1;

    /** @param resource $sock */
    public function __construct($sock, string $peer, int $nowMs)
    {
        $this->sock = $sock;
        $this->peer = $peer;
        $this->at = $nowMs;
        stream_set_blocking($sock, false);
        stream_set_write_buffer($sock, 0);
    }
}
