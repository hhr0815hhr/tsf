<?php

namespace TSF\Protocol;

/**
 * 系统协议号。
 *
 * 1–99 留给框架（登录、心跳、错误、踢下线），1000 以上给业务。
 * 后续 `tsf proto` 会从 .proto 生成，这里是当前的手写基线。
 */
final class Msg
{
    // 登录
    public const LOGIN = 1;
    public const LOGIN_OK = 2;
    public const LOGIN_FAIL = 3;
    public const KICKED = 4;

    // 心跳
    public const PING = 100;
    public const PONG = 101;

    // 通用错误
    public const ERR = 400;
    public const TOO_FAST = 429;

    // 业务示例
    public const ECHO_REQ = 1001;
    public const ECHO_RESP = 1002;
}
