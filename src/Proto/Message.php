<?php

namespace TSF\Proto;

/**
 * 所有生成出来的消息的基类。
 *
 * 存在的唯一目的是让 `Codec::decode()` 有个统一的返回类型：
 *
 * ```php
 * $m = Codec::decode($msgId, $body);   // ?Message
 * if ($m instanceof LoginReq) { ... }
 * ```
 *
 * 不用接口而用抽象类：AOT 下"接口 + 静态方法"的组合支持面窄（`RegistryPayload`
 * 的注释里记过这条），抽象类这条路是 `Service` 已经验证过的。
 */
abstract class Message
{
    /** 协议号（来自 .proto 里 `// @id 1001` 指令） */
    abstract public function msgId(): int;

    /** 编成 wire format 字节 */
    abstract public function encode(): string;
}
