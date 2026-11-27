<?php

namespace TSF\Timer;

/**
 * cron 解析：5 字段 `分 时 日 月 周`，或 6 字段 `秒 分 时 日 月 周`。
 *
 * 6 字段是给"每秒级"的调度用的（pkg/schedule 需要）。**5 字段走的是快路径**：
 * `nextRun` 里秒层直接跳过（详见那里的注释），不会因为支持秒而变慢。
 *
 * 支持：`*`、`n`、`a-b`、`a-b/s`、星号配步长、`n/s`（从 n 到上界，每 s 个一次）、逗号列表。
 * 不支持秒级、不支持 L/W/# 这类扩展——游戏服里 99% 的需求是"每天 5:00 重置"
 * 和"每周一 0:00 开活动"，够用即可，多出来的语法只会带来没测过的边角。
 *
 * 星期取 0-6（0=周日），同时接受 7 当作周日。
 * 日和星期同时被限定时按标准 cron 的规则取**或**（`0 0 1 * 1` = 每月 1 号 **或** 每周一）。
 *
 * 时区用进程本地时区；不考虑夏令时跳变（国内无夏令时，海外部署需另测）。
 */
final class Cron
{
    /** 最多向后找 4 年（覆盖闰年 2 月 29 日这类表达式） */
    private const MAX_DAYS = 1461;

    /**
     * 秒掩码。默认只有第 0 秒——也就是"5 字段表达式"的语义。
     *
     * 单独存出来是为了让 `nextRun` 能判断"要不要进秒循环"：
     * 值是 1 时整层循环都可以跳过，5 字段的性能因此和以前完全一致。
     */
    private int $secMask = 1;

    /** @var array<string, int> 分钟掩码（bit n = 第 n 分钟） */
    private int $minuteMask = 0;

    /** @var int 小时掩码 */
    private int $hourMask = 0;

    private int $domMask = 0;

    private int $monthMask = 0;

    private int $dowMask = 0;

    private bool $domRestricted = false;

    private bool $dowRestricted = false;

    /** @var array<string, Cron> 表达式 → 解析结果（cron 注册是低频操作，但解析不便宜） */
    private static array $cache = [];

    public static function parse(string $expr): Cron
    {
        if (isset(self::$cache[$expr])) {
            return self::$cache[$expr];
        }
        $c = new Cron();
        $c->build($expr);
        self::$cache[$expr] = $c;
        return $c;
    }

    /** 下一个触发时刻（unix 秒，落在分钟边界）；找不到返回 0 */
    public static function next(string $expr, int $fromTs): int
    {
        return self::parse($expr)->nextRun($fromTs);
    }

    private function build(string $expr): void
    {
        $fields = preg_split('/\s+/', trim($expr));
        if ($fields === false) {
            throw new \RuntimeException("cron: 表达式为空 [" . $expr . "]");
        }
        $n = count($fields);
        if ($n === 5) {
            // 5 字段时补一个"秒=0"，后面按 6 字段统一处理
            array_unshift($fields, "0");
        } elseif ($n !== 6) {
            throw new \RuntimeException(
                "cron: 需要 5 或 6 个字段（分 时 日 月 周 / 秒 分 时 日 月 周），收到 [" . $expr . "]"
            );
        }

        $this->secMask = self::fieldMask($fields[0], 0, 59, "秒", $expr);
        $this->minuteMask = self::fieldMask($fields[1], 0, 59, "分", $expr);
        $this->hourMask = self::fieldMask($fields[2], 0, 23, "时", $expr);
        $this->domMask = self::fieldMask($fields[3], 1, 31, "日", $expr);
        $this->monthMask = self::fieldMask($fields[4], 1, 12, "月", $expr);

        // 星期允许 7 表示周日
        $dowMask = self::fieldMask($fields[5], 0, 7, "周", $expr);
        if (($dowMask & (1 << 7)) !== 0) {
            $dowMask = ($dowMask | 1) & ~(1 << 7);
        }
        $this->dowMask = $dowMask;

        $this->domRestricted = $fields[3] !== "*";
        $this->dowRestricted = $fields[5] !== "*";
    }

    private static function fieldMask(string $field, int $min, int $max, string $label, string $expr): int
    {
        $mask = 0;
        $parts = explode(",", $field);
        foreach ($parts as $part) {
            $step = 1;
            $body = $part;
            $slash = strpos($part, "/");
            if ($slash !== false) {
                $body = substr($part, 0, $slash);
                $stepStr = substr($part, $slash + 1);
                if ($stepStr === "" || !ctype_digit($stepStr)) {
                    throw new \RuntimeException("cron: " . $label . " 步长非法 [" . $expr . "]");
                }
                $step = (int) $stepStr;
                if ($step <= 0) {
                    throw new \RuntimeException("cron: " . $label . " 步长必须 > 0 [" . $expr . "]");
                }
            }

            if ($body === "*") {
                $from = $min;
                $to = $max;
            } elseif (strpos($body, "-") !== false) {
                $pair = explode("-", $body);
                if (count($pair) !== 2 || !ctype_digit($pair[0]) || !ctype_digit($pair[1])) {
                    throw new \RuntimeException("cron: " . $label . " 区间非法 [" . $expr . "]");
                }
                $from = (int) $pair[0];
                $to = (int) $pair[1];
            } else {
                if (!ctype_digit($body)) {
                    throw new \RuntimeException("cron: " . $label . " 取值非法 [" . $expr . "]");
                }
                $from = (int) $body;
                // 「0/5」这种**单值带步长**，语义是「从 0 一直走到上界，每 5 个一次」，
                // 不是「只在第 0 秒」。少了这一步，「每 5 秒」会变成「每分钟的第 0 秒」。
                //
                // 2026-11-27 修：v0.12.0 合并两份 Cron 实现时（8f133c3）把这一条丢了——
                // 被删掉的 pkg/schedule 版有，核心版没有。回归藏了四天：
                // schedule-test 一直在红，但它的 run.sh 数的是 "  PASS  "，
                // 而用例打的是 "  OK   "，于是 passed=0 failed=0、退出码 0。
                $to = $slash !== false ? $max : $from;
            }

            if ($from < $min || $to > $max || $from > $to) {
                throw new \RuntimeException(
                    "cron: " . $label . " 超出范围 " . $min . "-" . $max . " [" . $expr . "]"
                );
            }

            for ($v = $from; $v <= $to; $v += $step) {
                $mask |= 1 << $v;
            }
        }
        return $mask;
    }

