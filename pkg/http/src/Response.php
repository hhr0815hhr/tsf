<?php

namespace TSF\Http;

/**
 * 统一响应。
 *
 * ## 业务码和 HTTP 状态码分开
 *
 * `code` 是业务码（0 = 成功），`status` 是 HTTP 状态码。很多团队只用其中一个，
 * 结果要么"所有错误都返回 200"（网关和监控看不出异常），要么"用 HTTP 状态码
 * 表达业务错误"（400/404 的语义被滥用）。
 *
 * 现在约定：
 *   - **HTTP 状态码表达传输层结果**（200 成功、401 未登录、429 限流、500 内部错误）
 *   - **code 表达业务结果**（0 成功、非 0 是业务错误码，客户端按这个走分支）
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = "",
    ) {
    }

    /**
     * 业务成功的 JSON 响应。
     *
     * @param mixed $data 会被 json_encode，建议传 array
     */
    public static function ok(mixed $data = null): self
    {
        return self::json(0, "", $data, 200);
    }

    /**
     * 业务失败的 JSON 响应。
     *
     * @param int $status HTTP 状态码（401 / 403 / 404 / 429 / 500）
     */
    public static function fail(int $code, string $msg, int $status = 200): self
    {
        return self::json($code, $msg, null, $status);
    }

    public static function json(int $code, string $msg, mixed $data, int $status): self
    {
        $payload = ["code" => $code, "msg" => $msg];
        if ($data !== null) {
            $payload["data"] = $data;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($status, ["content-type" => "application/json; charset=utf-8"], is_string($body) ? $body : "{}");
    }

    public static function text(string $s, int $status = 200): self
    {
        return new self($status, ["content-type" => "text/plain; charset=utf-8"], $s);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }
}
