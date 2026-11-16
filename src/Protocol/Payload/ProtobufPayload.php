<?php

namespace TSF\Protocol\Payload;

/**
 * protobuf 专用名字（与 RegistryPayload 行为一致，只是名字更明确）。
 *
 * 存在的意义是可读性：配置文件里写 `payload: protobuf` 比写 registry 更直白，
 * 日志里也能一眼看出用的是哪种协议。
 */
final class ProtobufPayload extends RegistryPayload
{
    public function __construct()
    {
        parent::__construct("protobuf");
    }
}
