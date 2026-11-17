<?php

namespace TSF\Schedule;

/**
 * cron 表达式解析与匹配。
 *
 * 支持 6 段（秒 分 时 日 月 周）或 5 段（分 时 日 月 周，秒默认 0）。
 *
 *     0 0 5 * * *        每天 5:00:00
 *     0/5 * * * * *      每 5 秒
 *     0 0 0 1 * *        每月 1 号 0 点
 *     0 0 9 * * 1-5      工作日 9 点
 *     0 30 2 * * 0       每周日 2:30
 *
 * ## 为什么不做「计算下次时间」
 *
 * 「下次触发是几点」要处理闰年、月末、以及 cron 那条反直觉的「日 和 周 是或关系」。
 * 算错很难发现（可能一年才暴露一次）。而「当前这一秒是否匹配」是个纯判断：
 * 拿 now 的六个字段分别查表，边界清晰、好测。
 *
 * 代价是每秒都要判一次。但每个任务是几次位运算，1 秒一次完全不是瓶颈。
 *
 * ## 日月或关系
 *
 * 标准 cron：`0 0 1 * 1` 表示「每月 1 号**或**每周一」，不是且。
 * 但如果其中一个字段是 `*`，就只看另一个（否则 `0 0 * * 1` 会变成"每周一"意外地正确，
 * 因为 dom=* 时每个日子都匹配 —— 逻辑上成立，但显式判掉更清楚）。
 */
final class Cron
{
    /** @var array<string, array<string, int>> expr => 解析结果缓存 */
    private static array $cache = [];

    /**
     * 解析表达式；非法返回空数组。
     *
     * @return array<string, int> 六个 mask + 两个 star 标志
     */
    public static function parse(string $expr): array
    {
        if (isset(self::$cache[$expr])) {
            return self::$cache[$expr];
        }

        $parts = preg_split("/\s+/", trim($expr));
        if (!is_array($parts)) {
            return [];
        }
        $parts = array_values(array_filter($parts, function ($p): bool {
            return is_string($p) && $p !== "";
        }));
        $n = count($parts);
        if ($n === 5) {
            array_unshift($parts, "0");     // 5 段：秒默认 0
        } elseif ($n !== 6) {
            return [];
        }

        $sec = self::field($parts[0], 0, 59);
        $min = self::field($parts[1], 0, 59);
        $hour = self::field($parts[2], 0, 23);
        $dom = self::field($parts[3], 1, 31);
        $mon = self::field($parts[4], 1, 12);
        $dow = self::field($parts[5], 0, 7);

        if ($sec < 0 || $min < 0 || $hour < 0 || $dom < 0 || $mon < 0 || $dow < 0) {
            return [];
        }

        // cron 里 0 和 7 都表示周日：把 7 折到 0
        // cron 里 0 和 7 都表示周日：把 7 折到 0
        if (($dow & (1 << 7)) !== 0) {
            $dow = ($dow & ~(1 << 7)) | 1;
        }

        $out = [
            "sec" => $sec,
            "min" => $min,
            "hour" => $hour,
            "dom" => $dom,
            "mon" => $mon,
            "dow" => $dow,
            "domStar" => trim($parts[3]) === "*" ? 1 : 0,
            "dowStar" => trim($parts[5]) === "*" ? 1 : 0,
        ];
        self::$cache[$expr] = $out;
        return $out;
    }

    /** 当前时间戳是否匹配。$ts 精确到秒 */
    public static function matches(string $expr, int $ts): bool
    {
        $f = self::parse($expr);
        if ($f === []) {
            return false;
        }

        $sec = (int) date("s", $ts);
        $min = (int) date("i", $ts);
        $hour = (int) date("G", $ts);
        $dom = (int) date("j", $ts);
        $mon = (int) date("n", $ts);
        $dow = (int) date("w", $ts);    // 0=周日 .. 6=周六

        if (($f["sec"] & (1 << $sec)) === 0) {
            return false;
        }
        if (($f["min"] & (1 << $min)) === 0) {
            return false;
        }
        if (($f["hour"] & (1 << $hour)) === 0) {
            return false;
        }
        if (($f["mon"] & (1 << $mon)) === 0) {
            return false;
        }

        $domOk = ($f["dom"] & (1 << $dom)) !== 0;
        $dowOk = ($f["dow"] & (1 << $dow)) !== 0;

        if ($f["domStar"] === 1 && $f["dowStar"] === 1) {
            return true;
        }
        if ($f["domStar"] === 1) {
            return $dowOk;
        }
        if ($f["dowStar"] === 1) {
            return $domOk;
        }
        // 两个都指定：或关系（cron 标准）
        return $domOk || $dowOk;
    }

    /**
     * 一个字段 → 位图。返回 -1 表示非法。
     *
     * 支持：`*`、`5`、`1-5`、`* /5`、`0-30/5`、`1,3,5`（以及组合）
     */
    private static function field(string $s, int $min, int $max): int
    {
        $s = trim($s);
        if ($s === "") {
            return -1;
        }
        $mask = 0;
        $items = explode(",", $s);
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === "") {
                return -1;
            }

            $step = 1;
            $hasStep = false;
            $slash = strpos($item, "/");
            if ($slash !== false) {
                $stepStr = substr($item, $slash + 1);
                $item = substr($item, 0, $slash);
                $step = (int) $stepStr;
                $hasStep = true;
                if ($step <= 0) {
                    return -1;
                }
            }

            if ($item === "*") {
                $lo = $min;
                $hi = $max;
            } else {
                $dash = strpos($item, "-");
                if ($dash !== false) {
                    $lo = (int) substr($item, 0, $dash);
                    $hi = (int) substr($item, $dash + 1);
                } else {
                    if (!ctype_digit($item)) {
                        return -1;
                    }
                    $v = (int) $item;
                    $lo = $v;
                    // 「0/5」这种单值带步进，语义是「从 0 一直走到上界，每 5 个一次」
                    // （不是只有 0）。少了这一步会让「每 5 秒」变成「只在第 0 秒」。
                    $hi = $hasStep ? $max : $v;
                }
            }

            if ($lo < $min || $hi > $max || $lo > $hi) {
                return -1;
            }

            $i = $lo;
            while ($i <= $hi) {
                $mask |= (1 << $i);
                $i += $step;
            }
        }
        return $mask;
    }

    /** 给 GM/调试用：把表达式翻译成人话 */
    public static function describe(string $expr): string
    {
        $f = self::parse($expr);
        if ($f === []) {
            return "非法表达式";
        }
        $parts = preg_split("/\s+/", trim($expr));
        if (!is_array($parts)) {
            return $expr;
        }
        if (count($parts) === 5) {
            return trim($expr) . "（5 段：秒=0）";
        }
        return trim($expr);
    }
}
