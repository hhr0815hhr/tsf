<?php

namespace TSF\Rpc\Codec;

use TSF\Rpc\Contract\RpcCodec;
use TSF\Rpc\Request;
use TSF\Rpc\Response;

/**
 * 默认协议：JSON。
 *
 * 选它做默认不是因为快，而是因为**能抓包看**。跨进程调用出问题时，
 * `tcpdump` / 日志里能直接读出方法名和参数，比对着二进制猜位偏移快得多。
 * 真要压性能再换 `RawCodec`。
 *
 * ## 线格式
 *
 * 请求：`{"m":"方法名","b":"载荷"}`
 * 响应：`{"o":1,"b":"..."}` 成功 / `{"o":0,"e":"错误"}` 失败
 *
 * 字段名一个字母是刻意的：这条路径每次调用都走一遍，
 * `method`/`body` 写成全称在百万次调用里多出的字节不是零。
 *
 * ## 为什么响应要带 `o` 标记
 *
 * 因为"成功还是失败"是**协议语义**，不该由框架用两个 msgId 替 codec 决定。
 * 带上 `o` 之后，想换成 protobuf 的 `enum Status` 也只是换一个 codec。
 */
final class JsonCodec implements RpcCodec
{
    public function packRequest(string $method, string $body): string
    {
        $out = json_encode(
            ["m" => $method, "b" => $body],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($out)) {
            throw new \RuntimeException("rpc: 请求编码失败（" . json_last_error_msg() . "）");
        }
        return $out;
    }

    public function unpackRequest(string $raw): ?Request
    {
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return null;
        }
        $method = isset($d["m"]) ? (string) $d["m"] : "";
        if ($method === "") {
            return null;   // 没有方法名的请求无法路由，当作格式错误
        }
        return new Request($method, isset($d["b"]) ? (string) $d["b"] : "");
    }

    public function packResponse(bool $ok, string $body): string
    {
        $out = $ok
            ? json_encode(["o" => 1, "b" => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode(["o" => 0, "e" => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($out)) {
            throw new \RuntimeException("rpc: 响应编码失败（" . json_last_error_msg() . "）");
        }
        return $out;
    }

    public function unpackResponse(string $raw): ?Response
    {
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return null;
        }
        $ok = isset($d["o"]) && (int) $d["o"] === 1;
        if ($ok) {
            return new Response(true, isset($d["b"]) ? (string) $d["b"] : "");
        }
        return new Response(false, isset($d["e"]) ? (string) $d["e"] : "unknown-error");
    }

    public function name(): string
    {
        return "json";
    }
}
