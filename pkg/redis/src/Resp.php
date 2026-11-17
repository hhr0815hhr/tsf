<?php

namespace TSF\Redis;

/**
 * RESP2 协议的编解码。
 *
 * 为什么不依赖 php-redis 扩展：AOT 运行时只保证基础扩展（PDO / fsockopen 这一档），
 * 第三方扩展不一定能链进去。手写 200 行协议换来"任何环境都能跑"，值。
 *
 * 五种回复类型：
 *   +简单字符串  -错误  :整数  $批量字符串  *数组
 *
 * 限制：数组嵌套按递归解析。RESP2 里 SHA、SCAN 会用到嵌套，这里支持；再深就没有了。
 */
final class Resp
{
    /** @param array<int, string|int|float> $args */
    public static function encode(array $args): string
    {
        $out = "*" . count($args) . "\r\n";
        foreach ($args as $a) {
            $s = (string) $a;
            $out .= "$" . strlen($s) . "\r\n" . $s . "\r\n";
        }
        return $out;
    }

    /**
     * 读一个完整回复。
     *
     * @param resource $fp
     * @return string|int|array|null
     */
    public static function read($fp): string|int|array|null
    {
        $line = fgets($fp);
        if (!is_string($line) || $line === "") {
            return null;
        }
        $type = $line[0];
        // 去掉类型字节和行尾 \r\n
        $payload = rtrim(substr($line, 1), "\r\n");

        if ($type === "+") {
            return $payload;
        }
        if ($type === "-") {
            // 服务端返回的错误：当成异常抛，让调用方自己决定怎么处理
            throw new \RuntimeException("redis: " . $payload);
        }
        if ($type === ":") {
            return (int) $payload;
        }
        if ($type === "$") {
            $len = (int) $payload;
            if ($len < 0) {
                return null;    // $-1 表示 nil
            }
            $need = $len + 2;   // 内容 + 尾部 \r\n
            $buf = "";
            while (strlen($buf) < $need) {
                $chunk = fread($fp, $need - strlen($buf));
                if (!is_string($chunk) || $chunk === "") {
                    return null;
                }
                $buf .= $chunk;
            }
            return substr($buf, 0, $len);
        }
        if ($type === "*") {
            $n = (int) $payload;
            if ($n < 0) {
                return null;    // *-1 表示 nil 数组
            }
            $out = [];
            $i = 0;
            while ($i < $n) {
                $out[] = self::read($fp);
                $i++;
            }
            return $out;
        }
        return null;
    }
}
