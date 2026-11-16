<?php

namespace TSF\Protocol;

use TSF\Kernel\Logger;
use TSF\Protocol\Contract\FrameCodec;
use TSF\Protocol\Contract\PayloadCodec;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Frame\NativeHeaderFrame;
use TSF\Protocol\Payload\JsonPayload;
use TSF\Protocol\Payload\MsgpackPayload;
use TSF\Protocol\Payload\ProtobufPayload;
use TSF\Protocol\Payload\RawPayload;
use TSF\Protocol\Payload\RegistryPayload;

/**
 * 协议栈：分帧 + 消息体两层组合。
 *
 * 业务只面对本类，不关心底下是 8 字节头还是 varint、是 JSON 还是 protobuf。
 * 配置一行就能换；只换 payload 时分帧层不变，老客户端不受影响。
 *
 *   $p = ProtocolStack::fromConfig("native", "json");
 *   $p->encode(Msg::LOGIN, ["uid" => 1001]);
 *   $frame = $p->frame();   // 交给 Transport\Server
 *
 * 注意分工：
 *   - 分帧在传输层做（必须在收包回调里，决定"这一坨字节够不够一帧"）；
 *   - 消息体解码在 Gateway 做（那里才知道 msgId 对应的业务类型，
 *     且登录前的系统消息不需要解析业务体）。
 * 所以这里对外只暴露 frame()/payload() 两个部件，不提供"一体化 codec"。
 */
final class ProtocolStack
{
    private FrameCodec $frame;

    private PayloadCodec $payload;

    public function __construct(FrameCodec $frame, PayloadCodec $payload)
    {
        $this->frame = $frame;
        $this->payload = $payload;
    }

    public function frame(): FrameCodec
    {
        return $this->frame;
    }

    public function payload(): PayloadCodec
    {
        return $this->payload;
    }

    public function frameName(): string
    {
        return $this->frame->name();
    }

    public function payloadName(): string
    {
        return $this->payload->name();
    }

    /** 结构 → 完整帧（含分帧头）。客户端/离线工具用。 */
    public function encode(int $msgId, string|array|object $data, int $flags = 0): string
    {
        return $this->frame->encode($msgId, $this->payload->pack($msgId, $data), $flags);
    }

    /** 帧 → DecodedMessage；数据不足返回 null。 */
    public function decode(string $buf): ?DecodedMessage
    {
        $f = $this->frame->decode($buf);
        if ($f === null) {
            return null;
        }
        $m = new DecodedMessage();
        $m->msgId = $f->msgId;
        $m->flags = $f->flags;
        $m->data = $this->payload->unpack($f->msgId, $f->body);
        $m->consumed = $f->consumed;
        return $m;
    }

    /** 按名字装配。名字来自配置（`protocol.frame` / `protocol.payload`）。 */
    public static function fromConfig(string $frameName, string $payloadName): ProtocolStack
    {
        $frame = self::makeFrame($frameName);
        $payload = self::makePayload($payloadName);
        Logger::info("protocol", ["frame" => $frame->name(), "payload" => $payload->name()]);
        return new ProtocolStack($frame, $payload);
    }

    private static function makeFrame(string $name): FrameCodec
    {
        if ($name === "native") {
            if (!function_exists("c_encode")) {
                throw new \RuntimeException(
                    "protocol.frame=native 需要 ext/codec 参与编译；请确认 project.yml 里包含 tsf/ext/codec"
                );
            }
            return new NativeHeaderFrame();
        }
        if ($name === "header" || $name === "php" || $name === "") {
            return new HeaderFrame();
        }
        throw new \RuntimeException("protocol.frame 未知取值: " . $name);
    }

    private static function makePayload(string $name): PayloadCodec
    {
        if ($name === "raw" || $name === "") {
            return new RawPayload();
        }
        if ($name === "json") {
            return new JsonPayload();
        }
        if ($name === "msgpack") {
            return new MsgpackPayload();
        }
        if ($name === "protobuf") {
            return new ProtobufPayload();
        }
        if ($name === "registry") {
            return new RegistryPayload();
        }
        throw new \RuntimeException("protocol.payload 未知取值: " . $name);
    }
}
