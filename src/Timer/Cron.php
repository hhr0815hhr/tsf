<?php

namespace TSF\Timer;

/**
 * 标准 5 字段 cron 解析：`分 时 日 月 周`。
 *
 * 支持：`*`、`n`、`a-b`、`a-b/s`、`* /s`、逗号列表。
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
        if ($fields === false || count($fields) !== 5) {
            throw new \RuntimeException("cron: 需要 5 个字段（分 时 日 月 周），收到 [" . $expr . "]");
        }

        $this->minuteMask = self::fieldMask($fields[0], 0, 59, "分", $expr);
        $this->hourMask = self::fieldMask($fields[1], 0, 23, "时", $expr);
        $this->domMask = self::fieldMask($fields[2], 1, 31, "日", $expr);
        $this->monthMask = self::fieldMask($fields[3], 1, 12, "月", $expr);

        // 星期允许 7 表示周日
        $dowMask = self::fieldMask($fields[4], 0, 7, "周", $expr);
        if (($dowMask & (1 << 7)) !== 0) {
            $dowMask = ($dowMask | 1) & ~(1 << 7);
        }
        $this->dowMask = $dowMask;

        $this->domRestricted = $fields[2] !== "*";
        $this->dowRestricted = $fields[4] !== "*";
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
                $to = $from;
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
        // 从"下一分钟边界"开始找：同一分钟内重复触发会死循环
        $start = $fromTs - ($fromTs % 60) + 60;
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
                    $ts = $dayStart + $h * 3600 + $m * 60;
                    if ($ts >= $start) {
                        return $ts;
                    }
                }
            }
        }
        return 0;
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
        return "min=0x" . dechex($this->minuteMask)
            . " hour=0x" . dechex($this->hourMask)
            . " dom=0x" . dechex($this->domMask)
            . " mon=0x" . dechex($this->monthMask)
            . " dow=0x" . dechex($this->dowMask);
    }
}
