<?php

namespace TSF\Snapshot;

/**
 * 文件存储：一个快照一个文件，**原子写**。
 *
 * ## 为什么要临时文件 + rename
 *
 * 直接 `file_put_contents($final, $json)` 在写的途中被 kill -9，留下的就是一个
 * 半截 JSON——下次启动读它必然解析失败，而且**看不出是"没写完"还是"数据坏了"**。
 *
 * `rename` 在同一文件系统内是原子的：要么看到旧文件，要么看到新文件，
 * 不存在"看到半个"的中间态。这是崩溃恢复的基本功。
 *
 * ## fsync 是可选但重要的
 *
 * `fwrite` 只是写进了内核页缓存，机器掉电时可能还没落盘。`fsync` 强制刷盘，
 * 代价是每次多几毫秒。`flush` 参数默认关闭（压测/开发不想付这个钱），
 * 生产环境开启。
 */
final class FileStore extends Store
{
    private string $dir;

    private bool $sync;

    private int $puts = 0;

    private int $errors = 0;

    /**
     * @param string $dir  存放目录，不存在会自动创建
     * @param bool   $sync 是否 fsync（抗掉电，每次多几毫秒）
     */
    public function __construct(string $dir, bool $sync = false)
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("snapshot: 无法创建目录 [" . $dir . "]");
            }
        }
        $this->dir = rtrim($dir, "/");
        $this->sync = $sync;
    }

    public function put(Snapshot $s): bool
    {
        $final = $this->path($s->kind, $s->key);
        $tmp = $final . ".tmp";
        $json = Codec::encode($s);

        $fh = fopen($tmp, "wb");
        if ($fh === false) {
            $this->errors++;
            return false;
        }
        $n = fwrite($fh, $json);
        fflush($fh);
        if ($this->sync) {
            // PHP 8.1+；老版本没有这个函数就退化成只 flush
            if (function_exists("fsync")) {
                fsync($fh);
            }
        }
        fclose($fh);

        if ($n !== strlen($json)) {
            $this->errors++;
            unlink($tmp);
            return false;
        }

        if (!rename($tmp, $final)) {
            $this->errors++;
            unlink($tmp);
            return false;
        }
        $this->puts++;
        return true;
    }

    public function get(string $kind, int $key): ?Snapshot
    {
        $p = $this->path($kind, $key);
        if (!is_file($p)) {
            return null;
        }
        $json = file_get_contents($p);
        if (!is_string($json) || $json === "") {
            return null;
        }
        return Codec::decode($json);
    }

    public function delete(string $kind, int $key): bool
    {
        $p = $this->path($kind, $key);
        if (!is_file($p)) {
            return false;
        }
        return unlink($p);
    }

    /** @return array<int, int> */
    public function keys(string $kind): array
    {
        $prefix = $this->safeKind($kind) . "_";
        $out = [];
        $names = scandir($this->dir);
        if (!is_array($names)) {
            return $out;
        }
        foreach ($names as $name) {
            $f = (string) $name;
            // 跳过临时文件：写一半的 .tmp 不是有效快照
            if (!str_ends_with($f, ".snap")) {
                continue;
            }
            if (!str_starts_with($f, $prefix)) {
                continue;
            }
            $mid = substr($f, strlen($prefix), -5);
            $out[] = (int) $mid;
        }
        return $out;
    }

    public function stats(): array
    {
        return ["puts" => $this->puts, "errors" => $this->errors, "dir" => $this->dir];
    }

    private function path(string $kind, int $key): string
    {
        return $this->dir . "/" . $this->safeKind($kind) . "_" . $key . ".snap";
    }

    /**
     * kind 会被拼进文件名，必须过滤——虽然它来自代码常量而不是用户输入，
     * 但"永远不会有脏输入"这种假设迟早出事（比如哪天 kind 改成从配置读）。
     */
    private function safeKind(string $kind): string
    {
        $out = "";
        $n = strlen($kind);
        for ($i = 0; $i < $n; $i++) {
            $c = $kind[$i];
            if (($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z") || ($c >= "0" && $c <= "9") || $c === "_" || $c === "-") {
                $out .= $c;
            }
        }
        if ($out === "") {
            return "x";
        }
        return $out;
    }
}
