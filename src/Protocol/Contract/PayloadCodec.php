<?php

namespace TSF\Protocol\Contract;

/**
 * 消息体层：把帧里的 body（字节串）转成业务能用的结构，反之亦然。
 *
 * 与分帧层正交：换 JSON/protobuf 只影响这一层，分帧格式不变，老客户端仍能连。
 *
 * 默认实现是 RawPayload（原样透传），此时 pack/unpack 几乎零成本——
 * 不想用结构化消息的项目不会为此付钱。
 *
 * **载荷类型用 `string|array|object` 而不是 `mixed`**：
 *   - 消息体只有这三种形态（二进制串 / 结构化数组 / 消息对象），int/float/bool
 *     作为顶层载荷没有意义；
 *   - 联合类型把约定写进了签名，调用方不用猜，编译器也能查；
 *   - 本框架的编码标准是"源码里不出现 mixed 类型声明"（见 README §5.8）。
 */
interface PayloadCodec
{
    /** 结构 → 字节串 */
    public function pack(int $msgId, string|array|object $data): string;

    /** 字节串 → 结构 */
    public function unpack(int $msgId, string $body): string|array|object;

    public function name(): string;
}
