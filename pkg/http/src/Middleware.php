<?php

namespace TSF\Http;

/**
 * 前置中间件。
 *
 * ## 为什么是接口而不是闭包
 *
 * AOT 对参数类型要求严格：`use(\Closure $mw)` 传进来一个有 __invoke 的对象，
 * 编译期直接报「Argument mw must be an instance of Closure」。闭包也不行 ——
 * 闭包在 AOT 下的捕获语义和普通函数不同，跨边界传容易出问题。
 *
 * 接口 + 明确类型是最稳的写法，而且中间件能单独实例化和测试。
 */
interface Middleware
{
    /**
     * 返回 null 表示继续；返回 Response 表示短路（后面的中间件和路由都不跑）。
     */
    public function handle(Request $req): ?Response;
}
