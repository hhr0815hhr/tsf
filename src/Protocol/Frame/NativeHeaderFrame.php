<?php

namespace TSF\Protocol\Frame;

use TSF\Protocol\Contract\DecodedFrame;
use TSF\Protocol\Contract\FrameCodec;

/**
 * 分帧实现：同一 8 字节头格式，逐字节操作下沉到 ext/codec（C++）。
 *
 * 与 HeaderFrame 字节格式完全一致，实测吞吐 +7~14%（见 bench/REPORT.md）。
 * 保留 PHP 版的原因：排障时可以切回去确认"问题在协议还是在原生层"。
 *
 * 依赖 c_encode / c_frame_len / c_decode_body 三个原语，
 * 由 ext/codec/php-src/codec.stub.php 声明。若工程没编译该扩展，
 * ProtocolStack 在装配时会显式报错，而不是运行时才炸。
 */
final class NativeHeaderFrame implements FrameCodec
{
    public function name(): string
    {
        return "header/native";
    }

    public function encode(int $msgId, string $body, int $flags = 0): string
    {
        return c_encode($msgId, $body, $flags);
    }

    public function decode(string $buf): ?DecodedFrame
    {
        $len = c_frame_len($buf);
        if ($len === 0) {
            return null;
        }
        if ($len < 0) {
            throw new \RuntimeException("frame: bad length");
        }

        $f = new DecodedFrame();
        // msgId/flags 内联取值：另写辅助方法会多两次调用（实测把收益吃掉一大半）
        $f->msgId = (ord($buf[4]) << 8) | ord($buf[5]);
        $f->flags = ord($buf[6]);
        $f->body = c_decode_body($buf, $len);
        $f->consumed = $len;
        return $f;
    }
}
