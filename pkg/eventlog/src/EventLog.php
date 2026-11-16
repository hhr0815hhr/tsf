<?php

namespace TSF\EventLog;

/**
 * 追加写的事件日志。一行一条，JSON Lines。
 *
 * ## 它保的是什么
 *
 * 快照保"房间还活着"，事件日志保**"发生过的事没丢"**。两者互补：
 * 房间状态丢了可以重开一局，交易记录丢一笔就是数据事故。
 *
 * ## 持久性有两档，差别是 3500 倍
 *
 * 实测（`bench/probe-file`）：
 *
 * | 写法 | 成本 | 抗什么 |
 * |:--|--:|:--|
 * | 持久句柄 + `fflush` | **1.2 µs/条** | **进程崩溃（kill -9）** |
 * | 持久句柄 + `fsync` | **4284 µs/条** | 机器掉电 |
 *
 * 这个区分极其重要，很多人混为一谈：
 *
 * - `kill -9` 只杀进程，`fflush` 过的数据已经在**内核页缓存**里，内核会照常写盘；
 * - 只有**机器掉电/内核崩溃**才会丢页缓存，那才需要 `fsync`。
 *
 * 所以默认 `MODE_FLUSH`：1.2 µs 一条，扛得住 kill -9。
 * 真需要抗掉电的场景（比如支付）用 `MODE_SYNC`，并且**必须批量提交**——
 * 每条都 fsync 的话 1000 笔要 4.3 秒，等于把服务变成串行磁盘。
 *
 * ## 崩溃时最后一行可能是半截
 *
 * 写入不是原子的，进程在 `fwrite` 中途被杀会留下半行。恢复时按 `\n` 切分、
 * 逐行解析，**遇到第一个坏行就停下**——后面的行不可信（可能是同一块缓冲的残余）。
 * `repair()` 能把文件截到最后一条完整记录。
 *
 * ## 幂等重放
 *
 * 每条事件带一个业务唯一 `id`。重放时"同一 id 出现两次"由业务侧决定怎么处理
 * （通常是唯一键约束或一张已处理表），这里只保证"你可以安全地重放任意多次"——
 * 因为日志是只追加的，重放不会改变日志本身。
 *
 * ## 只支持**单写入进程**
 *
 * `nextSeq` 是在**构造时**扫一遍文件定出来的（见 `scan()`），`append()` 之后直接自增。
 * 两个进程各持一份 `EventLog` 写同一个文件，就会各自从同一个序号往下发。
 *
 * 实测：两个进程各追加 60 条 → 文件里 120 条记录、**只有 61 个不同序号**（59 个重复）。
 * 行是完整的（`fwrite` 一条不会撕裂），`id` 也唯一，所以：
 *
 * - 幂等**不受影响**——钱不会多发；
 * - 但 `replay($fromSeq)` 与 `truncate($uptoSeq)` **以 seq 为坐标**，一撞就会漏读/重读，
 *   "截到 N 号"也会切错位置。归档与 checkpoint 全建立在这上面。
 *
 * 多进程要写同一类事实时，按 worker 分文件（`settle.w0.log`）再在读取侧合并，
 * 不要指望加锁解决：`flock` 不在 AOT 的 std 表面里，硬加会把 2.6 µs/条 变成系统调用级别的成本。
 *
 * 应用层应该在启动时断言（参考 `demo/duel/main.php`：`worker_num > 1` 直接抛）。
 */
final class EventLog
{
    /** 写进内核页缓存：抗进程崩溃，1.2 µs/条 */
    public const MODE_FLUSH = 1;

    /** 落盘：抗机器掉电，4.3 ms/条（实测），必须批量提交 */
    public const MODE_SYNC = 2;

    private string $path;

    private int $mode;

    /** @var resource|null 无类型：PHP 不能把 resource 写成类型声明 */
    private $fh = null;

    /** 下一条事件的序号（从 1 开始，单调递增） */
    private int $nextSeq = 1;

    /** 已写入但还没 commit 的条数 */
    private int $pending = 0;

    private int $appended = 0;

    /** 重放时最后一个有效行的序号；0 表示文件为空或全坏 */
    private int $lastGoodSeq = 0;

    /** 重放时遇到的坏行数（>0 说明上次是崩溃退出，需要 repair） */
    private int $badLines = 0;

    private int $commits = 0;

