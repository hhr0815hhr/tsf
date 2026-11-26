<?php

namespace TSF\Rpc;

use TSF\Metrics\Metrics;
use TSF\Rpc\Codec\JsonCodec;
use TSF\Rpc\Contract\RpcCodec;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * RPC 调用端。**它自己是一个 Service**，因为响应的投递需要一个地址。
 *
 * ## 为什么不能是普通对象
 *
 * 服务端收到请求后用 `$m->from` 作为回包地址。如果调用方是一个普通对象，
 * 它没有地址——响应回来时 `deliver()` 找不到收件人，直接算 `undelivered` 丢掉。
 *
 * 所以调用端必须有个信箱。做成 Service 之后，`session` 的回程、
 * 超时扫描（用自己的定时器）都自然了。
 *
 * ## 用法
 *
 * ```php
 * $addr = Scheduler::instance()->spawnOf(function () { return new Client(200); });
 * $rpc  = Scheduler::instance()->serviceOf($addr);
 *
 * $rpc->call($targetAddr, "getProfile", "1001", function (?string $resp, string $err): void {
 *     if ($err !== "") {
 *         return;          // 超时或远端报错，$err 有值
 *     }
 *     // $resp 是远端返回的字符串
 * });
 * ```
 *
 * ## 为什么是回调而不是返回值
 *
 * 这个框架是单线程 + 消息循环，没有协程。想在 `call()` 里阻塞等响应，
 * 只能在 tick 里嵌套 tick——那会导致重入，把别的服务的消息处理插到中间。
 * 所以 RPC 只能是异步的。这也和 Scheduler 的模型一致：一切都是消息。
 *
 * ## 重试：这层不做
 *
 * 重试的正确语义取决于方法是否幂等。"超时了重发一次"对查询无害，
 * 对扣钱是灾难。框架不知道哪个是哪个，所以不做——调用方拿到底层错误
 * （`timeout` / `unavailable`）自己决定重不重试、怎么去重。
 */
final class Client extends Service
{
    /** 超时扫描的定时消息（低频，10ms 一次） */
    private const MSG_SWEEP = 60101;

    private const SWEEP_MS = 10;

    private int $defaultTimeoutMs;

    /** 未完成的调用上限。超了立刻失败，不让待发表无限涨 */
    private int $maxPending = 4096;

    /**
     * @var array<int, array{cb: \Closure, deadline: int, addr: int, method: string}>
     */
    private array $pending = [];

    private int $nextId = 1;

    private int $sent = 0;

    private int $ok = 0;

    private int $failed = 0;

    private int $timedOut = 0;

    private int $late = 0;

    /** 协议：必须和服务端的 codec 一致，否则对方解不出来 */
    private RpcCodec $codec;

    /** 本节点认识的 RPC 载荷版本 */
    private int $protoVersion = 1;

    /**
     * 已知是旧二进制的对端节点 => true。
     *
     * 判定方式是**乐观协商**：先按新格式发，旧对端的 codec 解不出来会立刻回
     * `bad-request`（不是超时），据此标记并降级重发一次。之后对这个节点直接用旧格式。
     *
     * 为什么不做握手协商：hello 是 `count($parts) !== 4` 的硬校验，
     * 加字段会让**旧节点直接判定 bad-hello、链路起不来**——滚动升级当场断。
     *
     * @var array<int, bool>
     */
    private array $legacy = [];

    /** @var array<int, int> 对端节点的载荷版本（从响应里学到） */
    private array $peerVer = [];

    /** @var array<int, int> 节点 => `legacy` 标记的到期时刻 */
    private array $legacyAt = [];

    /**
     * `legacy` 标记的有效期。
     *
     * 不设成永久：对方升级之后，我们不该永远用旧格式跟它说话——那样拿不到版本、
     * 用不了新字段，而且**没有自愈能力**。到期后重新按新格式试一次，
     * 成功就自动恢复正常；失败就再标记一段，成本只是每 TTL 一次多余的往返。
     */
    private int $legacyTtlMs = 60000;

    public function __construct(int $defaultTimeoutMs = 200, ?RpcCodec $codec = null, int $protoVersion = 1)
    {
        $this->defaultTimeoutMs = $defaultTimeoutMs > 0 ? $defaultTimeoutMs : 200;
        $this->codec = $codec !== null ? $codec : new JsonCodec();
        $this->protoVersion = $protoVersion > 0 ? $protoVersion : 1;
    }

