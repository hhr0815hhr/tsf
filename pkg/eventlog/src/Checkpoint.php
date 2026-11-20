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
        return true;
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
