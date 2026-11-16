<?php

namespace TSF\Protocol\Contract;

/**
 * 分帧层：把 TCP 字节流切成一条条消息。
 *
 * 这一层只关心"边界"，不关心里面装的是什么——与消息体编解码（PayloadCodec）正交。
 * 换协议（protobuf/json）不需要动这一层；换分帧方式（定长头/varint）也不需要动业务。
 *
 * 约定：
 *   - decode 不修改入参；数据不足返回 null；帧头非法抛 RuntimeException（服务器据此断开连接）；
 *   - 返回 DecodedFrame 对象而不是数组，理由见该类的注释；
 *   - 不用引用参数回传 consumed——AOT 下闭包/动态调用的引用参数需要显式 toRef()，
 *     漏写会静默不写回（踩过，延迟从 0.2ms 飙到 111ms）。
 */
interface FrameCodec
{
    public function encode(int $msgId, string $body, int $flags): string;

    /** 数据不足返回 null */
    public function decode(string $buf): ?DecodedFrame;

    public function name(): string;
}
