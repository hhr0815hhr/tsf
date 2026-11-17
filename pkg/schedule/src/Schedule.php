<?php

namespace TSF\Schedule;

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 游戏级定时任务。
 *
 * ## 和 Timer 的区别
 *
 * `Service::after() / interval()` 是**相对时间**原语：从现在起 N 毫秒后投一条消息。
 * 但运营需求是**日历语义**的：
 *
 *   「每天 5:00 重置」   —— 不是"每 86400 秒"，重启后要重新对齐到 5 点
 *   「每周一 0:00」      —— 周一是几秒？
 *   「活动在 12/01 10:00 开启」—— 绝对时间点
 *
 * 这些用 interval 拼不出来（重启会漂移、跨月跨年算不对），所以需要一个算"下次几点"的东西。
 *
 * ## 为什么用消息投递而不是闭包回调
 *
 * 直觉写法是 `$schedule->daily("reset", 5, 0, function () { ... })`。但 AOT 下
 * 「闭包存进数组、遍历时取出调用」是有风险的（元素类型退化成 php::Var，
 * 调用会被当成 callback 字符串解析 —— 这个坑在 redis 那轮踩过）。
 *
 * 所以这里改成投消息：任务只记「到点给谁投什么」，
 *
 *   $schedule->daily("reset", 5, 0, $worldAddr, Msg::RESET_DAILY);
 *
 * 业务在自己的 `onMessage` 里处理 `RESET_DAILY`。好处不只是绕开 AOT：
 * 任务定义变成纯数据（可序列化、可落库、可在 GM 里列出来），而且执行时机
 * 天然回到目标服务自己的上下文里，不会有"在别人的 tick 里改我的状态"。
 *
 * ## 精度
 *
 * 每秒扫一次，误差 ≤ 1 秒。对"每天 5 点重置"这种场景完全够。
 * 不要用它做帧级同步 —— 那是 FrameLoop 的事。
 */
final class Schedule extends Service
{
    /** 内部 tick 消息号 */
    public const TICK = 3900;

    // 任务类型
    private const KIND_DAILY = 1;
    private const KIND_WEEKLY = 2;
    private const KIND_EVERY = 3;
    private const KIND_AT = 4;

    /**
     * @var array<string, array<string, int|string>> name => [kind, next, target, msgId, data, a, b]
     *
     * 全标量，不存对象/闭包 —— AOT 下这种结构最稳。
     */
    private array $tasks = [];

