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
