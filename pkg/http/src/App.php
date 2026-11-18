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

        $server->on("request", function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router): void {
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
                $resp = $router->dispatch($request);
            } catch (\Throwable $e) {
                Logger::error("http handler threw", [
                    "path" => $path,
                    "err" => $e->getMessage(),
                ]);
                $resp = Response::fail(500, "internal error", 500);
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
