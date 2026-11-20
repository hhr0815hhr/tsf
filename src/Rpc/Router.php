<?php

namespace TSF\Rpc;

use TSF\Metrics\Metrics;

use TSF\Rpc\Codec\JsonCodec;
use TSF\Rpc\Contract\RpcCodec;
use TSF\Service\Message;
use TSF\Service\Scheduler;

/**
 * 服务端的方法表。任何 Service 都能用——不需要专门继承一个 RpcService。
 *
 * ```php
 * final class ProfileService extends Service
 * {
 *     private Router $rpc;
 *
 *     public function __construct()
 *     {
 *         $this->rpc = new Router();
 *         $this->rpc->on("getProfile", function (string $uid): string {
 *             return '{"uid":' . $uid . ',"level":10}';
 *         });
 *     }
 *
 *     public function onMessage(Message $m): void
 *     {
 *         if ($this->rpc->handle($m)) {
 *             return;          // 是 RPC 请求，已经应答了
 *         }
 *         // 普通消息继续走
 *     }
 * }
 * ```
 *
 * ## 为什么不用接口/继承
 *
 * RPC 只是"消息处理的一个分支"。强制每个远程服务继承 `RpcService` 会把
 * "能不能被远程调用"和"是什么类型的服务"绑在一起——而这两件事没关系。
 * 一个房间服务可能既处理客户端包又提供几个查询接口。
 *
 * ## 异常一律转成错误响应
 *
 * 处理函数抛异常时**不能让整个服务挂掉**。客户端传了脏参数是常态，
 * 一个 `TypeError` 打穿服务、把整个进程带走，是不可接受的。
 * 所以这里 catch 一切，回一个 ERR 响应，调用方自己决定怎么办。
 */
final class Router
{
    /** @var array<string, \Closure> method => handler */
    private array $handlers = [];

    private int $handled = 0;

    private int $errors = 0;

    private int $unrouted = 0;

    /** 协议：必须和调用端的 codec 一致 */
    private RpcCodec $codec;

    /** 本节点认识的载荷版本；请求版本比它新就拒绝 */
    private int $protoVersion = 1;

    /** 当前这条请求是不是版本化格式（回应要跟请求同风格） */
    private bool $replyVersioned = false;

    /** 因版本不足被拒绝的请求数（观测：滚动升级期间应短暂出现，之后归零） */
    private int $versionRejected = 0;

    public function __construct(?RpcCodec $codec = null, int $protoVersion = 1)
    {
        $this->protoVersion = $protoVersion > 0 ? $protoVersion : 1;
        $this->codec = $codec !== null ? $codec : new JsonCodec();
    }

    public function codec(): RpcCodec
    {
        return $this->codec;
    }

    /**
     * 注册一个方法。
     *
     * 处理函数签名：`(string $body, Message $m): string`。返回值会作为响应体；
     * 返回非 string 时按空串回（不报错——空响应是合法的）。
     */
    public function on(string $method, \Closure $handler): Router
    {
        $this->handlers[$method] = $handler;
        return $this;
    }

    public function has(string $method): bool
    {
        return isset($this->handlers[$method]);
    }

    public function methods(): array
    {
        $out = [];
        foreach ($this->handlers as $name => $h) {
            $out[] = (string) $name;
        }
        return $out;
    }

    /**
     * 试着当作 RPC 请求处理。返回 true 表示这条消息归我管（无论成功失败）。
     */
    public function handle(Message $m): bool
    {
        if ($m->msgId !== Rpc::MSG_REQ) {
            return false;
        }

        $this->replyVersioned = false;
        $raw = $m->data;
        if (!is_string($raw)) {
            // 跨进程时 RawPayload 只搬 string；收到非 string 说明对端不是本 RPC 实现
            $this->unrouted++;
            $this->reply($m, false, "bad-envelope");
            return true;
        }

        // 版本化请求：`V1 \x1f 版本 \x1f codec 载荷`。
        // 没有标签的就是老格式（滚动升级窗口里旧调用方还在发这种）
        if (strpos($raw, Rpc::TAG_V1 . Rpc::SEP) === 0) {
            $rest = substr($raw, strlen(Rpc::TAG_V1) + 1);
            $cut = strpos($rest, Rpc::SEP);
            if ($cut === false) {
                $this->unrouted++;
                $this->reply($m, false, "bad-request");
                return true;
            }
            $peerVer = (int) substr($rest, 0, $cut);
            $raw = substr($rest, $cut + 1);
            $this->replyVersioned = true;

            // 对方读得懂本节点的格式，但本节点读不懂对方的 → 只能拒绝。
            // 回一个**明确的**错误（不是超时、不是 bad-request），
            // 让调用方知道"这里是个旧节点"，可以降级或换个目标
            if ($peerVer > $this->protoVersion) {
                $this->versionRejected++;
                Metrics::inc("rpc.version_rejected");
                $this->reply($m, false, Rpc::ERR_VERSION);
                return true;
            }
        }

        $req = $this->codec->unpackRequest($raw);
        if ($req === null) {
            // codec 解不出来：说明两端协议不一致，或对端发的东西坏了。
            // 必须回一个错误，让调用方立刻知道，而不是等超时
            $this->unrouted++;
            $this->reply($m, false, "bad-request");
            return true;
        }

        $method = $req->method;
        $body = $req->body;

        $h = $this->handlers[$method] ?? null;
        if (!($h instanceof \Closure)) {
            $this->unrouted++;
            $this->reply($m, false, "no-method:" . $method);
            return true;
        }

        try {
            $out = $h($body, $m);
            $this->handled++;
            // 不靠 `is_string()` 判定：AOT 下闭包返回值经过类型擦除后，
            // `is_string` 可能对字符串也返回 false，导致"handler 明明返回了内容、
            // 对端却收到空串"。这里直接取字符串化结果，非字串一律当空。
            $payload = "";
            if (is_string($out)) {
                $payload = $out;
            } elseif (is_int($out) || is_float($out)) {
                $payload = (string) $out;
            }
            $this->reply($m, true, $payload);
        } catch (\Throwable $e) {
            // 处理函数崩了也要回包：调用方等到超时才知道失败，是最难排查的情况
            $this->errors++;
            $this->reply($m, false, "handler-error:" . $e->getMessage());
        }
        return true;
    }

    public function stats(): array
    {
        return [
            "version_rejected" => $this->versionRejected,
            "handled" => $this->handled,
            "errors" => $this->errors,
            "unrouted" => $this->unrouted,
            "methods" => count($this->handlers),
        ];
    }

    private function reply(Message $m, bool $ok, string $body): void
    {
        if ($m->from === 0) {
            return;   // 系统发来的请求没人收，不回
        }
        $payload = $this->codec->packResponse($ok, $body);
        if ($this->replyVersioned) {
            $payload = Rpc::TAG_V1 . Rpc::SEP . $this->protoVersion . Rpc::SEP . $payload;
        }
        Scheduler::instance()->deliver($m->from, Rpc::MSG_RESP, $payload, $m->session);
    }
}
