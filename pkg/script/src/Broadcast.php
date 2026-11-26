<?php

namespace TSF\Script;

/**
 * 脚本重载的集群广播。
 *
 * ## 为什么不放进 Manager
 *
 * Manager 是"本进程的脚本表"，如果它 `use TSF\Cluster\Cluster`，`pkg/script` 就从
 * **零依赖**变成依赖核心，而且单机部署和测试里也用不了。这里只接一个发送闭包，
 * 装配处决定它是 `Cluster::send()` 还是测试里的内存队列。
 *
 * ## 协议
 *
 * ```
 * RELOAD  reqId ␟ name
 * ACK     reqId ␟ node ␟ ok ␟ versions
 * ```
 *
 * ## 三条语义
 *
 * 1. **广播不等待**。`request()` 立刻返回 reqId，ack 到了再记。单线程调度里同步等
 *    会阻塞整个 worker，而重载本身是运维动作，慢一点可以接受。
 * 2. **`report()` 是当前状态，不是最终结果**。所以 `missing`（还没回）和
 *    `failedNodes`（回了但失败）必须分开——混在一起运维无法判断"是网络慢还是对方坏了"。
 * 3. **ack 里带 versions**。重载成功的判据不是"回了包"，而是"对方版本号前进了"。
 *    这条防的是"对方 reload 返回 true 但文件其实没变"。
 */
final class Broadcast
{
    /** 消息号必须 ≤ 65535：跨节点包头只有 2 字节，超了会被静默截断（见 Handoff 的教训） */
    public const MSG_RELOAD = 64201;

    public const MSG_ACK = 64202;

    private const SEP = "\x1f";

    private Manager $mgr;

    private int $selfNode;

    /** @var \Closure(int, int, string): void 参数：目标节点 / 消息号 / 载荷 */
    private \Closure $send;

    /** @var array<string, int> reqId => 目标节点数 */
    private array $targets = [];

    /** @var array<string, array<int, int>> reqId => [node => 1] 已成功 ack */
    private array $acked = [];

    /** @var array<string, array<int, int>> reqId => [node => 1] ack 但失败 */
    private array $failed = [];

    public function __construct(Manager $mgr, int $selfNode, \Closure $send)
    {
        $this->mgr = $mgr;
        $this->selfNode = $selfNode;
        $this->send = $send;
    }

    /**
     * 广播一次重载。返回 reqId，用 `report()` 查结果。
     *
     * @param array<int, int> $peers 目标节点号（不含自己）
     */
    public function request(string $name, array $peers, string $reqId = ""): string
    {
        if ($reqId === "") {
            // 节点号 + 毫秒：同一节点的两次广播不会撞 id，跨节点也不会撞
            $nowMs = (int) (microtime(true) * 1000.0);
            $reqId = (string) $this->selfNode . "-" . (string) $nowMs;
        }
        $this->targets[$reqId] = count($peers);
        $this->acked[$reqId] = [];
        $this->failed[$reqId] = [];

        $payload = $reqId . self::SEP . $name;
        $send = $this->send;
        foreach ($peers as $node) {
            $send($node, self::MSG_RELOAD, $payload);
        }
        return $reqId;
    }

    /**
     * 处理收到的消息（RELOAD 或 ACK）。业务在 Cluster 的收包回调里调它。
     *
     * @return string 要回给对方的内容；空串表示不用回
     */
    public function onMessage(int $msgId, string $payload): string
    {
        if ($msgId === self::MSG_RELOAD) {
            $parts = explode(self::SEP, $payload);
            if (count($parts) < 2) {
                return "";
            }
            $reqId = $parts[0];
            $name = $parts[1];
            $ok = $this->mgr->reload($name);
            $ver = 0;
            $s = $this->mgr->get($name);
            if ($s instanceof Script) {
                $ver = $s->versions();
            }
            return $reqId . self::SEP . (string) $this->selfNode . self::SEP
                . ($ok ? "1" : "0") . self::SEP . (string) $ver;
        }

        if ($msgId === self::MSG_ACK) {
            $parts = explode(self::SEP, $payload);
            if (count($parts) < 4) {
                return "";
            }
            $reqId = $parts[0];
            $node = (int) $parts[1];
            if ($parts[2] === "1") {
                if (!isset($this->acked[$reqId])) {
                    $this->acked[$reqId] = [];
                }
                $this->acked[$reqId][$node] = 1;
            } else {
                if (!isset($this->failed[$reqId])) {
                    $this->failed[$reqId] = [];
                }
                $this->failed[$reqId][$node] = 1;
            }
            return "";
        }

        return "";
    }

    /**
     * 当前状态。
     *
     * @return array<string, int|bool|array<int, int>>
     *   targets / acked / failedNodes / missing / done
     */
    public function report(string $reqId): array
    {
        $targets = 0;
        if (isset($this->targets[$reqId])) {
            $targets = $this->targets[$reqId];
        }
        $ackedMap = [];
        if (isset($this->acked[$reqId])) {
            $ackedMap = $this->acked[$reqId];
        }
        $failedMap = [];
        if (isset($this->failed[$reqId])) {
            $failedMap = $this->failed[$reqId];
        }

        $ackedNodes = [];
        foreach ($ackedMap as $node => $_) {
            $ackedNodes[] = $node;
        }
        $failedNodes = [];
        foreach ($failedMap as $node => $_) {
            $failedNodes[] = $node;
        }
        $settled = count($ackedMap) + count($failedMap);

        return [
            "targets" => $targets,
            "acked" => $ackedNodes,
            "failedNodes" => $failedNodes,
            "missing" => $targets - $settled,
            "done" => $settled >= $targets,
        ];
    }
}
