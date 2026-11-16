<?php

namespace TSF\Metrics;

use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 定期把指标表格打进日志。
 *
 * ```php
 * Scheduler::instance()->spawnOf(function (): Reporter {
 *     return new Reporter(60000);   // 每分钟一条
 * });
 * ```
 *
 * ## 为什么是"打日志"而不是"开端口"
 *
 * 这个框架跑在自己的游戏服上，不在 K8s 里。指标落到日志、由 filebeat/vector 采集，
 * 已经能满足"出问题时能查到"；为它多开一个 HTTP 端口意味着多一个攻击面、
 * 多一份配置、多一个会挂的东西，而收益只是"能在 Grafana 里看曲线"。
 *
 * 真要接 Prometheus 的话，`Metrics::snapshot()` 已经是结构化数据，
 * 写个导出器是几十行的事——但那应该是**需要的时候再加**，而不是默认背在身上。
 *
 * ## 累积还是按窗口
 *
 * 默认**累积**（不清零）。累积的 p99 会随时间变钝：跑了六小时的 p99 看不出
 * 最近五分钟的退化。所以留了 `$resetAfterDump`：每条日志后清零，
 * 每次看到的就是"上一个周期"的数字——排障时这个更有用。
 * 代价是丢失总量，需要总量的话看计数器趋势或另存。
 */
final class Reporter extends Service
{
    private const MSG_DUMP = 60401;

    private int $intervalMs;

    private bool $resetAfterDump;

    private int $dumps = 0;

    public function __construct(int $intervalMs = 60000, bool $resetAfterDump = false)
    {
        $this->intervalMs = $intervalMs > 0 ? $intervalMs : 60000;
        $this->resetAfterDump = $resetAfterDump;
    }

    public function onStart(): void
    {
        $this->interval($this->intervalMs, self::MSG_DUMP);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== self::MSG_DUMP) {
            return;
        }
        $this->report();
    }

    public function report(): void
    {
        Metrics::dump();
        $this->dumps++;
        if ($this->resetAfterDump) {
            Metrics::reset();
        }
    }

    public function dumps(): int
    {
        return $this->dumps;
    }
}
