<?php

namespace TSF\Http;

use TSF\Kernel\Logger;

/**
 * HTTP 应用：把 Router 接到 Swoole 的 HttpServer 上。
 *
 * ```php
 * $app = new App("0.0.0.0", 9101);
 * $app->post("/login", LoginHandler::class);
 * $app->get("/health", fn (Request $r): Response => Response::ok(["ok" => true]));
 * $app->run();
 * ```
 *
 * 中间件留了 `use()` 的位置，但这一版只跑 Router —— 中间件（日志/限流/CORS/auth）
 * 等接口多了再加，避免过早抽象。
 */
final class App
{
    private Router $router;

    /** @var list<\TSF\Http\Middleware> 前置中间件 */
    private array $middlewares = [];

    /** @var list<\Closure> 后置装饰器：function (Response): Response */
    private array $afters = [];

    public function __construct(
        private string $host,
        private int $port,
    ) {
        $this->router = new Router();
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * 注册前置中间件。按注册顺序执行，任一返回 Response 即短路。
     *
     * 顺序很重要：CORS → 限流 → 鉴权。预检请求不带 Authorization，
     * 限流要在验签之前（否则伪造 token 会白耗 CPU）。
     *
     */
    public function use(\TSF\Http\Middleware $mw): self
    {
        $this->middlewares[] = $mw;
        return $this;
    }

    /**
     * 注册后置装饰器（拿到 Response 再加工，比如补 CORS 头）。
     *
     * 为什么需要它：CORS 不仅 OPTIONS 预检要带 Access-Control-Allow-Origin，
     * 正常响应也必须带，否则浏览器还是会拦。前置中间件管不了这个。
     *
     * @param \Closure $fn function (Response): Response
     */
    public function after(\Closure $fn): self
    {
        $this->afters[] = $fn;
        return $this;
    }

    public function get(string $path, \Closure|string $handler): self
    {
        $this->router->get($path, $handler);
        return $this;
    }

    public function post(string $path, \Closure|string $handler): self
    {
        $this->router->post($path, $handler);
        return $this;
    }

    /** 起服务并阻塞 */
    public function run(): void
    {
        $server = new \Swoole\Http\Server($this->host, $this->port);
        $router = $this->router;

        $middlewares = $this->middlewares;
        $afters = $this->afters;
        $server->on("request", function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router, $middlewares, $afters): void {
            $started = microtime(true);
            $path = (string) ($req->server["request_uri"] ?? "/");
            $method = (string) ($req->server["request_method"] ?? "GET");

            // Swoole 的 header 键本来就是小写的
            $headers = is_array($req->header) ? $req->header : [];
            $query = is_array($req->get) ? $req->get : [];
            $post = is_array($req->post) ? $req->post : [];
            $raw = (string) $req->rawContent();

            $request = new Request(
                $method,
                $path,
                $query,
                $post,
                $headers,
                $raw,
                [],
                (string) ($req->server["remote_addr"] ?? ""),
            );

            try {
                $resp = null;
                foreach ($middlewares as $mw) {
                    $short = $mw->handle($request);
                    if ($short !== null) {
                        $resp = $short;
                        break;
                    }
                }
                if ($resp === null) {
                    $resp = $router->dispatch($request);
                }
            } catch (\Throwable $e) {
                Logger::error("http handler threw", [
                    "path" => $path,
                    "err" => $e->getMessage(),
                ]);
                $resp = Response::fail(500, "internal error", 500);
            }

            // 后置装饰（CORS 头之类）
            foreach ($afters as $fn) {
                $resp = ($fn)($resp);
            }

            $res->status($resp->status);
            foreach ($resp->headers as $k => $v) {
                $res->header($k, $v);
            }
            $res->end($resp->body);

            $ms = (microtime(true) - $started) * 1000;
            Logger::debug("http", [
                "method" => $method,
                "path" => $path,
                "status" => $resp->status,
                "ms" => round($ms, 2),
            ]);
        });

        Logger::info("http ready", ["host" => $this->host, "port" => $this->port]);
        $server->start();
    }
}
