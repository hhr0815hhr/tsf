<?php

namespace TSF\Kernel;

/**
 * 一个日志文件的落盘 + 按大小轮转。
 *
 * ## 为什么需要
 *
 * `Logger` 原来是一行一句 `file_put_contents(FILE_APPEND)`，没有任何上限。
 * 一个 info 级别的循环日志（每帧一行、每条消息一行）就能在几小时内把磁盘写满；
 * 写满之后 `@file_put_contents` 静默失败——**所有日志同时消失**，而且没有任何报错。
 * 容器里则是 ephemeral storage 超限被驱逐。
 *
 * ## 轮转规则
 *
 * 当前文件是 `$file`；超过 `maxBytes` 时把 `app.log` 改名成 `app.log.1`，
 * 原来的 `.1` 变 `.2`……最老的 `.{maxFiles}` 删掉，然后从空文件继续写。
 * 磁盘占用上限 = (maxFiles + 1) × maxBytes，再松一点余量（见下面的检查间隔）。
 *
 * ## 多进程安全
 *
 * 一个进程树里 master、多个 worker 都往同一个文件追加。轮转靠 `rename`，本身是原子的；
 * 危险的是两个进程**同时**判定"该转了"：第二个会把第一个刚建好的空文件再转一次，
 * 白白多出一层几乎空的 `.1`、还把最老的一层挤掉了。所以判定和改名整体放在
 * `$file.lock` 的排它锁里，拿到锁之后再查一次大小，别人已经转过了就直接返回。
 *
 * 每行写入**不**加锁：追加写靠 `O_APPEND` 就是原子的（小于 PIPE_BUF / 一页的行），
 * 加锁只会把日志变成串行瓶颈。
 *
 * ## 检查间隔
 *
 * 每写一行就 `filesize()` 太贵（一次 stat 约 1 µs，比写本身还贵）。按"已经写了多少字节"
 * 计数，攒够一个间隔才去查真实大小。间隔取 `maxBytes / 16`（下限 1 KB）：
 * 64 MB 的文件每写 4 MB 查一次，文件最大会超出上限约 1/16；上限很小时间隔也跟着变小，
 * 不会出现"上限 2 KB、却要写满 64 KB 才发现"的荒唐情况。
 *
 * 只按大小、不按日期：按天切分在跨进程时需要各进程对"今天是哪天"达成一致，
 * 午夜附近的错层很难验证；大小切分是确定性的，采集端（filebeat/vector）本来也不关心文件名。
 */
final class LogFile
{
    private string $file;

    private int $maxBytes;

    private int $maxFiles;

    /** 两次大小检查之间最多写多少字节 */
    private int $checkEvery;

    /** 距上次检查以来写了多少 */
    private int $since = 0;

    /** 轮转了几次（给自省/测试用） */
    private int $rotations = 0;

    public function __construct(string $file, int $maxBytes = 0, int $maxFiles = 5)
    {
        $this->file = $file;
        $this->maxBytes = $maxBytes > 0 ? $maxBytes : 0;
        $this->maxFiles = $maxFiles > 0 ? $maxFiles : 1;
        $every = intdiv($this->maxBytes, 16);
        if ($every < 1024) {
            $every = 1024;
        }
        $this->checkEvery = $every;
    }

    /** 重新设置上限。只在初始化时调，运行期改了也不影响已经写出去的 */
    public function setRotation(int $maxBytes, int $maxFiles): void
    {
        $this->maxBytes = $maxBytes > 0 ? $maxBytes : 0;
        $this->maxFiles = $maxFiles > 0 ? $maxFiles : 1;
        $every = intdiv($this->maxBytes, 16);
        if ($every < 1024) {
            $every = 1024;
        }
        $this->checkEvery = $every;
    }

    public function path(): string
    {
        return $this->file;
    }

    public function rotations(): int
    {
        return $this->rotations;
    }

    /** 追加一行。文件名是空串时什么也不做（纯 stdout 部署） */
    public function write(string $line): void
    {
        if ($this->file === "") {
            return;
        }
        $file = $this->file;
        @file_put_contents($file, $line, FILE_APPEND);
        if ($this->maxBytes === 0) {
            return;
        }
        $this->since += strlen($line);
        if ($this->since < $this->checkEvery) {
            return;
        }
        $this->since = 0;
        $file = $this->file;
        clearstatcache(true, $file);
        $size = @filesize($file);
        if (is_int($size) && $size >= $this->maxBytes) {
            $this->rotate();
        }
    }

    /**
     * 立刻检查并（必要时）轮转。给"写了一大段之后想马上切"的调用方用，正常不需要。
     */
    public function rotateIfDue(): void
    {
        if ($this->file === "" || $this->maxBytes === 0) {
            return;
        }
        $file = $this->file;
        clearstatcache(true, $file);
        $size = @filesize($file);
        if (is_int($size) && $size >= $this->maxBytes) {
            $this->rotate();
        }
    }

    private function rotate(): void
    {
        // 整个方法里只取一次：内置函数不直接吃属性访问（AOT 的约定）
        $file = $this->file;
        $lock = @fopen($file . ".lock", "c");
        if ($lock === false) {
            return;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return;
        }
        // 拿到锁之后重查：这期间别的进程可能已经转过了
        clearstatcache(true, $file);
        $size = @filesize($file);
        if (is_int($size) && $size >= $this->maxBytes) {
            // 第 i 层接收第 i-1 层的内容；i == 1 时"上一层"就是当前文件
            $i = $this->maxFiles;
            while ($i >= 1) {
                $dst = $file . "." . $i;
                $src = $i === 1 ? $file : $file . "." . ($i - 1);
                if ($i === $this->maxFiles && is_file($dst)) {
                    @unlink($dst);
                }
                if (is_file($src)) {
                    @rename($src, $dst);
                }
                $i--;
            }
            $this->rotations++;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
