<?php

namespace TSF\Protocol\Payload;

use TSF\Protocol\Contract\PayloadCodec;

/**
 * 原样透传（默认）。
 *
 * 业务直接收发字节串，框架不解释内容——等价于框架早期的行为，
 * 适合自己定义二进制协议、或压测只想测传输的场景。
 * pack/unpack 只是一次类型判断，开销可忽略。
 */
final class RawPayload implements PayloadCodec
{
    public function name(): string
    {
        return "raw";
    }

    public function pack(int $msgId, string|array|object $data): string
    {
        if (!is_string($data)) {
            // 不静默返回 ""：那样等于把消息丢了还不报错
            throw new \RuntimeException(
                "raw payload 只接受 string，收到 " . gettype($data) . "（msgId=" . $msgId . "）"
            );
        }
        return $data;
    }

    public function unpack(int $msgId, string $body): string|array|object
    {
        return $body;
    }
}
