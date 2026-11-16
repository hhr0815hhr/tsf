<?php

namespace TSF\Config;

use TSF\Service\Message;
use TSF\Service\Service;

/**
 * 配置表热更：盯着 `manifest.txt`，变了就重载受影响的表。
 *
 * ```php
 * Tables::load("build/config");
 * Scheduler::instance()->spawnOf(function (): Watcher {
 *     return new Watcher("build/config", function (array $changed): array {
 *         return Tables::reload($changed);     // 生成的代码，按表重载
 *     }, 30);
 * });
 * ```
 *
 * ## 为什么看清单而不是看 .bin 的 mtime
 *
 * `filemtime()` 只到秒。两秒钟内改两次、或者改一个**等长的数值**
 * （`price 100` → `200`，连文件大小都没变），mtime+size 完全察觉不到。
 *
 * T5-02 的服务发现踩过一模一样的坑：心跳用 mtime 判活，结果判死时间在 2~3 秒之间
 * 随机跳。那次之后定下的规矩是：**判"内容有没有变"要看内容，不要看时间戳**。
 *
 * 所以由导出端算 crc32 写进清单（`manifest.txt`），监听端只读这几十个字节做字符串
 * 比对——1 微秒的事，而且内容变了一定能发现。crc 的计算成本由导出端付，不在热路径上。
 *
 * ## 清单写入是原子的
 *
 * 导出端先写 `manifest.tmp` 再 `rename`。rename 在同一文件系统上是原子的，
 * 所以监听端要么看到旧清单、要么看到新清单，不会读到半截。
 * 也因此**不需要"等文件稳定"的延迟**——省下的这几十毫秒直接体现在生效时间上。
 *
 * 顺序也重要：导出端先写完所有 `.bin`，最后才改清单。监听端看到新清单时，
 * 数据文件一定已经就位。
 *
 * ## 失败要保留旧数据
 *
 * 重载抛异常时**不动现有表**（`Tables::reload` 内部先建新实例再赋值），
 * 只记错误。策划存了一张坏表，线上不该跟着崩——旧数据继续用，日志里留一条。
 */
final class Watcher extends Service
{
    private const MSG_POLL = 60301;

    private string $dir;

    /** @var \Closure 签名 `(array $changedFiles): array`，通常就是 `Tables::reload(...)` */
    private \Closure $reload;

    private int $pollMs;

    /** 上一次看到的清单原文（整体比较，不做逐行 diff） */
    private string $last = "";

    private int $polls = 0;

    private int $reloads = 0;

    private int $failures = 0;

    /** 最近一次重载耗时（ms） */
    private float $lastMs = 0.0;

    private string $lastError = "";

    /** @var string[] 最近一次重载涉及的表 */
    private array $lastChanged = [];

    public function __construct(string $dir, \Closure $reload, int $pollMs = 30)
    {
        $this->dir = rtrim($dir, "/");
        $this->reload = $reload;
        $this->pollMs = $pollMs > 0 ? $pollMs : 30;
    }

    public function onStart(): void
    {
        // 记下启动时的清单：之后只有"和这一份不一样"才算变更。
        // 不记的话第一次轮询就会把现有数据当成"变了"，白重载一遍
        $this->last = $this->readManifest();
        $this->interval($this->pollMs, self::MSG_POLL);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== self::MSG_POLL) {
            return;
        }
        $this->poll();
    }

    private function poll(): void
    {
        $this->polls++;

        $cur = $this->readManifest();
        if ($cur === "" || $cur === $this->last) {
            return;
        }

        $prev = $this->parse($this->last);
        $next = $this->parse($cur);
        $changed = [];
        foreach ($next as $name => $crc) {
            if (!isset($prev[$name]) || $prev[$name] !== $crc) {
                $changed[] = (string) $name;
            }
        }

        // 清单变了、但没有哪个表的 crc 变（比如某张表被删掉了）：更新基线就行
        if ($changed === []) {
            $this->last = $cur;
            return;
        }

        $t0 = hrtime(true);
        try {
            $fn = $this->reload;
            $fn($changed);
            $this->reloads++;
            $this->lastError = "";
        } catch (\Throwable $e) {
            // 保留旧数据，只记错误。下一轮不会重试——见类注释"失败要保留旧数据"
            $this->failures++;
            $this->lastError = $e->getMessage();
        }
        $this->lastMs = (float) (hrtime(true) - $t0) / 1000000.0;
        $this->lastChanged = $changed;
        $this->last = $cur;
    }

    private function readManifest(): string
    {
        $s = @file_get_contents($this->dir . "/manifest.txt");
        return $s === false ? "" : $s;
    }

    /** @return array<string, string> 文件名 => crc32 */
    private function parse(string $content): array
    {
        $out = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === "#") {
                continue;
            }
            $parts = explode(" ", $line);
            if (count($parts) < 3) {
                continue;
            }
            $out[$parts[0]] = $parts[2];
        }
        return $out;
    }

    public function stats(): array
    {
        return [
            "polls" => $this->polls,
            "reloads" => $this->reloads,
            "failures" => $this->failures,
            "last_ms" => $this->lastMs,
            "last_changed" => $this->lastChanged,
            "last_error" => $this->lastError,
            "poll_ms" => $this->pollMs,
        ];
    }

    public function lastError(): string
    {
        return $this->lastError;
    }
}
