<?php

namespace TSF\Protocol;

/**
 * 一帧完整解码结果：分帧信息 + 已解出的业务数据。
 *
 * 只在客户端/离线工具里用（服务端是"分帧在 Transport、解包在 Gateway"两段式）。
 * 用对象而不是数组，理由同 Contract\DecodedFrame：属性有类型、不用记下标。
 */
final class DecodedMessage
{
    public int $msgId = 0;

    public int $flags = 0;

    /** 载荷只可能是这三种形态，见 Contract\PayloadCodec */
    public string|array|object $data = "";

    public int $consumed = 0;
}
