<?php

namespace TSF\Kernel;

/**
 * 安全的文件加载器：先失效 opcache，再 include。
 *
 * ## 为什么必须有这个类
 *
 * AOT 二进制里的 `include` 会命中 opcache，而且**不会**因为文件 mtime 变化
 * 自动失效——即使 `opcache.validate_timestamps=On` 也一样。
 *
 * 症状极具误导性：文件监听逻辑完全正常（重载计数在涨、mtime 也变了），
 * 但读到的永远是旧值。实测把配置里的 `base` 从 30 改成 45、再改成 60，
 * 伤害值始终是旧公式的结果，直到显式调用 `opcache_invalidate()` 才生效。
 * 整个过程没有任何报错。
 *
 * 重启进程时 opcache 是空的，所以"改了就好"的直觉在冷启动下成立、
 * 在热更时失效——这也是这个坑容易被误判为"缓存没清干净"的原因。
 *
 * ## 用法
 *
 * ```php
 * $conf = FileLoader::array(__DIR__ . "/config/skill.php");   // 配置表
 * $fn   = FileLoader::closure(__DIR__ . "/hot/activity.php"); // 热更脚本
 * ```
 *
 * 两个方法都返回 null 表示"文件不存在或形状不对"。调用方应当**保留旧数据**
 * 而不是清空——半个配置表比旧表更危险。
 */
final class FileLoader
{
    /**
     * 文件内容指纹（crc32）。用来判断"这次要不要重载"。
     *
     * ## 为什么不返回 mtime
     *
     * 框架里有条已经吃过两次亏的规矩：**判"内容有没有变"要看内容，不要看时间戳**。
     * `filemtime()` 只到秒——一秒内改两次看不到；改一个等长的值（`12` → `45`）
     * 连文件大小都不变。两个维度同时失效，现象就是"改了但不生效，日志里一次变更都没有"。
     *
     * 配置表那边（`TSF\Config\Watcher`）用的是导出端算好 crc32 的清单；
     * 这里没有导出端，所以直接对文件内容算。PHP 配置文件通常几 KB，
     * crc32 在微秒级，每帧算一次也不值得优化成增量。
     *
     * 返回空串表示文件不可读——调用方应当把它当成"这次不重载"，
     * 而不是"内容变了"。
     */
    public static function hash(string $path): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === "") {
            return "";
        }
        return (string) crc32($raw);
    }

    /**
     * 加载一个返回 array 的文件（配置表的主力场景）。
     *
     * @return array<string, mixed>|array<int, mixed>|null
     */
    public static function array(string $path): ?array
    {
        $r = self::requireFile($path);
        if (!is_array($r)) {
            return null;
        }
        return $r;
    }

    /**
     * 加载一个返回 Closure 的文件（T1 热更的主力场景）。
     *
     * 约定：这类文件只能 `return function (...) {...};`，
     * 不能声明 class / function / const —— 重复声明会 fatal。
     */
    public static function closure(string $path): ?\Closure
    {
        $r = self::requireFile($path);
        if (!$r instanceof \Closure) {
            return null;
        }
        return $r;
    }

    /**
     * 真正干活的地方。
     *
     * 注意参数一律走**局部变量**：`opcache_invalidate($this->path, true)`
     * 这类写法在 AOT 下会被编译成"不传参"的调用，运行时报
     * `Argument #1 ($filename) not passed`，而编译期毫无告警。
     *
     * @return array<array-key, mixed>|object|string|int|float|bool|null
     */
    private static function requireFile(string $path): array|object|string|int|float|bool|null
    {
        if (!is_file($path)) {
            return null;
        }
        // 这一行是整个类的存在理由
        opcache_invalidate($path, true);
        $r = include $path;
        return $r;
    }
}
