<?php

namespace TSF\Http;

use TSF\Metrics\Prometheus;

/**
 * 一行接上 `/metrics`：`MetricsRoute::register($app);`
 *
 * 放在 http 包里而不是 `src/Metrics`，是因为依赖方向：核心不能反向依赖可选包。
 * `Prometheus::render()` 只吐字符串，谁把它塞进 HTTP 响应是调用方的事——
 * 这样接 gin 风格的自研框架、接 Swoole 原生回调、或者写进文件，都只是换个壳。
 *
 * ## 建议：这个路由挂在内网端口
 *
 * `/metrics` 会暴露进程内存和业务指标（房间数、在线人数、结算金额）。
 * 挂到公网端口上等于把这些数字送给任何人。示例里给它单独的路径前缀，
 * 真实部署应该分端口或用中间件限制来源 IP。
 */
final class MetricsRoute
{
    public static function register(App $app, string $path = "/metrics", bool $withProcess = true): void
    {
        $app->get($path, function (Request $req) use ($withProcess): Response {
            return new Response(
                200,
                ["content-type" => Prometheus::CONTENT_TYPE],
                Prometheus::render($withProcess)
            );
        });
    }
}
