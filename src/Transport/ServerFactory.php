<?php

namespace TSF\Transport;

use TSF\Protocol\Contract\FrameCodec;
use TSF\Transport\Tcp\TcpServer;
use TSF\Transport\Ws\WsServer;

/**
 * 传输层工厂。
 *
 * 为什么需要它：AOT 下局部变量的类型由首次赋值推断，
 * 直接在业务入口写 if/else 给同一个变量赋 TcpServer / WsServer 会报
 * "Cannot re-assign typed object"。工厂方法带统一返回类型 `Server`，
 * 调用方拿到的就是基类，类型稳定。
 */
final class ServerFactory
{
    /** @param array<string, string|int|float|bool|array> $options */
    public static function make(
        string $transport,
        string $host,
        int $port,
        FrameCodec $frame,
        array $options = []
    ): Server {
        if ($transport === "ws" || $transport === "websocket") {
            return new WsServer($host, $port, $frame, $options);
        }
        return new TcpServer($host, $port, $frame, $options);
    }
}