    /** 本节点的载荷版本 */
    public function protoVersion(): int
    {
        return $this->protoVersion;
    }

    /** 某个对端节点的载荷版本；0 表示还不知道 */
    public function peerVersion(int $node): int
    {
        return $this->peerVer[$node] ?? 0;
    }

    /** `legacy` 标记的有效期（毫秒）。设 0 表示下次调用就重新试探 */
    public function setLegacyTtl(int $ms): void
    {
        $this->legacyTtlMs = $ms >= 0 ? $ms : 60000;
    }

    /** 某个对端当前是否只能收旧格式。标记过期会自动失效并重新试探 */
    public function isLegacy(int $node): bool
    {
        if (!isset($this->legacy[$node])) {
            return false;
        }
        $exp = $this->legacyAt[$node] ?? 0;
        if ($exp > 0 && Scheduler::nowMs() >= $exp) {
            unset($this->legacy[$node], $this->legacyAt[$node]);
            return false;
        }
        return true;
    }

    public function codec(): RpcCodec
    {
        return $this->codec;
    }

    public function onStart(): void
    {
        // 超时用定时器扫，而不是"每个请求挂一个 timeout"：
        // 1000 个在飞的请求就是 1000 个定时器，而扫描是 10ms 一次定长开销
        $this->interval(self::SWEEP_MS, self::MSG_SWEEP);
    }

    /**
     * 发起调用。**返回请求 id，0 表示根本没发出去**（地址为 0 或待发表满）。
     *
     * 无论成功失败，`$cb` 一定会被调用一次（除非进程在中间挂了）——
     * 调用方不需要自己兜底"回调可能永远不来"。
     *
     * @param \Closure $cb 签名 `(?string $resp, string $err): void`；
     *                     `$err` 为空表示成功，此时 `$resp` 有值
     */
    public function call(int $addr, string $method, string $body, \Closure $cb, int $timeoutMs = 0): int
    {
        if ($addr === 0) {
            $this->failed++;
            Metrics::inc("rpc.failed");
            $cb(null, "bad-addr");
            return 0;
        }
        if (count($this->pending) >= $this->maxPending) {
            $this->failed++;
            Metrics::inc("rpc.failed");
            $cb(null, "too-many-pending");
            return 0;
        }

        $id = $this->nextId;
        $this->nextId++;
        if ($this->nextId > 0x7FFFFFFF) {
            $this->nextId = 1;   // 回绕；在飞的 id 表里没有冲突就行
        }

        $ms = $timeoutMs > 0 ? $timeoutMs : $this->defaultTimeoutMs;
        $node = ($addr >> 24) & 0xFF;
        $versioned = !$this->isLegacy($node);
        $this->pending[$id] = [
            "cb" => $cb,
            "deadline" => Scheduler::nowMs() + $ms,
            "addr" => $addr,
            "method" => $method,
            "body" => $body,
            "versioned" => $versioned,
            "retried" => false,
        ];

        // 载荷交给 codec 编。框架不关心它是 JSON、protobuf 还是裸二进制。
        //
        // 注意：Cluster 的默认 codec 是 RawPayload，**只接受 string**——传 array 会在
        // pack() 里直接抛异常。所以不管 RpcCodec 产出什么，都必须是字节串，
        // 而不是"结构化数组交给传输层再编一次"。
        $payload = $this->codec->packRequest($method, $body);
        if ($payload === "") {
            unset($this->pending[$id]);
            $this->failed++;
            Metrics::inc("rpc.failed");
            $cb(null, "encode-failed");
            return 0;
        }
        if ($versioned) {
            // 版本放在 codec 载荷之前：老对端的 codec 会立刻解不出并回 bad-request，
            // 我们据此降级——这比等超时快一个数量级
            $payload = Rpc::TAG_V1 . Rpc::SEP . $this->protoVersion . Rpc::SEP . $payload;
        }

        $this->sent++;

        Metrics::inc("rpc.sent");
        $s = Scheduler::instance();
        // deliverAs 而不是 deliver：必须让 from 是**本 RPC 服务的地址**，
        // 否则响应会回到"当前正在处理消息的那个服务"的信箱里去
        $s->deliverAs($this->addr(), $addr, Rpc::MSG_REQ, $payload, $id);
        return $id;
    }

