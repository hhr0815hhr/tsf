<?php

namespace TSF\EventLog;

/**
 * 日志折叠后的状态检查点。
 *
 * ## 它解决的是"启动时间随日志长度线性增长"
 *
 * 只有事件日志时，恢复 = 从头重放全部事件。跑三个月的账本可能是几百万条，
 * 启动要几十秒甚至几分钟，而且**每次都重算一遍已经确定的历史**。
 *
 * 检查点的做法：每折叠 N 条就把"折叠后的状态"和"折到哪一条"一起落盘。
 * 恢复时不再从头重放，而是「读检查点 + 重放它之后的尾巴」。
 *
 * ```
 *   事件日志:  [1 ......................... 100000 ]
 *   检查点:                         ^ 80000（state 已经包含 1..80000 的效果）
 *   恢复:            读 80000 的 state → 重放 80001..100000
 * ```
 *
 * ## 顺序不能反：**先写检查点，再截断日志**
 *
 * 这两步配合使用才有意义（`saveAndPrune()` 把顺序固化在 API 里）：
 *
 * | 顺序 | 崩在中间会怎样 |
 * |:--|:--|
 * | 先检查点后截断 | 两份都在 → 安全（重放会跳过已折进检查点的部分） |
 * | 先截断后检查点 | 唯一的旧状态副本被删了 → **永久丢数据** |
 *
 * 所以 `truncate()` 单独用是危险的：它只在"确认检查点已经落盘"之后才该调用。
 *
 * ## 写入是原子的
 *
 * 临时文件 + `rename()`。`rename` 在同一文件系统内是原子的，所以进程在任意时刻被杀，
 * 磁盘上要么是旧检查点、要么是新检查点，不会出现半截文件。
 *
 * 另外带 crc32 校验：检查点被外部工具改坏（手工编辑、磁盘位翻转）时**拒绝加载**，
 * 让调用方退回"从头重放"——慢一点但正确。**读一个坏检查点比不读更危险**：
 * 它会把错误的余额当成起点，而日志里对不上的部分已经被截掉了。
 */
final class Checkpoint
{
    /** 文件头，用来一眼认出这不是普通 JSON 文件 */
    public const MAGIC = "TSFCKPT1";

    /** 增量文件头（与基座区分：加载时一眼能看出这是差量而不是全量） */
    public const MAGIC_DELTA = "TSFCKPD1";

    private string $path;

    /** 上次成功加载/保存的序号；0 表示没有可用检查点 */
    private int $seq = 0;

    /** @var array<string, int|string|float|bool> */
    private array $state = [];

