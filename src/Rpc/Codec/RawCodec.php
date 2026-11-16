<?php

namespace TSF\Rpc\Codec;

use TSF\Rpc\Contract\RpcCodec;
use TSF\Rpc\Request;
use TSF\Rpc\Response;

/**
 * 极简二进制协议：**不解析 body，只贴方法名和 ok 标记**。
 *
 * ## 什么时候该用它
 *
 * 当 `body` 已经被业务自己序列化好了——比如用 protobuf / msgpack 编了一段二进制，
 * RPC 层只需要把"调哪个方法"和"这段字节"送到对端。用 JSON 的话，
 * 那段二进制会被 `json_encode` 转义成 base64 之类，体积和 CPU 都白花。
 *
 * ## 线格式
 *
 * 请求：`方法名 + "\n" + body`
 * 响应：`chr(1|0) + body`（首字节 1 = 成功，0 = 失败）
 *
 * 定界用换行而不是长度前缀：方法名不可能含换行（由 `Router::on()` 的名字决定），
 * 而 body 里有没有换行都无所谓——切分只找**第一个**换行。
 *
 * ## 代价
 *
 * 方法名里一旦混进换行，整条协议就错位了。所以 `packRequest` 会检查并抛异常，
 * 而不是默默编出一个对端解不对的包。这类"编出来就是坏的"数据，
 * 早失败比晚失败好——晚失败表现为"对端说没这个方法"，排查成本高一个数量级。
 */
final class RawCodec implements RpcCodec
{
    public function packRequest(string $method, string $body): string
    {
        if (str_contains($method, "\n")) {
            throw new \RuntimeException("rpc: 方法名不能包含换行（" . $method . "）");
        }
        return $method . "\n" . $body;
    }

    public function unpackRequest(string $raw): ?Request
    {
        $pos = strpos($raw, "\n");
        if ($pos === false) {
            return null;   // 没有定界符：格式错误
        }
        $method = substr($raw, 0, $pos);
        if ($method === "") {
            return null;
        }
        return new Request($method, substr($raw, $pos + 1));
    }

    public function packResponse(bool $ok, string $body): string
    {
        return ($ok ? "\x01" : "\x00") . $body;
    }

    public function unpackResponse(string $raw): ?Response
    {
        if ($raw === "") {
            return null;
        }
        $flag = $raw[0];
        if ($flag !== "\x01" && $flag !== "\x00") {
            return null;
        }
        return new Response($flag === "\x01", substr($raw, 1));
    }

    public function name(): string
    {
        return "raw";
    }
}
