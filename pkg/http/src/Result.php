<?php

namespace TSF\Http;

/**
 * 出站请求的结果。
 *
 * 和服务端的 `Response` 分开是两个原因：
 *   1. 字段不同——这里要 `error` 和 `elapsedMs`，服务端响应不需要
 *   2. 语义不同——服务端的 `Response` 是"我要发出去的东西"，这个是"我收到的东西"
 *
 * `status = 0` 表示**没拿到响应**（连接失败、超时、DNS 解析失败），此时看 `error`。
 */
final class Result
{
    public function __construct(
        public int $status,
        public string $body,
        public string $error,
        public int $elapsedMs,
    ) {
    }

    /** 2xx 且没有传输层错误 */
    public function ok(): bool
    {
        return $this->error === "" && $this->status >= 200 && $this->status < 300;
    }

    /** 有响应但状态码是 4xx/5xx（此时 `error` 为空，`status` 有值） */
    public function failed(): bool
    {
        return $this->error === "" && $this->status >= 400;
    }

    public function describe(): string
    {
        if ($this->error !== "") {
            return "传输失败: " . $this->error . "（" . (string) $this->elapsedMs . " ms）";
        }
        return "HTTP " . (string) $this->status . "（" . (string) $this->elapsedMs . " ms）";
    }
}
