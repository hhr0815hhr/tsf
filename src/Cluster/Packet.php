<?php

namespace TSF\Cluster;

/**
 * 跨节点消息的线格式。与分帧层（HeaderFrame）分开定义，因为语义不同：
 * 分帧层只负责"切出边界 + 一个协议号"，而这里要带**目标/源服务地址**。
 *
 *   | len(4B, 整包) | dst(4B) | src(4B) | session(4B) | msgId(2B) | payload |
 *
 * 18 字节头 + 载荷。dst/src 是完整的服务地址（含节点号），所以中继节点也能直接转发。
 *
 * 为什么这里用 chr/ord 而不是 pack/unpack：写这段的时候 pack 在 AOT 下的支持面没验证过，
 * 而 chr/ord 在所有模式下都稳。**后来实测过了**（`bench/probe-pack`）：pack/unpack 的
 * g / e / V / P 在 AOT 下全部可用且正确（含负数、零、次正规数），`Proto\Wire` 用它编
 * float/double。这里没换，是因为这段是热路径且现状没问题——但"pack 不能用"这个印象是错的。
 * （与 HeaderFrame 保持一致的做法）。
 */
final class Packet
{
    public const HEADER_SIZE = 18;

    /** 单包上限，防止恶意大包打爆内存 */
    public const MAX_PACKET = 1024 * 1024;

    public int $dst = 0;

    public int $src = 0;

    public int $session = 0;

    public int $msgId = 0;

    /** 线上的原始载荷字节 */
    public string $payload = "";

    /** 解包后的业务数据，由 Cluster::poll 填（未填时是空串） */
    public string|array|object $data = "";

    /** 本包在缓冲区里占用的字节数 */
    public int $consumed = 0;

    public static function encode(int $dst, int $src, int $session, int $msgId, string $payload): string
    {
        $len = self::HEADER_SIZE + strlen($payload);
        return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF)
            . chr(($dst >> 24) & 0xFF) . chr(($dst >> 16) & 0xFF) . chr(($dst >> 8) & 0xFF) . chr($dst & 0xFF)
            . chr(($src >> 24) & 0xFF) . chr(($src >> 16) & 0xFF) . chr(($src >> 8) & 0xFF) . chr($src & 0xFF)
            . chr(($session >> 24) & 0xFF) . chr(($session >> 16) & 0xFF) . chr(($session >> 8) & 0xFF) . chr($session & 0xFF)
            . chr(($msgId >> 8) & 0xFF) . chr($msgId & 0xFF)
            . $payload;
    }

    /** 数据不足返回 null；包头非法抛异常（调用方应断开该连接） */
    public static function tryDecode(string $buf): ?Packet
    {
        $have = strlen($buf);
        if ($have < self::HEADER_SIZE) {
            return null;
        }
        $len = (ord($buf[0]) << 24) | (ord($buf[1]) << 16) | (ord($buf[2]) << 8) | ord($buf[3]);
        if ($len < self::HEADER_SIZE) {
            throw new \RuntimeException("cluster: bad packet length " . $len);
        }
        if ($len > self::MAX_PACKET) {
            throw new \RuntimeException("cluster: packet too large " . $len);
        }
        if ($have < $len) {
            return null;
        }

        $p = new Packet();
        $p->dst = (ord($buf[4]) << 24) | (ord($buf[5]) << 16) | (ord($buf[6]) << 8) | ord($buf[7]);
        $p->src = (ord($buf[8]) << 24) | (ord($buf[9]) << 16) | (ord($buf[10]) << 8) | ord($buf[11]);
        $p->session = (ord($buf[12]) << 24) | (ord($buf[13]) << 16) | (ord($buf[14]) << 8) | ord($buf[15]);
        $p->msgId = (ord($buf[16]) << 8) | ord($buf[17]);
        $p->payload = substr($buf, self::HEADER_SIZE, $len - self::HEADER_SIZE);
        $p->consumed = $len;
        return $p;
    }
}
