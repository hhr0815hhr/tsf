<?php

namespace TSF\Protocol\Payload;

use TSF\Protocol\Contract\PayloadCodec;

/**
 * MessagePack 编解码（依赖 msgpack 扩展）。
 *
 * 当前环境（php85 + AOT）**没有 msgpack 扩展**，构造时会直接报错并提示安装方式，
 * 而不是等到第一次解析才莫名其妙失败。
 *
 * 若目标环境装了扩展，AOT 编译时也要把该扩展链进来（tpc 用的是同一份 php-config），
 * 光在解释器里装是不够的。
 */
final class MsgpackPayload implements PayloadCodec
{
    public function __construct()
    {
        if (!function_exists("msgpack_pack")) {
            throw new \RuntimeException(
                "msgpack 扩展未安装。安装方式：pecl install msgpack，" .
                "并确保 AOT 编译所用的 PHP 也带该扩展；否则请改用 json 或自定义二进制。"
            );
        }
    }

    public function name(): string
    {
        return "msgpack";
    }

    public function pack(int $msgId, string|array|object $data): string
    {
        $s = msgpack_pack($data);
        if (!is_string($s)) {
            throw new \RuntimeException("msgpack: encode failed, msgId=" . $msgId);
        }
        return $s;
    }

    public function unpack(int $msgId, string $body): string|array|object
    {
        $v = msgpack_unpack($body);
        if (is_string($v) || is_array($v) || is_object($v)) {
            return $v;
        }
        throw new \RuntimeException(
            "msgpack: 顶层必须是 string/array/object，实际是 " . gettype($v) . "（msgId=" . $msgId . "）"
        );
    }
}
