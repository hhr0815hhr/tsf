<?php

namespace TSF\Match;

/**
 * 匹配队列：按评分配对，容差随等待时长放宽。
 *
 * **不依赖 Service / Scheduler**，可以单独跑单元测试——匹配算法是最容易写错的部分，
 * 绑在服务里测起来要付出消息往返的代价。
 *
 * 算法：
 *   1. 按评分升序排序（同分按入队时间，先来先配）；
 *   2. 线性扫描，为每个人找后面第一个「分差在容差内」的人，配掉双方；
 *   3. 容差 = base + 已等待秒数 × expand，所以等得久的人更容易配上。
 *
 * 为什么排序而不是两两比较：1000 人两两是 50 万次，排序后只要 O(n log n) 次比较
 * 加一遍线性扫描。内层还有个提前 break——分差超过「最大可能容差」就没必要再往后看，
 * 因为列表是升序的，后面只会更远。
 *
 * 容差是**每人独立**的（等得久的更宽松），但 break 判断用全局最大容差作上界，
 * 这样既公平又不会退化成 O(n²)。
 */
final class MatchQueue
{
    /** @var array<int, array{0:int, 1:int}> uid => [score, sinceMs] */
    private array $waiting = [];

    /** 基础容差：刚入队时能接受的分差 */
    private int $baseTolerance;

    /** 每秒放宽多少分 */
    private int $expandPerSec;

    private int $matchedPairs = 0;

    public function __construct(int $baseTolerance = 50, int $expandPerSec = 50)
    {
        $this->baseTolerance = $baseTolerance > 0 ? $baseTolerance : 0;
        $this->expandPerSec = $expandPerSec > 0 ? $expandPerSec : 0;
    }

    /**
     * 入队。已在队里返回 false（重复入队不该重置等待时间，
     * 否则玩家可以通过反复点"匹配"把自己变成新人，永远享受基础容差）。
     */
    public function add(int $uid, int $score, int $nowMs): bool
    {
        if (isset($this->waiting[$uid])) {
            return false;
        }
        $this->waiting[$uid] = [$score, $nowMs];
        return true;
    }

    public function remove(int $uid): bool
    {
        if (!isset($this->waiting[$uid])) {
            return false;
        }
        unset($this->waiting[$uid]);
        return true;
    }

    public function has(int $uid): bool
    {
        return isset($this->waiting[$uid]);
    }

    public function size(): int
    {
        return count($this->waiting);
    }

    /** 某人已等待多久（毫秒）；不在队里返回 -1 */
    public function waitedMs(int $uid, int $nowMs): int
    {
        if (!isset($this->waiting[$uid])) {
            return -1;
        }
        return $nowMs - $this->waiting[$uid][1];
    }

    /**
     * 尝试配对一轮。
     *
     * @return array<int, array{0:int, 1:int}> 配对成功的 [uidA, uidB] 列表
     */
    public function tryMatch(int $nowMs): array
    {
        $n = count($this->waiting);
        if ($n < 2) {
            return [];
        }

        // [score, sinceMs, uid]；uid 放最后，配掉后置 -1 做标记
        $list = [];
        $oldest = $nowMs;
        foreach ($this->waiting as $uid => $e) {
            $list[] = [$e[0], $e[1], $uid];
            if ($e[1] < $oldest) {
                $oldest = $e[1];
            }
        }

        // 升序；同分时先入队的排前面
        usort($list, function (array $a, array $b): int {
            if ($a[0] === $b[0]) {
                return $a[1] <=> $b[1];
            }
            return $a[0] <=> $b[0];
        });

        // 等得最久的人能达到的容差——任何人的容差都不会超过它，
        // 所以分差超过它就一定配不上，可以安全地提前结束内层扫描。
        $maxTol = $this->baseTolerance
            + intdiv($nowMs - $oldest, 1000) * $this->expandPerSec;

        $pairs = [];
        $i = 0;
        while ($i < $n - 1) {
            if ($list[$i][2] < 0) {
                $i++;
                continue;
            }

            $uidA = $list[$i][2];
            $scoreA = $list[$i][0];
            $tolA = $this->baseTolerance
                + intdiv($nowMs - $list[$i][1], 1000) * $this->expandPerSec;

            $hit = -1;
            $j = $i + 1;
            while ($j < $n) {
                if ($list[$j][2] >= 0) {
                    $diff = $list[$j][0] - $scoreA;
                    if ($diff > $maxTol) {
                        break;
                    }
                    $tolB = $this->baseTolerance
                        + intdiv($nowMs - $list[$j][1], 1000) * $this->expandPerSec;
                    $tol = $tolA > $tolB ? $tolA : $tolB;
                    if ($diff <= $tol) {
                        $hit = $j;
                        break;
                    }
                }
                $j++;
            }

            if ($hit >= 0) {
                $uidB = $list[$hit][2];
                $pairs[] = [$uidA, $uidB];
                $list[$i][2] = -1;
                $list[$hit][2] = -1;
                unset($this->waiting[$uidA], $this->waiting[$uidB]);
                $this->matchedPairs++;
            }
            $i++;
        }

        return $pairs;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            "waiting" => count($this->waiting),
            "pairs" => $this->matchedPairs,
        ];
    }
}
