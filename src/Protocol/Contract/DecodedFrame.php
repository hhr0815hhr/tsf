<?php

namespace TSF\Protocol\Contract;

/**
 * 一帧解码出来的结果。
 *
 * 为什么用对象而不是 `array{int,int,string,int}`：
 *   - 数组元素是混合类型，取出来还得靠下标 + 转换，容易写错顺序（$f[2] 是 body 还是 flags？）；
 *   - 实测对象属性读取比 4 元素数组构造+下标访问快约 150 ns/帧（AOT，见 bench/probe-types）；
 *   - 属性都有类型声明，拼错名字编译期就报错。
 *
 * 只由 FrameCodec 实现产出，业务侧不应该直接 new 它。
 */
final class DecodedFrame
{
    public int $msgId = 0;

    public int $flags = 0;

    public string $body = "";

    /** 本帧在缓冲区里占用的字节数（含头），调用方据此滑动缓冲 */
    public int $consumed = 0;
}
