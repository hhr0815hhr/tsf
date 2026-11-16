<?php

namespace TSF\Snapshot;

/**
 * 快照的编解码：**JSON + 头部版本号 + 哈希**。
 *
 * 用 JSON 而不是 serialize()：快照可能要跨版本、跨语言、落库、
 * 甚至被运维肉眼检查。`serialize` 的格式绑死 PHP 版本和类结构，
 * 一次重构就让所有旧快照变成废纸。
 *
 * ## 哈希为什么用 crc32
 *
 * 快照校验只需要"发现不一致"，不需要抗攻击。crc32 比 md5 快一个数量级，
 * 而这里每帧/每秒都可能算一次。真正的存档完整性另有机制（校验和/备份），
 * 不指望一个哈希。
 */
final class Codec
{
    /** 当前格式版本。改了 body 的结构就 +1 */
    public const VERSION = 1;

    /** 上一次 decode 失败的原因（排障用；成功时为空串） */
    private static string $lastError = "";

    /**
     * 编码成一行 JSON。字段名极短是刻意的——快照会被高频读写，
     * 而 `{"v":1,"k":"room",...}` 比 `{"version":1,"kind":"room",...}`
     * 在 10 万次落盘里差着几十 MB。
     *
     * **输出是确定性的**：只要 body 的构造顺序一致，字节就一致。
     * 这是"冷哈希一致"验收的前提。
     */
    public static function encode(Snapshot $s): string
    {
        $out = json_encode([
            "v" => $s->version,
            "k" => $s->kind,
            "id" => $s->key,
            "t" => $s->atMs,
            "b" => $s->body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($out)) {
            throw new \RuntimeException("snapshot: 编码失败（" . json_last_error_msg() . "）");
        }
        return $out;
    }

    /**
     * 解码。**任何异常都返回 null**，不抛。
     *
     * 理由：快照损坏/版本不符是"数据问题"，不是"程序 bug"。调用方的正确反应是
     * 丢掉这份快照重新开始，而不是让整个进程崩掉。要区分原因时用 `why()`。
     */
    public static function decode(string $json): ?Snapshot
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            self::$lastError = "不是合法 JSON";
            return null;
        }
        if (!isset($d["v"]) || !is_int($d["v"])) {
            self::$lastError = "缺少版本号";
            return null;
        }
        $v = (int) $d["v"];
        if ($v !== self::VERSION) {
            self::$lastError = "版本不符：快照 v" . $v . "，当前 v" . self::VERSION;
            return null;
        }
        if (!isset($d["k"]) || !is_string($d["k"])) {
            self::$lastError = "缺少 kind";
            return null;
        }
        if (!isset($d["id"]) || !is_int($d["id"])) {
            self::$lastError = "缺少 key";
            return null;
        }

        $s = new Snapshot((string) $d["k"], (int) $d["id"], $v);
        if (isset($d["t"]) && is_int($d["t"])) {
            $s->atMs = (int) $d["t"];
        }
        if (isset($d["b"]) && is_array($d["b"])) {
            $body = [];
            foreach ($d["b"] as $name => $block) {
                if (is_array($block)) {
                    $body[(string) $name] = $block;
                }
            }
            $s->body = $body;
        }
        self::$lastError = "";
        return $s;
    }

    /** 上一次 decode 失败的原因（排障用；成功时为空串） */
    public static function why(): string
    {
        return self::$lastError;
    }

    /**
     * 整份字节的哈希（含采集时间）。用于"这份文件有没有被改过"。
     */
    public static function hash(string $json): string
    {
        return hash("crc32b", $json);
    }

    /**
     * **只对状态算哈希**，不含采集时间与元信息。
     *
     * 这是"恢复前后状态一致"的正确口径：同一份状态在不同时刻采集，
     * 整份字节必然不同（`atMs` 变了），但状态哈希必须相同。
     */
    public static function bodyHash(Snapshot $s): string
    {
        $out = json_encode($s->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($out)) {
            return "";
        }
        return hash("crc32b", $out);
    }
}
