<?php

namespace TSF\Kernel;

/**
 * 启动序列。
 *
 * 顺序是设计出来的，不要随意调整：
 *   1 配置     —— 后面每步都要读
 *   2 日志     —— 最先可用，后面任何一步失败都要能记录
 *   3 应用装配 —— 由业务 main() 自己完成（注册 Service、加载配置表等）
 *   4 启动     —— 进入 Swoole 事件循环，此后不再返回
 */
final class Bootstrap
{
    private static string $baseDir = "";

    private static string $env = "prod";

    private static bool $booted = false;

    public static function boot(string $baseDir, string $channel = "app"): void
    {
        if (self::$booted) {
            return;
        }
        self::$baseDir = $baseDir;

        $envVar = getenv("TSF_ENV");
        if (is_string($envVar) && $envVar !== "") {
            self::$env = $envVar;
        }

        // 1) 配置
        $cfgDir = $baseDir . "/config";
        Config::load($cfgDir, self::$env);

        // 2) 日志
        $logFile = Config::getString("log.file", $baseDir . "/var/log/" . $channel . ".log");
        $levelName = Config::getString("log.level", "info");
        $logLevel = Logger::levelFromName($levelName);
        $logStdout = Config::getBool("log.stdout", false);
        Logger::init($channel, $logFile, $logLevel, $logStdout);

        // 3) 基础信息不走容器：baseDir/env 由 Bootstrap 自己的访问器提供，
        //    容器只装服务（object），免得容器退化成"什么都能塞"的键值缓存。
        self::$booted = true;
        Logger::info("boot", [
            "env" => self::$env,
            "dir" => $baseDir,
            "php" => PHP_VERSION,
            "pid" => posix_getpid(),
        ]);
    }

    public static function baseDir(): string
    {
        return self::$baseDir;
    }

    public static function env(): string
    {
        return self::$env;
    }

    /** 校验必需目录存在，缺失则创建（var/log、var/snap 等） */
    public static function ensureDirs(array $dirs): void
    {
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                if (!mkdir($d, 0777, true)) {
                    Logger::error("mkdir failed", ["dir" => $d]);
                }
            }
        }
    }

    /** 统一退出：记录原因后以非 0 退出，避免"静默死掉" */
    public static function fatal(string $reason, array $ctx = []): void
    {
        Logger::error("fatal: " . $reason, $ctx);
        exit(1);
    }
}