    public function __construct(string $path, int $mode = self::MODE_FLUSH)
    {
        $this->path = $path;
        $this->mode = $mode === self::MODE_SYNC ? self::MODE_SYNC : self::MODE_FLUSH;

        $dir = dirname($path);
        if ($dir !== "" && !is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("eventlog: 无法创建目录 [" . $dir . "]");
            }
        }

        // 扫描已有内容来确定 nextSeq —— 同时暴露坏行
        $this->scan();
    }

    /**
     * 追加一条。返回它的序号。
     *
     * 序号在**本次进程内**单调递增，且与文件里已有的序号接续。
     * 崩溃重启后会从最后一个有效序号 +1 继续——中间不会重号。
     */
    public function append(string $id, string $kind, array $data): int
    {
        $seq = $this->nextSeq;
        $this->nextSeq++;

        $line = json_encode([
            "s" => $seq,
            "id" => $id,
            "k" => $kind,
            "t" => (int) (microtime(true) * 1000),
            "d" => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($line)) {
            throw new \RuntimeException("eventlog: 编码失败（" . json_last_error_msg() . "）");
        }

        $fh = $this->handle();
        $n = fwrite($fh, $line . "\n");
        if ($n === false || $n !== strlen($line) + 1) {
            // 写失败不回滚 seq：宁可留个洞，也不要让两条事件共用一个序号
            throw new \RuntimeException("eventlog: 写入失败（" . $this->path . "）");
        }

        // 刷出 PHP 的用户态缓冲，进内核页缓存。
        // 这一步就是"抗 kill -9"的保证，成本可忽略
        fflush($fh);

        $this->pending++;
        $this->appended++;
        // 刚写进去的这条就是最新的有效记录。不更新的话 lastSeq() 会一直返回
        // 构造时的值，调用方拿它做 checkpoint 就会漏掉本次进程写的所有事件
        $this->lastGoodSeq = $seq;

        if ($this->mode === self::MODE_SYNC) {
            $this->commit();
        }
        return $seq;
    }

    /**
     * 落盘。只有 `MODE_SYNC` 需要显式调用。
     *
     * 批量提交的用法：
     *
     * ```php
     * foreach ($trades as $t) { $log->append(...); }
     * $log->commit();      // 一次 fsync 覆盖这一批
     * ```
     */
    public function commit(): bool
    {
        if ($this->pending === 0) {
            return true;
        }
        $fh = $this->handle();
        fflush($fh);
        $ok = true;
        if (function_exists("fsync")) {
            $ok = fsync($fh);
        }
        $this->pending = 0;
        $this->commits++;
        return $ok;
    }

    /**
     * 从头（或从某序号之后）读所有事件。
     *
     * @param int $fromSeq 只返回 seq >= 这个值的事件（1 表示全部）
     * @param int $limit   0 表示不限
     *
     * @return array<int, array{s: int, id: string, k: string, t: int, d: array}>
     */
    public function replay(int $fromSeq = 1, int $limit = 0): array
    {
        // 文件不存在是很正常的状态（新实例、刚被 truncate 清空），
        // 但 file_get_contents 会为此发一条 warning——不该让"还没有日志"
        // 变成一条看起来像故障的日志
        if (!is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if (!is_string($raw) || $raw === "") {
            return [];
        }

        $out = [];
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if ($line === "") {
                continue;
            }
            $d = json_decode($line, true);
            if (!is_array($d) || !isset($d["s"]) || !is_int($d["s"])) {
                // 第一个坏行之后的内容不再采信：可能是一块没写完的缓冲
                break;
            }
            $seq = (int) $d["s"];
            if ($seq < $fromSeq) {
                continue;
            }
            $out[] = [
                "s" => $seq,
                "id" => isset($d["id"]) ? (string) $d["id"] : "",
                "k" => isset($d["k"]) ? (string) $d["k"] : "",
                "t" => isset($d["t"]) ? (int) $d["t"] : 0,
                "d" => isset($d["d"]) && is_array($d["d"]) ? $d["d"] : [],
            ];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * 把文件截到最后一条完整记录，返回丢弃的字节数。
     *
     * 崩溃后重放会停在坏行，但文件本身还留着半截行——它会让**下一次**追加
     * 接在半行后面，变成一条新的坏行。所以修复是必须的，不是可选优化。
     */
    public function repair(): int
    {
        if (!is_file($this->path)) {
            return 0;
        }
        $raw = file_get_contents($this->path);
        if (!is_string($raw) || $raw === "") {
            return 0;
        }

        $goodLen = 0;
        $pos = 0;
        $total = strlen($raw);
        while ($pos < $total) {
            $nl = strpos($raw, "\n", $pos);
            if ($nl === false) {
                break; // 末尾没有换行：这行不完整
            }
            $line = substr($raw, $pos, $nl - $pos);
            $d = json_decode($line, true);
            if (!is_array($d) || !isset($d["s"])) {
                break;
            }
            $pos = $nl + 1;
            $goodLen = $pos;
        }

        $dropped = $total - $goodLen;
        if ($dropped === 0) {
            return 0;
        }

        $this->close();
        $tmp = $this->path . ".repair";
        $keep = substr($raw, 0, $goodLen);
        file_put_contents($tmp, $keep);
        rename($tmp, $this->path);
        $this->scan();
        return $dropped;
    }

    /**
     * 截断：只保留 seq > `$uptoSeq` 的行。
     *
     * 这是"归档"的实现——老日志先拷到别处，再从这里删掉。返回保留的条数。
     *
     * 用临时文件 + rename，中途崩溃不会丢日志（要么旧文件，要么新文件）。
     */
    public function truncate(int $uptoSeq): int
    {
        $keep = [];
        foreach ($this->replay() as $e) {
            if ($e["s"] > $uptoSeq) {
                $keep[] = $e;
            }
        }

        $this->close();
        $tmp = $this->path . ".tmp";
        $buf = "";
        foreach ($keep as $e) {
            $line = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($line)) {
                $buf .= $line . "\n";
            }
        }
        file_put_contents($tmp, $buf);
        rename($tmp, $this->path);
        $this->scan();
        return count($keep);
    }

    public function lastSeq(): int
    {
        return $this->lastGoodSeq;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function mode(): int
    {
        return $this->mode;
    }

    public function stats(): array
    {
        // 文件可能还不存在（刚 new 出来、一条都没写），filesize() 会为此发 warning。
        // "还没有日志"是正常状态，不该看起来像故障
        $size = is_file($this->path) ? filesize($this->path) : false;
        return [
            "size" => is_int($size) ? $size : 0,
            "appended" => $this->appended,
            "commits" => $this->commits,
            "pending" => $this->pending,
            "next_seq" => $this->nextSeq,
            "last_good_seq" => $this->lastGoodSeq,
            "bad_lines" => $this->badLines,
        ];
    }

    /** 重放/修复时发现的坏行数。>0 说明上次是非正常退出 */
    public function badLines(): int
    {
        return $this->badLines;
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            fflush($this->fh);
            fclose($this->fh);
        }
        $this->fh = null;
        $this->pending = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function handle()
    {
        if (is_resource($this->fh)) {
            return $this->fh;
        }
        $fh = fopen($this->path, "ab");
        if ($fh === false) {
            throw new \RuntimeException("eventlog: 无法打开 [" . $this->path . "]");
        }
        $this->fh = $fh;
        return $fh;
    }

    /**
     * 扫一遍已有文件：确定 nextSeq、lastGoodSeq、坏行数。
     *
     * 全量扫描的代价随日志增长线性上升，所以日志要配合 `truncate()` 定期归档。
     * 真要跑百万级日志时应该改成"只读文件尾部找最后一个 seq"，
     * 但那需要保证每行定长或额外索引——当前规模不值得。
     */
    private function scan(): void
    {
        $this->lastGoodSeq = 0;
        $this->badLines = 0;

        if (!is_file($this->path)) {
            $this->nextSeq = 1;
            return;
        }

        $raw = file_get_contents($this->path);
        if (!is_string($raw) || $raw === "") {
            $this->nextSeq = $this->lastGoodSeq + 1;
            return;
        }

        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            if ($line === "") {
                continue;
            }
            $d = json_decode($line, true);
            if (!is_array($d) || !isset($d["s"]) || !is_int($d["s"])) {
                $this->badLines++;
                continue;
            }
            $seq = (int) $d["s"];
            if ($seq > $this->lastGoodSeq) {
                $this->lastGoodSeq = $seq;
            }
        }
        $this->nextSeq = $this->lastGoodSeq + 1;
    }
}
