<?php

namespace TSF\Discovery;

use TSF\Cluster\Cluster;
use TSF\Discovery\Contract\Registry;
use TSF\Kernel\Logger;

/**
 * 成员联动：让一个 `Cluster` 保持连通的节点，和注册表里**活着的**节点保持一致。
 *
 * ## 为什么需要它
 *
 * `Cluster::watchPeer()` 解决了"断了会自己连回来"，但**谁该被 watch** 仍要业务自己写。
 * 静态配置的拓扑可以直接写死；节点会扩缩、换地址的部署就得有人盯着注册表——
 * 此前没有人做这件事，于是"注册表里有它、集群里连不上它"是常态，而且没有任何报错。
 *
 * 这个类就是那个"盯着的人"：
 *
 * ```
 * 注册表里活着、我还没 watch 的   → watchPeer(node, 它登记的地址)
 * 我 watch 过、但注册表里已经没了的 → forgetPeer(node)   // 已建立的链路留着，断了不再连
 * ```
 *
 * ## 它不做的事
 *
 * - **不主动断开链路**。节点从注册表里消失（TTL 到期）不等于对端进程已经没了；
 *   真没了链路自己会断。这里只是不再为它重连。
 * - **不替你决定多久对一次账**。`sync()` 是显式调用的——频率取决于场景。
 *   常规做法是挂在 `LocalNode::afterBeat()` 上，跟着心跳走（默认 1 秒一次）。
 * - **不查注册表**。它只读 `Registry::alive()`，不调 `refresh()`——那是注册表自己的事。
 *
 * ## 核心不依赖可选包
 *
 * 它只认 `Discovery\Contract\Registry` 这个接口（在核心里）。`FileRegistry` 是核心的，
 * `RedisRegistry` 在 `pkg/registry` 里——都能传进来，核心不需要知道 Redis 的存在。
 */
final class Membership
{
    private Registry $registry;

    private Cluster $cluster;

    private int $selfNode;

    private int $ttlMs;

    /** @var array<int, string> 节点号 => 上一次登记给它 watchPeer 的地址 */
    private array $watching = [];

    private int $syncs = 0;

    private int $added = 0;

    private int $removed = 0;

    private int $changed = 0;

    /**
     * 登记地址 → 拨号地址 的转换；null = 用默认规则（见 `dialOf()`）。
     *
     * @var \Closure|null  function (string $endpoint): string
     */
    private ?\Closure $resolver = null;

    public function __construct(Registry $registry, Cluster $cluster, int $ttlMs = 0, ?\Closure $resolver = null)
    {
        $this->registry = $registry;
        $this->cluster = $cluster;
        $this->selfNode = $cluster->nodeId();
        $this->ttlMs = $ttlMs;
        $this->resolver = $resolver;
    }

    /**
     * 对一遍账。返回这次有没有变动（加了或撤了）。
     *
     * 幂等：连续调用不会重复登记同一个地址。
     */
    public function sync(): bool
    {
        $this->syncs++;
        $alive = [];
        foreach ($this->registry->alive($this->ttlMs) as $n) {
            if ($n->nodeId !== $this->selfNode) {
                $alive[$n->nodeId] = $n->endpoint;
            }
        }

        $changed = false;

        // 新出现的、或者地址变了的（对端换了端口重启）
        foreach ($alive as $node => $endpoint) {
            $dial = $this->dialOf($endpoint);
            if ($dial === "") {
                Logger::warn("membership: node has no usable endpoint, skipped", ["node" => $node, "endpoint" => $endpoint]);
                continue;
            }
            $prev = $this->watching[$node] ?? "";
            if ($prev === $dial) {
                continue;
            }
            $this->cluster->watchPeer($node, $dial);
            $this->watching[$node] = $dial;
            $this->added++;
            $changed = true;
            Logger::info("membership: watching peer", [
                "node" => $node,
                "dial" => $dial,
                "why" => $prev === "" ? "joined" : "address changed",
            ]);
        }

        // 已经不在注册表里的：不再为它重连
        // 循环变量用新名字：上面的循环里 $dial 已经是字符串类型，AOT 不允许再从数组里取值赋给它
        foreach ($this->watching as $node => $gone) {
            if (isset($alive[$node])) {
                continue;
            }
            $this->cluster->forgetPeer($node);
            unset($this->watching[$node]);
            $this->removed++;
            $changed = true;
            Logger::info("membership: stopped watching peer", ["node" => $node, "dial" => (string) $gone]);
        }

        if ($changed) {
            $this->changed++;
        }
        return $changed;
    }

    /**
     * 登记地址 → 拨号地址。
     *
     * 注册表里的 `endpoint` 有三种常见写法，都能认：
     *
     * | 写法 | 例子 | 处理 |
     * |:--|:--|:--|
     * | 带协议 | `tcp://10.0.0.2:9301` / `unix:///tmp/a.sock` | 原样 |
     * | `host:port`（没有 `/`） | `10.0.0.2:9301`、`127.0.0.1:9301` | 当 TCP |
     * | 裸路径 | `/tmp/a.sock` | 当 unix socket（`Cluster` 的老写法） |
     *
     * 判断依据是"有没有 `/`"：裸路径里必然有 `/`，而 `host:port` 不会。
     * IPv6 字面量（`[::1]:9301`）里有冒号也有方括号但可能没有 `/`——也被当成 TCP，是对的。
     */
    public function dialOf(string $endpoint): string
    {
        $r = $this->resolver;
        if ($r !== null) {
            return $r($endpoint);
        }
        $e = trim($endpoint);
        if ($e === "") {
            return "";
        }
        if (strpos($e, "://") !== false) {
            return $e;
        }
        if (strpos($e, "/") === false && strpos($e, ":") !== false) {
            return "tcp://" . $e;
        }
        return $e;
    }

    /** @return array<int, string> 当前保持连通的节点：节点号 => 拨号地址 */
    public function watching(): array
    {
        return $this->watching;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            "syncs" => $this->syncs,
            "added" => $this->added,
            "removed" => $this->removed,
            "changed" => $this->changed,
            "watching" => count($this->watching),
        ];
    }
}
