<?php

namespace TSF\Kernel;

/**
 * 崩溃取证：把一个服务崩溃时的现场写到磁盘。
 *
 * ## 为什么这是"最被低估"的能力
 *
 * 线上最难受的问题不是崩溃，是**崩溃之后什么都没留下**。原来 `Scheduler` 的 catch
 * 只打一行 `svc=duel:1001 msgId=1001 err=...`——知道它挂了，不知道挂之前在处理什么。
 * 于是"玩家说卡了一下/回档了"这类问题只能靠时间戳猜。
 *
 * Erlang 的 crash report 之所以被反复提起，不是因为它优雅，是因为它**留下了现场**。
 *
 * ## 落盘格式是 JSONL，不是格式化文本
 *
 * 一行一个 JSON 对象：第一行是元信息（服务 / 消息 / 异常 / 重启次数），
 * 第二行是崩溃前最近若干条消息，第三行是业务自己给的状态摘要。
 *
 * 用 JSONL 的理由：`jq` 能直接查、能进日志管道、字段缺失不算错。
 * 格式化成给人读的表格反而没法自动化——而崩溃现场最需要的是**能批量对比**。
 *
 * ## 磁盘不能被灌满
 *
 * 一个死循环崩溃（restart 抖动）几秒钟就能写几千个文件。所以保留上限是强制的，
 * 超过就删最旧的。丢弃历史比写满磁盘好：写满之后连日志都写不进去。
 */
final class CrashDump
{
    /** 默认保留多少个 dump 文件 */
    public const KEEP = 200;

    /**
     * 写一份现场。
     *
     * @param string             $dir     输出目录（不存在会自动建）
     * @param string             $label   服务标签，如 `duel:1001`
     * @param int                $msgId   崩溃时正在处理的消息号（0 表示不确定）
     * @param \Throwable         $e       异常
     * @param array<int, string> $recent  崩溃前的消息摘要，按时间正序
     * @param string             $state   业务状态摘要（`Service::dumpState()`）
     * @param array<string, int|string> $extra 额外元信息（比如重启次数）
     *
     * @return string 写出的路径；空串表示写失败（不抛，崩溃路径上不该再抛）
     */
    public static function save(
        string $dir,
        string $label,
        int $msgId,
        \Throwable $e,
        array $recent,
        string $state,
        array $extra = [],
        int $keep = self::KEEP
    ): string {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return "";
        }

        $now = (int) (microtime(true) * 1000);
        $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', "_", $label);
        if (!is_string($safe) || $safe === "") {
            $safe = "unknown";
        }
        // 同一毫秒内崩两次（重启抖动时很常见）不能互相覆盖：
        // 后一个 dump 覆盖前一个，等于把第一次崩溃的现场吃了
        $base = rtrim($dir, "/") . "/crash-" . $safe . "-" . $now;
        $path = $base . ".jsonl";
        if (is_file($path)) {
            $n = 1;
            while (is_file($base . "-" . $n . ".jsonl")) {
                $n++;
            }
            $path = $base . "-" . $n . ".jsonl";
        }

        $meta = [
            "t" => $now,
            "svc" => $label,
            "msgId" => $msgId,
            "err" => $e->getMessage(),
            "errClass" => $e::class,
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ];
        foreach ($extra as $k => $v) {
            if (!isset($meta[$k])) {
                $meta[$k] = $v;
            }
        }

        $body = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $body .= json_encode(["recent" => array_values($recent)], JSON_UNESCAPED_UNICODE) . "\n";
        if ($state !== "") {
            // state 由业务给，可能是 JSON 也可能是纯文本；避免二次编码失败直接写原文
            $body .= json_encode(["state" => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }

        if (@file_put_contents($path, $body) === false) {
            return "";
        }
        self::prune($dir, $keep);
        return $path;
    }

    /** 只保留最新的 `$keep` 个文件 */
    public static function prune(string $dir, int $keep = self::KEEP): void
    {
        if ($keep <= 0) {
            return;
        }
        $files = glob(rtrim($dir, "/") . "/crash-*.jsonl");
        if (!is_array($files) || count($files) <= $keep) {
            return;
        }
        // 文件名里带毫秒时间戳，所以按名字排序就是按时间排序（不用 stat，快一个数量级）
        sort($files);
        $drop = count($files) - $keep;
        for ($i = 0; $i < $drop; $i++) {
            @unlink((string) $files[$i]);
        }
    }
}
