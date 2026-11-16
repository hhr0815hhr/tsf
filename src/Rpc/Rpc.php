<?php

namespace TSF\Rpc;

/**
 * RPC 的协议号与约定。
 *
 * ## 为什么不用新的传输层
 *
 * `Cluster` 已经把"跨进程投递一条消息"做到了 p99 285 µs（UDS + 攒批），
 * 而 `Message` 本来就带 `session` 字段（"请求 id，回应时原样带回"）。
 * 也就是说**请求-响应的管道一直在，只是没人把它接起来**。
 *
 * 这一层要补的只有三件事：
 *
 * 1. 分配请求 id 并把回调挂上去（`Client::call`）
 * 2. 收到响应时按 session 找回回调（`Client::onMessage`）
 * 3. 超时后把回调以失败结束，别让调用方永远等（`Client::sweep`）
 *
 * ## 方法名走 string，不走 int
 *
 * 游戏服的消息通常用 int 协议号（省 4 字节、查表快），但 RPC 是**跨服务调用**，
 * 调用方和服务端往往不是同一个团队写的。string 方法名让"这个服务能调什么"
 * 一目了然，也让日志可读。性能差异在这里可以忽略——RPC 本来就不是每秒百万次的路径。
 */
final class Rpc
{
    /** 请求。载荷结构由 `Contract\RpcCodec` 决定 */
    public const MSG_REQ = 60001;

    /**
     * 响应。
     *
     * **只有一个响应协议号**：成功还是失败由 codec 编在载荷里，不由框架用
     * 两个协议号替它决定。否则错误语义就被钉死成"data 是一段错误字符串"，
     * 想换成 protobuf 的 `enum Status`、想在响应里带重试建议，都做不到。
     */
    public const MSG_RESP = 60002;

    /**
     * 调用方自己产生的错误码（不是从对端传回来的）。
     *
     * 这几个字符串**出现在 `$err` 里**而不是 `$resp` 里，所以调用方只看形状
     * 分不出"对端超时"和"对端说参数错了"——两者都是非空字符串。
     * 熔断器必须分清这两类（见 `blamesPeer`），所以这里把码定死。
     */
    public const ERR_TIMEOUT = "timeout";

    public const ERR_BAD_ADDR = "bad-addr";

    public const ERR_TOO_MANY_PENDING = "too-many-pending";

    public const ERR_ENCODE = "encode-failed";

    public const ERR_BAD_RESPONSE = "bad-response";

    /**
     * 这次失败该不该算在**对端**头上（熔断用）。
     *
     * ## 为什么必须分类
     *
     * 业务错误和传输错误在 `$err` 里长得一样。如果一律算失败：
     *
     * ```php
     * // 玩家查一个不存在的角色，服务端正常返回 "no such role"
     * $router->call($uid, "getProfile", ...);   // err = "no such role"
     * ```
     *
     * 那么"查不存在的角色"这个**完全正常的业务**会在连续 3 次之后把整台服熔断。
     * 这是熔断器最容易踩的坑，而且现象很迷惑：平时没事，一到某个活动批量查空数据就全站挂掉。
     *
     * 反过来说：**对端能返回业务错误，恰恰证明它活着**——它读到了请求、
     * 跑完了逻辑、还把结论编回来了。这比一次成功更能说明链路是通的。
     *
     * ## 三个本地错误也不该算
     *
     * | 错误 | 谁的问题 |
     * |:--|:--|
     * | `too-many-pending` | **我们自己的**待发表满了 |
     * | `encode-failed` | 我们的 codec 编不出来 |
     * | `bad-addr` | 我们的地址算错了 |
     *
     * 把这三个算进去，会在**自己过载时**把健康的对端全部熔断——
     * 那正好是最需要它们的时候。
     */
    public static function blamesPeer(string $err): bool
    {
        // 只有"发了但对面没答"和"答了但解不出来"才是对端的问题
        return $err === self::ERR_TIMEOUT || $err === self::ERR_BAD_RESPONSE;
    }

    /** 是不是调用方自己产生的错误码（用来在日志里区分"本地失败"和"远端业务失败"） */
    public static function isLocalError(string $err): bool
    {
        return $err === self::ERR_TIMEOUT
            || $err === self::ERR_BAD_ADDR
            || $err === self::ERR_TOO_MANY_PENDING
            || $err === self::ERR_ENCODE
            || $err === self::ERR_BAD_RESPONSE;
    }
}
