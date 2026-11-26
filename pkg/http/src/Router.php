<?php

namespace TSF\Http;

/**
 * 路由表。
 *
 * ## 只支持两种 handler
 *
 * 1. **闭包** `function (Request $req): Response`
 * 2. **类名** `MyHandler::class`（推荐）—— 类要有 `handle(Request): Response` 方法
 *
 * 类名优先：AOT 对"编译期能确定的调用"最友好，而且 handler 能单独测。
 * 闭包适合两三个接口的临时项目。
 *
 * ## 路径参数
 *
 * `/player/:uid/items/:slot` —— 以 `:` 开头的段是参数，按段数严格匹配。
 * 不做正则路由（不需要，而且正则回溯在热路径上是白给的开销）。
 */
final class Router
{
    /**
     * @var array<string, list<array{pattern: list<string>, handler: \Closure|string}>>
     */
    private array $routes = [];

    public function get(string $path, \Closure|string $handler): void
    {
        $this->add("GET", $path, $handler);
    }

    public function post(string $path, \Closure|string $handler): void
    {
        $this->add("POST", $path, $handler);
    }

    public function put(string $path, \Closure|string $handler): void
    {
        $this->add("PUT", $path, $handler);
    }

    public function delete(string $path, \Closure|string $handler): void
    {
        $this->add("DELETE", $path, $handler);
    }

    private function add(string $method, string $path, \Closure|string $handler): void
    {
        $trimmed = trim($path, "/");
        $pattern = $trimmed === "" ? [] : explode("/", $trimmed);
        $this->routes[$method][] = ["pattern" => $pattern, "handler" => $handler];
    }

    /**
     * 分发。找不到路由返回 404，handler 抛异常由调用方兜。
     */
    public function dispatch(Request $req): Response
    {
        $found = $this->match($req->method, $req->path);
        if ($found === null) {
            // 路径在、方法不对 → 405 + Allow。此前一律 404：
            // 客户端把"我用错了方法"和"接口不存在"混在一起，排查时得多猜一轮
            $allow = $this->methodsFor($req->path);
            if ($allow !== "") {
                return Response::fail(405, "method not allowed", 405)->withHeader("Allow", $allow);
            }
            return Response::fail(404, "not found", 404);
        }

        $req->params = $found[0];
        $handler = $found[1];

        if ($handler instanceof \Closure) {
            return ($handler)($req);
        }

        // 类名：实例化后调 handle()。不缓存实例 —— 无状态 handler 每次 new
        // 的开销可以忽略（AOT 下就是一次对象分配），缓存反而要处理构造参数。
        $obj = new $handler();
        if (!method_exists($obj, "handle")) {
            return Response::fail(500, "handler 缺少 handle 方法: " . $handler, 500);
        }
        return $obj->handle($req);
    }

    /**
     * @return array{0: array<string, string>, 1: \Closure|string}|null
     */
    /**
     * 这个路径上注册过哪些方法（逗号分隔，按注册顺序）。空串 = 路径不存在。
     *
     * 给 405 的 `Allow` 头用。只有路由没命中的时候才会走到这里，不是热路径。
     */
    private function methodsFor(string $path): string
    {
        $out = "";
        foreach ($this->routes as $method => $list) {
            if ($this->match((string) $method, $path) === null) {
                continue;
            }
            if ($out !== "") {
                $out .= ", ";
            }
            $out .= $method;
        }
        return $out;
    }

    private function match(string $method, string $path): ?array
    {
        $list = $this->routes[$method] ?? [];
        $trimmed = trim($path, "/");
        $segs = $trimmed === "" ? [] : explode("/", $trimmed);

        foreach ($list as $route) {
            $pattern = $route["pattern"];
            if (count($pattern) !== count($segs)) {
                continue;
            }
            $params = [];
            $hit = true;
            foreach ($pattern as $i => $seg) {
                if ($seg !== "" && $seg[0] === ":") {
                    $params[substr($seg, 1)] = $segs[$i];
                    continue;
                }
                if ($seg !== $segs[$i]) {
                    $hit = false;
                    break;
                }
            }
            if ($hit) {
                return [$params, $route["handler"]];
            }
        }
        return null;
    }
}
