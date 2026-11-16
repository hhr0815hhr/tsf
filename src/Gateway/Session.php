<?php

namespace TSF\Gateway;

/**
 * 一条连接对应的会话。
 *
 * 只放"连接维度"的状态；玩家数据不在这里（那属于业务层，会进快照）。
 * 这样会话层的职责单一，也方便将来把 fd 换成别的传输标识。
 */
final class Session
{
    public int $fd = 0;

    public int $uid = 0;

    public string $token = "";

    public int $openAt = 0;

    public int $lastActive = 0;

    public bool $authed = false;

    /** 本连接累计收到的帧数，用于排障与限流统计 */
    public int $frames = 0;

    /** 本连接被限流丢弃的帧数 */
    public int $dropped = 0;
}
