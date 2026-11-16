<?php

namespace TSF\Rpc;

/**
 * 一次远程调用：方法名 + 载荷。
 *
 * 用类而不是 `array`：AOT 下数组元素是 `Variant`，取值要转型；
 * 而这里字段的类型是确定的（两个 string），用类能让编译器帮忙查。
 * 对象分配约 46 ns，相对于一次几百微秒的跨进程往返可以忽略。
 */
final class Request
{
    public string $method;

    public string $body;

    public function __construct(string $method, string $body = "")
    {
        $this->method = $method;
        $this->body = $body;
    }
}
