<?php

namespace TSF\Protocol\Payload;

use TSF\Protocol\Contract\PayloadCodec;

/**
 * 注册式编解码：按 msgId 挂上编解码闭包。
 *
 * 一份实现同时覆盖两类需求：
 *   1. **protobuf**：闭包调用生成类的 `mergeFromString` / `serializeToString`
 *   2. **自定义二进制**：业务自己 pack/unpack
 *
 * 为什么用闭包而不是要求消息类实现某个接口：
 *   - protobuf 生成类不归我们管，没法加接口；
 *   - 闭包让"proto3 生成的类"和"手写的类"用同一套注册方式；
 *   - AOT 下接口 + 静态方法的组合支持面窄，闭包最稳。
 *
 * 用法（protobuf）：
 *   $p = new ProtobufPayload();
 *   $p->register(Msg::LOGIN,
 *       fn ($m) => $m->serializeToString(),
 *       fn (string $b) => (new LoginReq())->mergeFromString($b));
 */
class RegistryPayload implements PayloadCodec
{
    /** @var array<int, \Closure> msgId => fn(string|array|object): string */
    private array $encoders = [];

    /** @var array<int, \Closure> msgId => fn(string): string|array|object */
    private array $decoders = [];

    private string $label;

    public function __construct(string $label = "registry")
    {
        $this->label = $label;
    }

    public function name(): string
    {
        return $this->label;
    }

    /**
     * 注册一个消息类型的编解码。
     *
     * @param \Closure $encoder fn(string|array|object $msg): string
     * @param \Closure $decoder fn(string $bytes): string|array|object
     */
    public function register(int $msgId, \Closure $encoder, \Closure $decoder): void
    {
        $this->encoders[$msgId] = $encoder;
        $this->decoders[$msgId] = $decoder;
    }

    public function has(int $msgId): bool
    {
        return isset($this->encoders[$msgId], $this->decoders[$msgId]);
    }

    public function pack(int $msgId, string|array|object $data): string
    {
        if (!isset($this->encoders[$msgId])) {
            throw new \RuntimeException(
                "payload[" . $this->label . "]: 未注册编码器 msgId=" . $msgId .
                "（需要在启动时 register()）"
            );
        }
        $fn = $this->encoders[$msgId];
        return (string) $fn($data);
    }

    public function unpack(int $msgId, string $body): string|array|object
    {
        if (!isset($this->decoders[$msgId])) {
            throw new \RuntimeException(
                "payload[" . $this->label . "]: 未注册解码器 msgId=" . $msgId
            );
        }
        $fn = $this->decoders[$msgId];
        $v = $fn($body);
        if (is_string($v) || is_array($v) || is_object($v)) {
            return $v;
        }
        throw new \RuntimeException(
            "payload[" . $this->label . "]: 解码器必须返回 string/array/object，" .
            "实际是 " . gettype($v) . "（msgId=" . $msgId . "）"
        );
    }
}
