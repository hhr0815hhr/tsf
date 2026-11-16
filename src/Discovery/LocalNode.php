<?php

namespace TSF\Discovery;

use TSF\Discovery\Contract\Registry;
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
