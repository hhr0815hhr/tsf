<?php

namespace TSF\Http\Middleware;

use TSF\Http\Middleware;
use TSF\Http\Request;
use TSF\Http\Response;

/**
 * CORS。H5 / 小游戏 / 小程序直连 HTTP 接口时需要。
 *
 * 处理 OPTIONS 预检：**直接返回 204**，不进路由。浏览器发预检时不会带
 * Authorization 头，所以这个中间件必须排在 AuthMiddleware 之前，
 * 否则预检会被 401 挡掉，前端看到的是一个和 CORS 无关的报错。
 */
final class CorsMiddleware implements Middleware
{
    /**
     * @param string $allowOrigin "*" 或具体域名。带 cookie 的请求不能是 "*"。
     */
    public function __construct(
        private string $allowOrigin = "*",
        private string $allowHeaders = "Content-Type, Authorization",
        private string $allowMethods = "GET, POST, PUT, DELETE, OPTIONS",
        private int $maxAge = 86400,
    ) {
    }

    public function handle(Request $req): ?Response
    {
        if ($req->method === "OPTIONS") {
            return (new Response(204, $this->headers()))
                ->withHeader("access-control-max-age", (string) $this->maxAge);
        }
        return null;
    }

    /** 给正常响应补 CORS 头（App 会在 dispatch 后统一调用） */
    public function decorate(Response $resp): Response
    {
        foreach ($this->headers() as $k => $v) {
            $resp->withHeader($k, $v);
        }
        return $resp;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            "access-control-allow-origin" => $this->allowOrigin,
            "access-control-allow-headers" => $this->allowHeaders,
            "access-control-allow-methods" => $this->allowMethods,
        ];
    }
}
