<?php

namespace TSF\Rpc\Contract;

use TSF\Rpc\Request;
use TSF\Rpc\Response;

/**
 * RPC 载荷编解码。换协议只实现这一个接口，`Client` / `Router` 一行不用改。
 *
 * ## 分层的边界
 *
 * | 层 | 谁决定 | 内容 |
 * |:--|:--|:--|
 * | 消息头 | 框架 | `msgId`（请求/响应）、`session`（配对 id） |
 * | **载荷** | **codec** | 方法名、参数、成功/失败、错误内容 |
 *
 * 把"成功还是失败"也交给 codec，而不是用两个 msgId 区分——否则错误语义
 * 就被钉死成"data 是一段错误字符串"。想用 protobuf 的错误码枚举、
 * 或者想在响应里带重试建议，都做不到。
 *
 * ## 两个实现就够说明问题
 *
 * - `JsonCodec`：默认，可读、能抓包看
 * - `RawCodec`：`method \n body` + 首字节 ok 标记，不解析 body
 *
 * Raw 的价值在于"body 已经被业务自己序列化好了"——比如业务用 protobuf 编了
 * 一段二进制，RPC 层只需要贴个方法名转发过去，不该再套一层 JSON 转义。
 *
 * ## 失败必须能被表达
 *
 * `unpackRequest` / `unpackResponse` 返回 null 表示"这段字节按本协议解不出来"。
 * 调用方（Router）会回一个格式错误响应——**不能静默丢弃**，否则调用方只能等超时，
 * 那是最难排查的故障。
 */
interface RpcCodec
{
    /** 请求 → 字节串 */
    public function packRequest(string $method, string $body): string;

    /** 字节串 → 请求；解不出来返回 null */
    public function unpackRequest(string $raw): ?Request;

    /** 响应 → 字节串。`$ok=false` 时 `$body` 是错误描述 */
    public function packResponse(bool $ok, string $body): string;

    /** 字节串 → 响应；解不出来返回 null */
    public function unpackResponse(string $raw): ?Response;

    /** 协议名（日志/排障用） */
    public function name(): string;
}
