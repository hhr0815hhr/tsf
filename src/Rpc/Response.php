<?php

namespace TSF\Rpc;

/**
 * 一次远程调用的结果：成功与否 + 内容。
 *
 * `$body` 的含义由 `$ok` 决定：成功时是返回体，失败时是错误描述
 * （`timeout` / `no-method:x` / `handler-error:...` / 远端自定义的错误串）。
 *
 * 没有单独的 `code` 字段：错误码是业务语义，不同服务的码表不一样。
 * 想带结构化错误就把 JSON 放进 `$body`，别让框架替所有人定义错误码。
 */
final class Response
{
    public bool $ok;

    public string $body;

    public function __construct(bool $ok, string $body = "")
    {
        $this->ok = $ok;
        $this->body = $body;
    }

    public static function success(string $body = ""): Response
    {
        return new Response(true, $body);
    }

    public static function failure(string $message): Response
    {
        return new Response(false, $message);
    }
}
