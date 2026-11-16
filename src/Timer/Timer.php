<?php

namespace TSF\Timer;

/**
 * 一个定时器条目。
 *
 * 到期后不是"调用回调"，而是**投递一条消息**给目标服务——这是刻意的：
 * 定时器如果直接调函数，就绕过了服务的串行保证，等于在服务处理消息的间隙里插进来一段代码。
 * 走消息则和别的消息排同一个队，服务的状态机仍然只有一条执行路径。
 */
final class Timer
{
    /** 定时器 id（同一次 interval 的多次触发共用同一个 id，便于 cancel） */
    public int $id;

    /** 到期时刻（毫秒，单调时钟） */
    public int $dueAt;

    /** 目标服务地址 */
    public int $addr;

    /** 到期投递的协议号 */
    public int $msgId;

    public string|array|object $data;

    /** 周期（毫秒）；0 表示一次性 */
    public int $intervalMs;

    /** cron 表达式；非空则按墙钟重排（与 intervalMs 互斥） */
    public string $cron;

    /** 被 cancel 后置位，堆里留个墓碑等它自己浮上来再丢掉 */
    public bool $cancelled = false;

    public function __construct(
        int $id,
        int $dueAt,
        int $addr,
        int $msgId,
        string|array|object $data,
        int $intervalMs = 0,
        string $cron = ""
    ) {
        $this->id = $id;
        $this->dueAt = $dueAt;
        $this->addr = $addr;
        $this->msgId = $msgId;
        $this->data = $data;
        $this->intervalMs = $intervalMs;
        $this->cron = $cron;
    }
}