    /** 上次加载失败的原因（排障用）；成功时为空串 */
    private string $reason = "";

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if ($dir !== "" && !is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("checkpoint: 无法创建目录 [" . $dir . "]");
            }
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * 写检查点。返回 false 表示写失败（磁盘满/权限），调用方**不要**接着截断日志。
     *
     * @param array<string, int|string|float|bool> $state 折叠到 $seq 之后的状态
     */
    public function save(int $seq, array $state): bool
    {
        if ($seq < 0) {
            return false;
        }
        $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return false;
        }
        $doc = self::MAGIC . " " . $seq . " " . hash("crc32b", $payload) . "\n" . $payload;
        $tmp = $this->path . ".tmp";
        if (file_put_contents($tmp, $doc) !== strlen($doc)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }
        if (!rename($tmp, $this->path)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }
        $this->seq = $seq;
        $this->state = $state;
        $this->reason = "";
        return true;
    }

    /**
     * 加载检查点。返回 false 表示没有可用的检查点（不存在 / 坏掉 / 校验不过），
     * 调用方应退回"从头重放"。
     */
    public function load(): bool
    {
        $this->seq = 0;
        $this->state = [];
        $this->reason = "";

        if (!is_file($this->path)) {
            $this->reason = "not-found";
            return false;
        }
        $raw = file_get_contents($this->path);
        if (!is_string($raw) || $raw === "") {
            $this->reason = "empty";
            return false;
        }
        $nl = strpos($raw, "\n");
        if ($nl === false) {
            $this->reason = "no-header";
            return false;
        }
        $header = substr($raw, 0, $nl);
        $body = substr($raw, $nl + 1);

        $parts = explode(" ", $header);
        if (count($parts) !== 3 || $parts[0] !== self::MAGIC) {
            $this->reason = "bad-header";
            return false;
        }
        $seq = (int) $parts[1];
        $crc = $parts[2];
        if ($seq < 0) {
            $this->reason = "bad-seq";
            return false;
        }
        if (hash("crc32b", $body) !== $crc) {
            // 内容被动过。宁可从头重放，也不要把错的余额当起点
            $this->reason = "crc-mismatch";
            return false;
        }
        $d = json_decode($body, true);
        if (!is_array($d)) {
            $this->reason = "bad-json";
            return false;
        }
        $clean = [];
        foreach ($d as $k => $v) {
            if (is_int($v) || is_string($v) || is_float($v) || is_bool($v)) {
                $clean[(string) $k] = $v;
            }
        }
        $this->seq = $seq;
        $this->state = $clean;

        // 再叠增量。增量的存在是常态（每次检查点只写变化的部分），
        // 所以这一步失败必须整体判失败：只加载基座会得到一个"半旧的"状态，
        // 比完全加载不出来更危险
        $deltas = $this->deltaFiles();
        foreach ($deltas as $path) {
            $d = $this->readDelta($path);
            if ($d === null) {
                $this->reason = "bad-delta:" . basename($path);
                $this->seq = 0;
                $this->state = [];
                return false;
            }
            foreach ($d["set"] as $k => $v) {
                $this->state[(string) $k] = $v;
            }
            foreach ($d["del"] as $k) {
                unset($this->state[$k]);
            }
            if ($d["seq"] > $this->seq) {
                $this->seq = $d["seq"];
            }
        }
        return true;
    }

    /**
     * 写增量：只记录"变了什么"。
     *
     * 为什么需要它：全量检查点的写入量与状态大小成正比，而状态里有余额表
     * （随账号数增长）和去重表（随事件数增长）。每 1 万条事件写一次几十 MB 的全量，
     * 是从"能跑"变成"跑久了会痛"的那一步。
     *
     * 增量只写自上次检查点以来变化的键。代价是恢复时要按序叠加，所以
     * **增量必须能排序**：文件名里带序号，加载时按升序应用。
     *
     * @param array<string, int|string|float|bool> $set 新增/修改的键
     * @param array<int, string>                  $del 删除的键
     */
    public function saveDelta(int $seq, array $set, array $del): bool
    {
        if ($seq < 0) {
            return false;
        }
        $body = json_encode(
            ["seq" => $seq, "set" => $set, "del" => array_values($del)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($body)) {
            return false;
        }
        $doc = self::MAGIC_DELTA . " " . $seq . " " . hash("crc32b", $body) . "\n" . $body;
        $path = $this->deltaPath($seq);
        $tmp = $path . ".tmp";
        if (file_put_contents($tmp, $doc) !== strlen($doc)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }
        if (!rename($tmp, $path)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }
        return true;
    }

    /**
     * 压实：把当前完整状态写成新的基座，并删掉所有增量。
     *
     * 顺序必须是**先写基座、再删增量**：反过来的话，崩在中间就等于把状态删了，
     * 而增量只是差量、重建不出来。
     *
     * @param array<string, int|string|float|bool> $state
     */
    public function compact(int $seq, array $state): bool
    {
        if (!$this->save($seq, $state)) {
            return false;
        }
        foreach ($this->deltaFiles() as $path) {
            // 删不掉不算失败：下次压实还会再试，而且增量叠加是幂等的
            if (is_file($path)) {
                unlink($path);
            }
        }
        return true;
    }

    /** 当前有多少个增量文件（观测：应该在 compactEvery 附近，不该无限增长） */
    public function deltaCount(): int
    {
        return count($this->deltaFiles());
    }

    /**
     * 写增量 + 截断日志，顺序同样固化在这里（先增量、后截断）。
     *
     * @param array<string, int|string|float|bool> $set
     * @param array<int, string>                  $del
     *
     * @return int 截断后保留的条数；-1 表示拒绝执行
     */
    public function saveDeltaAndPrune(EventLog $log, int $seq, array $set, array $del): int
    {
        if ($seq <= 0 || $seq > $log->lastSeq()) {
            return -1;
        }
        if (!$this->saveDelta($seq, $set, $del)) {
            return -1;
        }
        return $log->truncate($seq);
    }

    /** @return array<int, string> 按序号升序的增量文件 */
    private function deltaFiles(): array
    {
        $files = glob($this->path . ".d*");
        if (!is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            $name = (string) $f;
            if (str_ends_with($name, ".tmp")) {
                continue;       // 写了一半的增量不是有效记录
            }
            $out[] = $name;
        }
        // 文件名里带的是十进制序号，但字典序会把 d100 排在 d9 前面，
        // 所以按解析出来的整数排。这里用 ksort 而不是 usort+闭包：
        // 闭包捕获 $this 在 AOT 下不可靠
        $map = [];
        foreach ($out as $f) {
            $map[$this->seqOfDelta($f)] = $f;
        }
        ksort($map);
        return array_values($map);
    }

    private function deltaPath(int $seq): string
    {
        return $this->path . ".d" . $seq;
    }

    private function seqOfDelta(string $path): int
    {
        $base = basename($path);
        $pos = strrpos($base, ".d");
        if ($pos === false) {
            return 0;
        }
        $num = substr($base, $pos + 2);
        return preg_match('/^\d+$/', $num) === 1 ? (int) $num : 0;
    }

    /** @return array{seq: int, set: array<string, int|string|float|bool>, del: array<int, string>}|null */
    private function readDelta(string $path): ?array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === "") {
            return null;
        }
        $nl = strpos($raw, "\n");
        if ($nl === false) {
            return null;
        }
        $header = substr($raw, 0, $nl);
        $body = substr($raw, $nl + 1);
        $parts = explode(" ", $header);
        if (count($parts) !== 3 || $parts[0] !== self::MAGIC_DELTA) {
            return null;
        }
        if (hash("crc32b", $body) !== $parts[2]) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d)) {
            return null;
        }
        $set = [];
        if (isset($d["set"]) && is_array($d["set"])) {
            foreach ($d["set"] as $k => $v) {
                if (is_int($v) || is_string($v) || is_float($v) || is_bool($v)) {
                    $set[(string) $k] = $v;
                }
            }
        }
        $del = [];
        if (isset($d["del"]) && is_array($d["del"])) {
            foreach ($d["del"] as $k) {
                if (is_string($k) && $k !== "") {
                    $del[] = $k;
                }
            }
        }
        return ["seq" => (int) $parts[1], "set" => $set, "del" => $del];
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** 上次加载/保存的序号；0 表示没有可用检查点 */
    public function seq(): int
    {
        return $this->seq;
    }

    /** @return array<string, int|string|float|bool> */
    public function state(): array
    {
        return $this->state;
    }

    /** 上次加载失败原因（`not-found` / `crc-mismatch` / …）；成功时为空串 */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * 检查点 + 截断日志，**顺序固化在这里**。
     *
     * 先写检查点，再截断。反过来的话，进程崩在两步之间时唯一的旧状态副本已经没了，
     * 日志里那段历史又被删掉——这是不可恢复的数据丢失。
     *
     * @param array<string, int|string|float|bool> $state
     *
     * @return int 截断后保留的条数；-1 表示拒绝执行（检查点写失败或序号越界）
     */
    public function saveAndPrune(EventLog $log, int $seq, array $state): int
    {
        if ($seq <= 0 || $seq > $log->lastSeq()) {
            // 折到"日志里还不存在的序号"说明调用方算错了，截断会把没处理的事件删掉
            return -1;
        }
        if (!$this->save($seq, $state)) {
            return -1;
        }
        return $log->truncate($seq);
    }
}