    public function onStart(): void
    {
        // 每秒扫一次。interval 由 Scheduler 驱动（见 02 篇坑 8：纯服务进程要自己驱动调度器）
        $this->interval(1000, self::TICK);
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== self::TICK) {
            return;
        }
        $now = time();
        $fire = [];
        foreach ($this->tasks as $name => $t) {
            $next = (int) ($t["next"] ?? 0);
            if ($next > 0 && $now >= $next) {
                $fire[] = $name;
            }
        }
        foreach ($fire as $name) {
            $this->fire($name, $now);
        }
    }

    // ---------------- 注册 ----------------

    /**
     * 每天 hour:minute（服务器本地时区）。
     *
     * 重启后不需要重新对齐 —— "下次 5 点"是每次触发后重新算出来的，
     * 启动时也按当前时间算，不会漂移。
     */
    public function daily(string $name, int $hour, int $minute, int $target, int $msgId, string|array|object $data = ""): void
    {
        $this->tasks[$name] = [
            "kind" => self::KIND_DAILY,
            "next" => self::nextDaily($hour, $minute, time()),
            "target" => $target,
            "msgId" => $msgId,
            "data" => is_string($data) ? $data : (string) json_encode($data),
            "a" => $hour,
            "b" => $minute,
        ];
    }

    /** 每周 weekday（1=周一 … 7=周日）hour:minute */
    public function weekly(string $name, int $weekday, int $hour, int $minute, int $target, int $msgId, string|array|object $data = ""): void
    {
        if ($weekday < 1 || $weekday > 7) {
            $weekday = 1;
        }
        $this->tasks[$name] = [
            "kind" => self::KIND_WEEKLY,
            "next" => self::nextWeekly($weekday, $hour, $minute, time()),
            "target" => $target,
            "msgId" => $msgId,
            "data" => is_string($data) ? $data : (string) json_encode($data),
            "a" => $weekday * 10000 + $hour * 100 + $minute,   // 打包成标量存
            "b" => $hour,
        ];
    }

    /** 固定间隔（秒）。第一次在 interval 秒后。
     * 名字不能叫 every：父类 Service::every() 是定时器原语，签名不同会冲突 */
    public function repeat(string $name, int $intervalSec, int $target, int $msgId, string|array|object $data = ""): void
    {
        if ($intervalSec < 1) {
            $intervalSec = 1;
        }
        $this->tasks[$name] = [
            "kind" => self::KIND_EVERY,
            "next" => time() + $intervalSec,
            "target" => $target,
            "msgId" => $msgId,
            "data" => is_string($data) ? $data : (string) json_encode($data),
            "a" => $intervalSec,
            "b" => $intervalSec,
        ];
    }

    /** 绝对时间点（活动开启/结束）。已经过去的时间点不会触发 */
    public function at(string $name, int $unixTs, int $target, int $msgId, string|array|object $data = ""): void
    {
        $this->tasks[$name] = [
            "kind" => self::KIND_AT,
            "next" => $unixTs,
            "target" => $target,
            "msgId" => $msgId,
            "data" => is_string($data) ? $data : (string) json_encode($data),
            "a" => 0,
            "b" => 0,
        ];
    }

    public function cancel(string $name): bool
    {
        if (!isset($this->tasks[$name])) {
            return false;
        }
        unset($this->tasks[$name]);
        return true;
    }

    /** @return array<int, array<string, int|string>> 给 GM / 调试用 */
    public function listAll(): array
    {
        $out = [];
        foreach ($this->tasks as $name => $t) {
            $out[] = [
                "name" => (string) $name,
                "kind" => (int) $t["kind"],
                "next" => (int) $t["next"],
                "nextAt" => date("Y-m-d H:i:s", (int) $t["next"]),
            ];
        }
        return $out;
    }

    // ---------------- 触发 ----------------

    private function fire(string $name, int $now): void
    {
        $t = $this->tasks[$name] ?? [];
        if ($t === []) {
            return;
        }
        $kind = (int) ($t["kind"] ?? 0);
        $target = (int) ($t["target"] ?? 0);
        $msgId = (int) ($t["msgId"] ?? 0);
        $data = (string) ($t["data"] ?? "");

        if ($target > 0 && $msgId > 0) {
            Scheduler::instance()->deliver($target, $msgId, $data);
        }

        // 重算下次
        if ($kind === self::KIND_DAILY) {
            $this->tasks[$name]["next"] = self::nextDaily((int) $t["a"], (int) $t["b"], $now + 1);
        } elseif ($kind === self::KIND_WEEKLY) {
            $packed = (int) $t["a"];
            $wd = intdiv($packed, 10000);
            $hour = intdiv($packed % 10000, 100);
            $min = $packed % 100;
            $this->tasks[$name]["next"] = self::nextWeekly($wd, $hour, $min, $now + 1);
        } elseif ($kind === self::KIND_EVERY) {
            $iv = (int) $t["a"];
            $next = (int) $t["next"] + $iv;
            // 落后太多（比如进程停了很久）就跳到最近的将来，别补跑一堆
            if ($next <= $now) {
                $next = $now + $iv;
            }
            $this->tasks[$name]["next"] = $next;
        } else {
            // 绝对时间点：触发一次就移除
            unset($this->tasks[$name]);
        }
    }

    // ---------------- 时间计算 ----------------

    /** 下一次 hour:minute（严格大于 $now） */
    public static function nextDaily(int $hour, int $minute, int $now): int
    {
        $today = self::stamp($hour, $minute, $now);
        if ($today <= $now) {
            return $today + 86400;
        }
        return $today;
    }

    /** 下一次 weekday hour:minute（1=周一..7=周日，严格大于 $now） */
    public static function nextWeekly(int $weekday, int $hour, int $minute, int $now): int
    {
        $cur = (int) date("N", $now);
        $diff = ($weekday - $cur) % 7;
        if ($diff < 0) {
            $diff += 7;
        }
        $base = self::stamp($hour, $minute, $now);
        $target = $base + $diff * 86400;
        if ($target <= $now) {
            $target += 7 * 86400;
        }
        return $target;
    }

    /** 把 hour:minute 落到 $now 当天的同一个日期上 */
    private static function stamp(int $hour, int $minute, int $now): int
    {
        $y = (int) date("Y", $now);
        $m = (int) date("n", $now);
        $d = (int) date("j", $now);
        $ts = mktime($hour, $minute, 0, $m, $d, $y);
        if (!is_int($ts)) {
            return $now;
        }
        return $ts;
    }
}