    public function onMessage(Message $m): void
    {
        $msgId = $m->msgId;
        if ($msgId === self::MSG_SWEEP) {
            $this->sweep(Scheduler::nowMs());
            return;
        }
        if ($msgId !== Rpc::MSG_RESP) {
            return;
        }

        $raw = is_string($m->data) ? $m->data : "";
        $body = $raw;

        // 响应风格跟请求一致：我们发了版本化请求，对端才会带版本回（老对端回不了）
        $p = $this->pending[$m->session] ?? null;
        $wasVersioned = $p !== null && ($p["versioned"] ?? false);
        $peerVer = 0;
        if ($wasVersioned && strpos($raw, Rpc::TAG_V1 . Rpc::SEP) === 0) {
            $rest = substr($raw, strlen(Rpc::TAG_V1) + 1);
            $cut = strpos($rest, Rpc::SEP);
            if ($cut === false) {
                $this->settle($m->session, false, "bad-response");
                return;
            }
            $peerVer = (int) substr($rest, 0, $cut);
            $body = substr($rest, $cut + 1);
            if ($peerVer > 0 && $p !== null) {
                $this->peerVer[($p["addr"] >> 24) & 0xFF] = $peerVer;
            }
        }

        $resp = $this->codec->unpackResponse($body);
        if ($resp === null) {
            // 解不出来也要结束这次调用：让调用方等超时是最难排查的故障
            $this->settle($m->session, false, "bad-response");
            return;
        }

        // 老对端解不出新格式，会立刻回 bad-request。标记并降级重发一次——
        // 这是唯一能"自动发现对方是旧二进制"的信道
        if ($wasVersioned && !$resp->ok && $resp->body === "bad-request" && $p !== null) {
            $node = ($p["addr"] >> 24) & 0xFF;
            $this->legacy[$node] = true;
            $this->legacyAt[$node] = Scheduler::nowMs() + $this->legacyTtlMs;
            $this->retryLegacy($m->session, $p);
            return;
        }

        $this->settle($m->session, $resp->ok, $resp->body);
    }

    /**
     * 用旧格式重发同一次调用。
     *
     * 复用同一个 session id：调用方拿到的仍是同一个回调，只是内部多了一次往返。
     * 超时时间不重置——重试不该让调用方多等一个完整超时。
     *
     * @param array{cb: \Closure, deadline: int, addr: int, method: string, body: string, versioned: bool, retried: bool} $p
     */
    private function retryLegacy(int $id, array $p): void
    {
        $payload = $this->codec->packRequest($p["method"], $p["body"]);
        if ($payload === "") {
            $this->settle($id, false, "encode-failed");
            return;
        }
        $p["versioned"] = false;
        $p["retried"] = true;
        $this->pending[$id] = $p;
        $this->sent++;
        Metrics::inc("rpc.sent");
        Scheduler::instance()->deliverAs($this->addr(), $p["addr"], Rpc::MSG_REQ, $payload, $id);
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function stats(): array
    {
        return [
            "sent" => $this->sent,
            "ok" => $this->ok,
            "failed" => $this->failed,
            "timeout" => $this->timedOut,
            "late" => $this->late,
            "pending" => count($this->pending),
        ];
    }

    private function settle(int $id, bool $success, string $body): void
    {
        $p = $this->pending[$id] ?? null;
        if (!is_array($p)) {
            // 超时之后才到的响应。计数而不回调——调用方已经收到过"超时"了，
            // 再回调一次会让它对同一笔请求处理两遍
            $this->late++;
            Metrics::inc("rpc.late");
            return;
        }
        unset($this->pending[$id]);

        $cb = $p["cb"];

        if ($success) {
            $this->ok++;
            Metrics::inc("rpc.ok");
        } else {
            $this->failed++;
            Metrics::inc("rpc.failed");
        }
        if ($cb instanceof \Closure) {
            $cb($success ? $body : null, $success ? "" : $body);
        }
    }

    private function sweep(int $now): void
    {
        if ($this->pending === []) {
            return;
        }
        // 先收集再删：边遍历边 unset 同一数组在 AOT 下行为没验证过，
        // 而且这段代码不热（10ms 一次），多一次分配无所谓
        $expired = [];
        foreach ($this->pending as $id => $p) {
            if ($now >= (int) $p["deadline"]) {
                $expired[] = (int) $id;
            }
        }
        foreach ($expired as $id) {
            $p = $this->pending[$id] ?? null;
            if (!is_array($p)) {
                continue;
            }
            unset($this->pending[$id]);
            $this->timedOut++;
            Metrics::inc("rpc.timeout");
            $cb = $p["cb"];
            if ($cb instanceof \Closure) {
                $cb(null, "timeout");
            }
        }
    }
}
