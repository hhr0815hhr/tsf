<?php

namespace TSF\Discovery;

use TSF\Discovery\Contract\Registry;
use TSF\Kernel\Logger;
use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 本节点身份 + 自动心跳。做成 Service 是因为"定期做事"在这个框架里就是"定时给自己发消息"。
 *
 * ```php
 * $reg = new FileRegistry("/var/run/tsf/nodes", 3000);
 * $node = new LocalNode($reg, new NodeInfo(1, "/tmp/tsf_node1.sock", "json"));
 * Scheduler::instance()->spawnOf(fn() => $node);
 * // 之后什么都不用管：上线自动注册、活着自动心跳、优雅退出自动注销
 * ```
 *
 * ## 心跳间隔 vs TTL 的关系
 *
 * TTL 必须显著大于心跳间隔，否则每次 GC 抖动或一次调度延迟就会被误判死亡。
 * 默认 1 秒心跳 / 3 秒 TTL：能容忍连续两次心跳丢失。
 *
 * 反过来，**收敛时间 ≈ TTL + 轮询间隔**。3 秒 TTL + 0.5 秒轮询，最坏 3.5 秒发现——
 * 这是取舍，不是 bug。想更快就把 TTL 压小，代价是误判率上升。
 *
 * ## 崩溃和优雅退出的区别
 *
 * - 优雅退出：`onStop` 里 `unregister()`，下一次 `refresh()` 立刻看不到它；
 * - `kill -9`：`onStop` 根本不执行，只能等 TTL 过期。
 *
 * 所以注销是"加速收敛"，不是"保证收敛"。真正的保证只能是 TTL。
 */
final class LocalNode extends Service
{
    private const MSG_BEAT = 60201;

    private Registry $registry;

    private NodeInfo $info;

    private int $beatMs;

    private int $beats = 0;

    private bool $registered = false;

    /** @var array<int, \Closure> 每次心跳之后要做的事：对账、同步成员…… */
    private array $afterBeat = [];

    public function __construct(Registry $registry, NodeInfo $info, int $beatMs = 1000)
    {
        $this->registry = $registry;
        $this->info = $info;
        $this->beatMs = $beatMs > 0 ? $beatMs : 1000;
    }

    public function onStart(): void
    {
        $this->registered = $this->registry->register($this->info);
        $this->interval($this->beatMs, self::MSG_BEAT);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== self::MSG_BEAT) {
            return;
        }
        $this->beats++;
        $this->registry->heartbeat($this->info->nodeId);
        foreach ($this->afterBeat as $cb) {
            try {
                $cb();
            } catch (\Throwable $e) {
                // 对账失败不该让心跳服务崩掉：记一行，下个周期再来
                Logger::error("localnode: after-beat hook failed", ["err" => $e->getMessage()]);
            }
        }
    }

    /**
     * 注册"每次心跳之后"要做的事。典型用法：跟着心跳对一遍集群成员。
     *
     * ```php
     * $membership = new Membership($registry, $cluster);
     * $node->afterBeat(function () use ($membership): void {
     *     $membership->sync();
     * });
     * ```
     *
     * 为什么挂在这里：心跳的周期本来就是"我发现对端变化的粒度"（TTL 的量级），
     * 对账跟着它走最自然，不用再起一个定时器。
     */
    public function afterBeat(\Closure $cb): void
    {
        $this->afterBeat[] = $cb;
    }

    public function onStop(): void
    {
        if ($this->registered) {
            $this->registry->unregister($this->info->nodeId);
        }
    }

    public function info(): NodeInfo
    {
        return $this->info;
    }

    public function nodeId(): int
    {
        return $this->info->nodeId;
    }

    public function beats(): int
    {
        return $this->beats;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }
}
