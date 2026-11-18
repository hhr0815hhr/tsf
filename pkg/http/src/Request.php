<?php

namespace TSF\Http;

/**
 * 一次 HTTP 请求的只读视图。
 *
 * 只包一层，不做内容协商、不做 PSR-7 那套（AOT 下接口和 trait 用得越多，
 * 编译期问题越难定位）。字段都是明确类型，避免 mixed 遍历。
 */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $headers 键统一小写
     * @param array<string, string> $params  路径参数，如 /player/:uid 里的 uid
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $headers,
        public string $rawBody,
        public array $params = [],
        public string $remoteIp = "",
        // 由 AuthMiddleware 填入；0 表示未登录
        public int $uid = 0,
    ) {
    }

    /** 取 query 参数（带默认值） */
    public function queryOf(string $key, string $default = ""): string
    {
        return $this->query[$key] ?? $default;
    }

    /** 取表单字段（带默认值） */
    public function postOf(string $key, string $default = ""): string
    {
        return $this->post[$key] ?? $default;
    }

    /** 取路径参数（带默认值） */
    public function param(string $key, string $default = ""): string
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * 解析 JSON body。
     *
     * 注意返回 array 而不是 object：AOT 下对着 mixed 反复下标是踩过的坑
     * （docs/guide/08 第一条）。调用方用 $data["field"] ?? 默认值 访问。
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->rawBody === "") {
            return [];
        }
        $d = json_decode($this->rawBody, true);
        return is_array($d) ? $d : [];
    }

    /**
     * 从 Authorization 头取 Bearer token。
     *
     * 也接受 `token` 查询参数（浏览器直接打开调试接口时方便），
     * 但生产环境应该只用 Authorization 头 —— query 里的 token 会进访问日志。
     */
    public function bearerToken(): string
    {
        $h = $this->headers["authorization"] ?? "";
        if (strncmp($h, "Bearer ", 7) === 0) {
            return substr($h, 7);
        }
        return $this->queryOf("token");
    }
}
