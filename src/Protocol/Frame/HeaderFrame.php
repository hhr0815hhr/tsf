<?php

namespace TSF\Protocol\Frame;

use TSF\Protocol\Contract\DecodedFrame;
use TSF\Protocol\Contract\FrameCodec;

/**
 * 分帧实现：8 字节定长头（纯 PHP 参考实现）。
 *
 *   | len(4B, 含头) | msgId(2B) | flags(1B) | reserved(1B) | body |
 *
 * 这是协议的**唯一权威实现**：字节布局、合法性校验、上限都在这里定义。
 * NativeHeaderFrame 是同一格式的 C++ 版本，两者必须保持字节级一致
 * （bench 里会逐字节比对）。
 *
 * 客户端也用它：`$f = new HeaderFrame(); $f->encode(...) / $f->decode(...)`。
 */
final class HeaderFrame implements FrameCodec
{
    public const HEADER_SIZE = 8;

    /** 单帧上限，防止恶意大包打爆内存 */
    public const MAX_FRAME = 1024 * 1024;

    public function name(): string
    {
        return "header/php";
    }

    public function encode(int $msgId, string $body, int $flags = 0): string
    {
        $len = self::HEADER_SIZE + strlen($body);
        return chr(($len >> 24) & 0xFF)
            . chr(($len >> 16) & 0xFF)
            . chr(($len >> 8) & 0xFF)
            . chr($len & 0xFF)
            . chr(($msgId >> 8) & 0xFF)
            . chr($msgId & 0xFF)
            . chr($flags & 0xFF)
            . "\x00"
            . $body;
    }

    public function decode(string $buf): ?DecodedFrame
    {
        $have = strlen($buf);
        if ($have < self::HEADER_SIZE) {
            return null;
        }

        // 这里刻意内联位运算而不调 self::u32/u16：这是服务端每帧都要跑的热路径，
        // 多一次静态调用约 150 ns（AOT 实测），不值得为复用两行算术付这个代价。
        $len = (ord($buf[0]) << 24) | (ord($buf[1]) << 16) | (ord($buf[2]) << 8) | ord($buf[3]);
        if ($len < self::HEADER_SIZE) {
            // 非法帧头：抛给上层断开连接，避免拆包循环空转
            throw new \RuntimeException("frame: bad length " . $len);
        }
        if ($len > self::MAX_FRAME) {
            throw new \RuntimeException("frame: too large " . $len);
        }
        if ($have < $len) {
            return null;
        }

        $f = new DecodedFrame();
        $f->msgId = (ord($buf[4]) << 8) | ord($buf[5]);
        $f->flags = ord($buf[6]);
        $f->body = substr($buf, self::HEADER_SIZE, $len - self::HEADER_SIZE);
        $f->consumed = $len;
        return $f;
    }

    /** 客户端读半包时先取长度用（服务端热路径不走这里） */
    public static function u16(string $s, int $off): int
    {
        return (ord($s[$off]) << 8) | ord($s[$off + 1]);
    }

    public static function u32(string $s, int $off): int
    {
        return (ord($s[$off]) << 24) | (ord($s[$off + 1]) << 16) | (ord($s[$off + 2]) << 8) | ord($s[$off + 3]);
    }

    /**
     * 客户端便捷入口：等价于 (new self())->encode(...)。
     *
     * 服务端不要用——每调用一次会多分配一个对象；服务端拿的是实例（FrameCodec）。
     * 存在的理由：客户端/测试脚本常在函数里编码，需要不依赖实例的调用方式。
     */
    public static function build(int $msgId, string $body, int $flags = 0): string
    {
        $f = new HeaderFrame();
        return $f->encode($msgId, $body, $flags);
    }

    /** 客户端便捷入口：等价于 (new self())->decode(...) */
    public static function parse(string $buf): ?DecodedFrame
    {
        $f = new HeaderFrame();
        return $f->decode($buf);
    }
}