    private function nextRun(int $fromTs): int
    {
        // 起点：5 字段从"下一分钟边界"（同一分钟内重复触发会死循环）；
        // 6 字段从"下一秒"，因为它本来就能秒级触发
        if ($this->secMask === 1) {
            $start = $fromTs - ($fromTs % 60) + 60;
        } else {
            $start = $fromTs + 1;
        }
        // 6 字段的起点可能不在整分钟上，日/时/分三层仍按整点推进，
        // 靠最后的 `$ts >= $start` 把早于起点的候选跳过
        $bm = (int) date("n", $start);
        $bd = (int) date("j", $start);
        $by = (int) date("Y", $start);

        for ($dayOff = 0; $dayOff < self::MAX_DAYS; $dayOff++) {
            $dayStart = mktime(0, 0, 0, $bm, $bd + $dayOff, $by);
            if ($dayStart === false) {
                return 0;
            }

            $mon = (int) date("n", $dayStart);
            $mday = (int) date("j", $dayStart);
            $dow = (int) date("w", $dayStart);

            if (($this->monthMask & (1 << $mon)) === 0) {
                continue;
            }
            if (!$this->dayMatches($mday, $dow)) {
                continue;
            }

            for ($h = 0; $h < 24; $h++) {
                if (($this->hourMask & (1 << $h)) === 0) {
                    continue;
                }
                for ($m = 0; $m < 60; $m++) {
                    if (($this->minuteMask & (1 << $m)) === 0) {
                        continue;
                    }
                    $base = $dayStart + $h * 3600 + $m * 60;
                    // 5 字段（secMask==1）：直接返回，不进秒循环。
                    // 这一行是"支持秒但不让 5 字段变慢"的关键——nextRun 最坏要遍历
                    // 1461 天 × 24 × 60，若在每格再加 60 次位运算就是 60 倍开销。
                    if ($this->secMask === 1) {
                        if ($base >= $start) {
                            return $base;
                        }
                        continue;
                    }
                    for ($sec = 0; $sec < 60; $sec++) {
                        if (($this->secMask & (1 << $sec)) === 0) {
                            continue;
                        }
                        $ts = $base + $sec;
                        if ($ts >= $start) {
                            return $ts;
                        }
                    }
                }
            }
        }
        return 0;
    }

    /**
     * 某个时刻是否匹配（精确到秒）。
     *
     * 与 `next()` 的关系：`matches(next(t))` 必为 true，且两者用的是同一套掩码
     * 与同一套"日/周取或"规则——**不能各写一份**，否则会出现
     * "算出来的时刻自己判定为不匹配"这种自相矛盾。
     */
    public function matches(int $ts): bool
    {
        if (($this->secMask & (1 << ((int) date("s", $ts)))) === 0) {
            return false;
        }
        if (($this->minuteMask & (1 << ((int) date("i", $ts)))) === 0) {
            return false;
        }
        if (($this->hourMask & (1 << ((int) date("G", $ts)))) === 0) {
            return false;
        }
        if (($this->monthMask & (1 << ((int) date("n", $ts)))) === 0) {
            return false;
        }
        return $this->dayMatches((int) date("j", $ts), (int) date("w", $ts));
    }

    /** 静态便捷：`Cron::match($expr, $ts)`，等价于 parse 后调 matches() */
    public static function match(string $expr, int $ts): bool
    {
        return self::parse($expr)->matches($ts);
    }

    private function dayMatches(int $mday, int $dow): bool
    {
        $domOk = ($this->domMask & (1 << $mday)) !== 0;
        $dowOk = ($this->dowMask & (1 << $dow)) !== 0;

        if ($this->domRestricted && $this->dowRestricted) {
            // 标准 cron：两者都限定时取"或"
            return $domOk || $dowOk;
        }
        if ($this->domRestricted) {
            return $domOk;
        }
        if ($this->dowRestricted) {
            return $dowOk;
        }
        return true;
    }

    /** 便于日志/调试 */
    public function describe(): string
    {
        return "sec=0x" . dechex($this->secMask)
            . " min=0x" . dechex($this->minuteMask)
            . " hour=0x" . dechex($this->hourMask)
            . " dom=0x" . dechex($this->domMask)
            . " mon=0x" . dechex($this->monthMask)
            . " dow=0x" . dechex($this->dowMask);
    }
}
