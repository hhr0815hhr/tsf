<?php

namespace TSF\Tool\Config;

/**
 * 二进制编码。**不用 pack/unpack**。
 *
 * `Cluster\Packet` 里已经踩过这个坑并写下了结论："AOT 下对 pack 的支持面不确定，
 * 而 chr/ord 在所有模式下都稳"。配置文件的读取端是 AOT 编译进二进制的，
 * 所以这里跟着用 chr/ord——顺带让生成器和读取端能用同一套位运算互相验证。
 *
 * 全部小端。配置文件和机器同源，字节序不需要跨平台协商。
 */
final class Bin
{
    public const MAGIC = "TSFC";

    /**
     * 格式版本。
     *
     * 2：头部从"全字段 hash"改成**字段清单**（名字 + 类型声明）。
     * 这样读取端能做两件 v1 做不到的事：报出"第几列不对"，
     * 以及允许**末尾追加列**（老代码忽略自己不认识的那几列）。
     */
    public const VERSION = 2;

    public static function u16(int $v): string
    {
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF);
    }

    public static function u32(int $v): string
    {
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 24) & 0xFF);
    }

    /**
     * 有符号 32 位。负数靠 `& 0xFF` 取补码低位——
     * PHP 的 int 是 64 位补码，所以对负数做右移+掩码得到的正是它低 32 位的表示，
     * 不需要先转成无符号。
     */
    public static function int32(int $v): string
    {
        return self::u32($v);
    }

    /** u32 长度 + 原样字节 */
    public static function str(string $s): string
    {
        return self::u32(strlen($s)) . $s;
    }

    /**
     * 小数存成十进制字符串。
     *
     * 自己实现 IEEE 754 的编码/解码（符号位、指数偏移、隐含位、次正规数）是一大坨
     * 容易写错又难测的代码，收益只是每行省几个字节——配置表加载是启动时一次性的，
     * 这点体积换"不会因为舍入写错"很值。
     *
     * 用 `%.17g` 保证 round-trip：17 位有效数字是 double 的最坏情况精度，
     * 用默认的 14 位会丢位（`0.1 + 0.2` 那种误差会在导出后固化）。
     */
    public static function asciiFloat(float $v): string
    {
        $s = sprintf("%.17G", $v);
        return self::u16(strlen($s)) . $s;
    }
}
