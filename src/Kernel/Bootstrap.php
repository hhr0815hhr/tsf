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
        // `${base}` 在这里展开成工程目录：示例的 tsf.json 里有日志/库/快照的路径，
        // 不展开的话仓库换个位置跑就会写回原目录（见 Config::expandBase 的注释）
        $cfgDir = $baseDir . "/config";
        Config::load($cfgDir, self::$env);
        Config::expandBase($baseDir);

        // 2) 日志
        $logFile = Config::getString("log.file", $baseDir . "/var/log/" . $channel . ".log");
        $levelName = Config::getString("log.level", "info");
        $logLevel = Logger::levelFromName($levelName);
        $logStdout = Config::getBool("log.stdout", false);
        Logger::init($channel, $logFile, $logLevel, $logStdout);
        // 轮转：默认单文件 64 MB、保留 5 份（磁盘上限约 384 MB）。max_bytes 设 0 = 不轮转。
        // 默认值必须有——没有上限的日志迟早写满磁盘，之后所有日志静默消失。
        Logger::setRotation(
            Config::getInt("log.max_bytes", 67108864),
            Config::getInt("log.max_files", 5)
        );
        // 审计通道：GM 指令单独落一个文件，和运行日志分开留存、分开检索。
        // 不配就放在日志目录下；显式配成空串 = 关闭（审计记录回到主日志）。
        $gmFile = Config::getString("log.gm_file", "");
        if ($gmFile === "" && !Config::has("log.gm_file")) {
            if ($logFile !== "") {
                $gmFile = dirname($logFile) . "/gm.log";
            }
        }
        if ($gmFile !== "") {
            Logger::initChannel(
                "gm",
                $gmFile,
                Logger::INFO,
                Config::getInt("log.gm_max_bytes", 134217728),
                Config::getInt("log.gm_max_files", 10)
            );
        }

        // 3) 基础信息不放进任何全局注册表：baseDir/env 由 Bootstrap 自己的访问器提供。
        //    （曾经有个 Container，已删——静态容器是隐式共享，和"只发消息"的模型冲突。）
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

    /**
     * 统一退出：记录原因后以**非 0** 退出，避免"静默死掉"。
     *
     * ## 为什么不是 exit(1)
     *
     * **AOT 产物里 `exit(n)` 的参数会被忽略，退出码恒为 0**（实测 exit(1)/exit(3)/
     * exit(77) 全部得到 0）。于是 supervisor / k8s 看到的是"正常退出"——
     * 不重启、不告警、不进 crash loop。这正是本方法要避免的"静默死掉"，
     * 而只用 exit(1) 的话，它自己就成了静默死掉。
     *
     * 改用信号：SIGKILL 让退出码变成 137，监控侧能识别为异常。
     * 日志不会丢——`Logger` 是即时的 file_put_contents(FILE_APPEND)，
     * 调用 logger 时已经落盘，不依赖析构。
     */
    public static function fatal(string $reason, array $ctx = []): void
    {
        Logger::error("fatal: " . $reason, $ctx);
        self::exitWith(1);
    }

    /**
     * 以指定退出码结束进程——**AOT 下必须走这里，不要直接 exit(n)**。
     *
     * 实测（TypePHP 0.9.x）：`exit(1)` / `exit(3)` / `exit(77)` 编译出来的产物
     * 退出码**全部是 0**，参数被忽略。受影响的是所有"靠退出码传递结果"的地方：
     *
     * | 场景 | 只用 exit(n) 的后果 |
     * |:--|:--|
     * | `Bootstrap::fatal()` | supervisor/k8s 认为正常退出 → 不重启、不告警 |
     * | `migrate`（部署脚本） | 迁移失败也返回 0 → 脚本继续启动没迁完的服务 |
     * | 测试的 SKIP/FAIL | 全部变成 PASS |
     *
     * 非 0 时用 SIGKILL 把退出码变成 137，监控侧能识别。日志不会丢——
     * `Logger` 是即时 file_put_contents(FILE_APPEND)，调用时已落盘。
     */
    public static function exitWith(int $code): void
    {
        if ($code !== 0 && function_exists("posix_kill")) {
            posix_kill((int) getmypid(), 9);
        }
        exit($code);
    }
}
