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
 * ## 来源限制是代码强制的，不是文档建议
 *
 * `/metrics` 会暴露进程内存和业务指标（房间数、在线人数、结算金额）。
 * 光写"挂内网"没用——部署时没人回头读文档，而默认暴露的代价是一次疏忽
 * 就把运营数据公开。所以默认只放行**回环 + 私有网段**，放开要显式写 `["*"]`。
 */
final class MetricsRoute
{
    /**
     * @param array<int, string> $allow 允许的来源（精确 IP / IPv4 CIDR / `*`）。
     *                                  默认 `IpFilter::PRIVATE_ONLY`
     */
    public static function register(
        App $app,
        string $path = "/metrics",
        bool $withProcess = true,
        array $allow = IpFilter::PRIVATE_ONLY
    ): void {
        $app->get($path, function (Request $req) use ($withProcess, $allow): Response {
            if (!IpFilter::allows($allow, $req->remoteIp)) {
                // 403 而不是 404：让运维一眼看出"是被拒了"，不至于怀疑路由没注册
                return new Response(403, ["content-type" => "text/plain; charset=utf-8"], "forbidden\n");
            }
            return new Response(
                200,
                ["content-type" => Prometheus::CONTENT_TYPE],
                Prometheus::render($withProcess)
            );
        });
    }
}
