<?php

namespace TSF\Http\Middleware;

use TSF\Gateway\TokenAuth;
use TSF\Http\Middleware;
use TSF\Http\Request;
use TSF\Http\Response;

/**
 * 校验 Bearer token，把 uid 写进 Request。
 *
 * ```php
 * $app->use(new AuthMiddleware($auth, ["/login", "/health"]));
 * ```
 *
 * ## 为什么用白名单而不是给每个路由挂中间件
 *
 * 「默认要登录，例外显式列出」比「默认不登录，需要的地方挂上」安全：
 * 后者新增接口时忘了挂，就是一个无鉴权接口。
 *
 * ## 校验失败返回 401
 *
 * 业务码用 40100（和 HTTP 401 呼应），客户端看到 401 应该跳登录页，
 * 而不是弹"操作失败"。
 */
final class AuthMiddleware implements Middleware
{
    /**
     * @param TokenAuth $auth
     * @param list<string> $allow 不需要登录的路径（精确匹配）
     */
    public function __construct(
        private TokenAuth $auth,
        private array $allow = [],
    ) {
    }

    public function handle(Request $req): ?Response
    {
        if (in_array($req->path, $this->allow, true)) {
            return null;
        }

        $token = $req->bearerToken();
        if ($token === "") {
            return Response::fail(40100, "missing token", 401);
        }

        $uid = $this->auth->resolve($token);
        if ($uid <= 0) {
            return Response::fail(40101, "invalid or expired token", 401);
        }

        $req->uid = $uid;
        return null;
    }
}
