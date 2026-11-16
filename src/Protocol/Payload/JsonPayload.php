<?php

namespace TSF\Protocol\Payload;

use TSF\Protocol\Contract\PayloadCodec;

/**
 * JSON 编解码。
 *
 * 优点：浏览器/调试工具直接可读，无需生成代码。
 * 缺点：体积大（约为二进制的 2~4 倍）、编解码慢（每次都要解析文本）。
 *
 * 适合：后台/GM 接口、配置下发、调试协议。**不建议用于高频战斗同步**——
 * 实测每帧编解码成本见 bench/proto，热路径应换 protobuf 或自定义二进制。
 *
 * 约定：顶层必须是 object 或 array。标量（`123`、`true`、`null`）算协议误用，
 * 直接报错而不是悄悄放行——否则业务拿到的类型会飘。
 */
final class JsonPayload implements PayloadCodec
{
    private int $flags;

    public function __construct(bool $pretty = false)
    {
        $this->flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $this->flags |= JSON_PRETTY_PRINT;
        }
    }

    public function name(): string
    {
        return "json";
    }

    public function pack(int $msgId, string|array|object $data): string
    {
        $s = json_encode($data, $this->flags);
        if ($s === false) {
            throw new \RuntimeException("json: encode failed, msgId=" . $msgId);
        }
        return $s;
    }

    public function unpack(int $msgId, string $body): string|array|object
    {
        $v = json_decode($body, true);
        if (is_array($v)) {
            return $v;
        }
        if ($v === null && $body !== "null") {
            throw new \RuntimeException("json: decode failed, msgId=" . $msgId);
        }
        throw new \RuntimeException(
            "json: 顶层必须是 object 或 array，实际是 " . gettype($v) . "（msgId=" . $msgId . "）"
        );
    }
}
