<?php

namespace TSF\Gm;

/**
 * GM 异步任务表。
 *
 * ## 为什么需要它
 *
 * 有些 GM 命令要跑几分钟（全服邮件、批量补发、重建索引），HTTP 请求等不了那么久，
 * 必须异步。但**异步不等于"返回处理中"**——那样运维永远不知道最后成没成。
 *
 * 所以异步的最小契约是三条：
 *   1. 立刻返回一个 task id（不是"已提交"这种没有后续的话）
 *   2. 能按 id 查进度和最终结果
 *   3. 失败要带上原因，别只说 fail
 *
 * ## 同步还是异步，由命令自己决定
 *
 * 框架不替命令做这个选择，只在 `args` 里看 `async` 字段。理由：快慢是命令的固有属性，
 * 只有写它的人知道「扣 100 金币」是微秒级、「给 5 万人发邮件」是分钟级。
 * 把判断放在调用方（写 curl 的人）会漏，放在框架里又猜不准。
 *
 * ## 典型用法
 *
 * ```php
 * // 命令侧
 * $id = Task::create("mail.sendAll", $total);
 * Scheduler::instance()->deliver($battleAddr, MSG_TASK, $id . "|" . $content);
 * return Result::ok(json_encode(["task" => $id, "status" => "running"]));
 *
 * // 执行侧（每帧推进一批）
 * foreach ($jobs as $id => $job) {
 *     $job->step(200);
 *     Task::progress($id, $job->done());
 *     if ($job->finished()) { Task::finish($id, ["sent" => $job->done()]); }
 * }
 *
 * // 查询侧
 * Registry::on("task.status", fn(array $a) => Result::ok(json_encode(Task::status($a["id"]))));
 * ```
 *
 * ## 为什么不做成 Service
 *
 * 任务表是纯数据（id → 状态数组），不需要自己的信箱。做成 Service 只会多一次消息往返，
 * 而 GM 侧本来就是同步查询语义。真正的执行进度由业务 Service 在帧循环里推进。
 */
final class Task
{
    /** @var array<string, array<string, int|string>> id => 任务记录 */
    private static array $tasks = [];

    private static int $seq = 0;

    /** 已完成任务的保留时长，过期由 gc() 清掉 */
    private static int $keepMs = 600000;

    public static function setKeepMs(int $ms): void
    {
        self::$keepMs = $ms > 0 ? $ms : 600000;
    }

    /**
     * 建一个任务。
     *
     * @param int $total 预计总量（未知填 0），用于进度百分比
     */
    public static function create(string $cmd, int $total = 0): string
    {
        self::$seq++;
        $id = "t" . self::$seq;
        self::$tasks[$id] = [
            "id" => $id,
            "cmd" => $cmd,
            "status" => "running",
            "total" => $total,
            "done" => 0,
            "started_ms" => (int) (microtime(true) * 1000),
            "ended_ms" => 0,
            "error" => "",
            "result" => "",
        ];
        return $id;
    }

    /** 更新进度（由执行侧每批调用） */
    public static function progress(string $id, int $done): void
    {
        if (!isset(self::$tasks[$id])) {
            return;
        }
        self::$tasks[$id]["done"] = $done;
    }

    /** 标记完成，附带结果（JSON 字符串或空） */
    public static function finish(string $id, string $result = ""): void
    {
        if (!isset(self::$tasks[$id])) {
            return;
        }
        self::$tasks[$id]["status"] = "done";
        self::$tasks[$id]["result"] = $result;
        self::$tasks[$id]["ended_ms"] = (int) (microtime(true) * 1000);
    }

    /** 标记失败。**必须带原因**：只说 fail 等于让运维去猜 */
    public static function fail(string $id, string $error): void
    {
        if (!isset(self::$tasks[$id])) {
            return;
        }
        self::$tasks[$id]["status"] = "failed";
        self::$tasks[$id]["error"] = $error;
        self::$tasks[$id]["ended_ms"] = (int) (microtime(true) * 1000);
    }

    /**
     * 查任务。不存在返回 null（调用方应返回"任务不存在或已过期"）。
     *
     * @return array<string, int|string>|null
     */
    public static function status(string $id): ?array
    {
        $t = self::$tasks[$id] ?? null;
        if ($t === null) {
            return null;
        }
        // 顺带把耗时算出来，省得调用方自己减
        $now = (int) (microtime(true) * 1000);
        $end = $t["ended_ms"] > 0 ? $t["ended_ms"] : $now;
        $t["elapsed_ms"] = $end - $t["started_ms"];
        return $t;
    }

    /** 未完成的任务 id 列表（执行侧用） */
    public static function running(): array
    {
        $out = [];
        foreach (self::$tasks as $id => $t) {
            if ($t["status"] === "running") {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * 清理过期任务，返回清理数量。
     *
     * 必须有清理：任务表是静态数组，长跑进程里不收就一路涨。
     * 只清"已完成且超过保留时长"的——running 的永不清。
     */
    public static function gc(): int
    {
        $now = (int) (microtime(true) * 1000);
        $n = 0;
        foreach (self::$tasks as $id => $t) {
            if ($t["status"] === "running") {
                continue;
            }
            if ($now - $t["ended_ms"] < self::$keepMs) {
                continue;
            }
            unset(self::$tasks[$id]);
            $n++;
        }
        return $n;
    }

    /** @return array<string, int> */
    public static function stats(): array
    {
        $running = 0;
        $done = 0;
        $failed = 0;
        foreach (self::$tasks as $t) {
            if ($t["status"] === "running") {
                $running++;
            } elseif ($t["status"] === "done") {
                $done++;
            } else {
                $failed++;
            }
        }
        return ["total" => count(self::$tasks), "running" => $running, "done" => $done, "failed" => $failed];
    }
}
