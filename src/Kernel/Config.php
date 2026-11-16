<?php

namespace TSF\Kernel;

/**
 * 分层配置：tsf.json → tsf.{env}.json → tsf.local.json，后者覆盖前者。
 *
 * 两个设计决定：
 *
 * 1) 用 JSON 而不是 PHP 数组 —— PHP 配置在 AOT 下是编译期固定的，改文件必须重编；
 *    JSON 是运行期读取，天然支持 L1 数据热更（文件监听 + 重载）。
 *
 * 2) 加载时把嵌套结构**扁平化成 "a.b.c" => 标量** 的单层表 —— 运行期只做一次
 *    array_key_exists，不做嵌套遍历。除了更快，还避开了一个 AOT 编译问题：
 *    混合类型的嵌套数组在反复下标访问时会触发类型校验异常（实测踩到）。
 */
final class Config
{
    /** @var array<string, string|int|float|bool|array> 扁平键值表，键形如 "log.level" */
    private static array $flat = [];

    private static string $dir = "";

    private static string $env = "prod";

    public static function load(string $dir, string $env = "prod"): void
    {
        self::$dir = $dir;
        self::$env = $env;
        self::$flat = [];

        $files = [
            $dir . "/tsf.json",
            $dir . "/tsf." . $env . ".json",
            $dir . "/tsf.local.json",
        ];
        foreach ($files as $f) {
            if (!file_exists($f)) {
                continue;
            }
            $raw = file_get_contents($f);
            if ($raw === false || $raw === "") {
                continue;
            }
            $arr = json_decode($raw, true);
            if (!is_array($arr)) {
                throw new \RuntimeException("config: invalid json [" . $f . "]");
            }
            self::flatten($arr, "");
        }
    }

    /** 递归展开成 "a.b.c" => 标量；纯列表（如白名单）整块保留 */
    private static function flatten(array $node, string $prefix): void
    {
        foreach ($node as $k => $v) {
            $key = $prefix === "" ? (string) $k : $prefix . "." . (string) $k;
            if (is_array($v)) {
                if (self::isList($v)) {
                    self::$flat[$key] = $v;
                } else {
                    self::flatten($v, $key);
                }
            } else {
                self::$flat[$key] = $v;
            }
        }
    }

    /** 判断是否是顺序索引数组（PHP 8.1 的 array_is_list，这里手写以避开兼容性） */
    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $v) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    public static function has(string $path): bool
    {
        return array_key_exists($path, self::$flat);
    }

    public static function getString(string $path, string $default = ""): string
    {
        if (!array_key_exists($path, self::$flat)) {
            return $default;
        }
        $v = self::$flat[$path];
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? "1" : "0";
        }
        return $default;
    }

    public static function getInt(string $path, int $default = 0): int
    {
        if (!array_key_exists($path, self::$flat)) {
            return $default;
        }
        $v = self::$flat[$path];
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return $default;
    }

    public static function getBool(string $path, bool $default = false): bool
    {
        if (!array_key_exists($path, self::$flat)) {
            return $default;
        }
        $v = self::$flat[$path];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            $lv = strtolower($v);
            return $lv === "1" || $lv === "true" || $lv === "yes" || $lv === "on";
        }
        return $default;
    }

    /** @return array<int, string|int|float|bool|array> */
    public static function getList(string $path): array
    {
        if (!array_key_exists($path, self::$flat)) {
            return [];
        }
        $v = self::$flat[$path];
        return is_array($v) ? $v : [];
    }

    /**
     * 取某个前缀下的所有标量键，返回去掉前缀的关联数组。
     * 例如 getMap("server.auth.tokens") 会把 "server.auth.tokens.tok-1001" => 1001
     * 还原成 ["tok-1001" => 1001]。
     *
     * 注意：键名里不要再包含点号（扁平化用点号做分隔符，会歧义）。
     *
     * @return array<string, string|int|float|bool|array>
     */
    public static function getMap(string $prefix): array
    {
        $p = $prefix . ".";
        $out = [];
        foreach (self::$flat as $k => $v) {
            if (strpos($k, $p) === 0) {
                $out[substr($k, strlen($p))] = $v;
            }
        }
        return $out;
    }

    /** @return array<string, string|int|float|bool|array> 扁平表，调试用 */
    public static function all(): array
    {
        return self::$flat;
    }

    public static function env(): string
    {
        return self::$env;
    }

    /**
     * 把配置值里的 `${base}` 换成本次启动的工程目录。
     *
     * 为什么需要：示例工程的 `tsf.json` 里写的是日志、sqlite、快照的绝对路径。
     * 绝对路径让配置**不可搬迁**——仓库 clone 到别处跑起来，会悄悄把日志和库写回
     * 原来那个目录，看起来一切正常，实际在污染另一棵树。
     *
     * 用占位符而不是相对路径：相对路径依赖进程的工作目录，`./demo/duel/duel` 从根目录
     * 起就会把数据写到根目录；工程目录是**编译期/启动期就确定**的，不依赖 cwd。
     *
     * 不是模板引擎：只做一次字面替换，认不出占位符就当普通字符串。
     */
    public static function expandBase(string $base): void
    {
        $marker = '${base}';
        $out = [];
        foreach (self::$flat as $k => $v) {
            if (is_string($v) && str_contains($v, $marker)) {
                $out[$k] = str_replace($marker, $base, $v);
            } else {
                $out[$k] = $v;
            }
        }
        self::$flat = $out;
    }
}
