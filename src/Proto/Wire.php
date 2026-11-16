<?php

namespace TSF\Proto;

/**
 * protobuf wire format 的编码原语。
 *
 * ## 为什么自己实现，而不用现成的 protobuf 库
 *
 * 项目里没有 protobuf 运行时（`vendor/` 里只有 swoole）。而**格式本身**是公开且稳定的，
 * 自己实现的成本远低于引一个依赖——更要紧的是它必须能在 AOT 下编译，
 * `Google\Protobuf\Internal` 那种大量魔术方法的库几乎肯定过不去。
 *
 * 这里生成的字节**和标准 protobuf 完全一致**，所以前端可以直接用 protobuf.js
 * 读同一份 `.proto`，不需要我们再生成一份 TypeScript。
 *
 * ## 两个容易写错的地方
 *
 * **1. 负数的 int32 是 10 字节。**
 * proto3 规定 int32/int64/enum 一律按 64 位编码。`-1` 不是 1 字节的 `0xFF`，
 * 而是符号扩展后的 `FF FF FF FF FF FF FF FF FF 01`（10 字节）。
 * 想省空间要用 `sint32`（zigzag），两者语义不同，混用会让前端读出天文数字。
 *
 * **2. 右移必须是"逻辑右移"。**
 * PHP 的 int 是 64 位补码，`>>` 是算术右移（负数会一直补 1）。
 * 编码 varint 时先掩码再移位：`($v >> 7) & 0x01FF_FFFF_FFFF_FFFF`，
 * 掩码把符号扩展出来的高位清掉，等价于逻辑右移。
 */
final class Wire
{
    /** wire type */
    public const WT_VARINT = 0;

    public const WT_64BIT = 1;

    public const WT_LEN = 2;

    public const WT_32BIT = 5;

    /** 逻辑右移用的掩码：低 57 位（64 - 7） */
    private const MASK57 = 0x01FFFFFFFFFFFFFF;

    public static function tag(int $field, int $wireType): string
    {
        return self::varint(($field << 3) | $wireType);
    }

    /**
     * 无符号/有符号整数统一按 64 位 varint 编码。
     *
     * 负数走同一套代码：PHP 的负数就是补码，`& 0xFF` 取低位、掩码右移取高位，
     * 出来正好是符号扩展后的 64 位表示。
     */
    public static function varint(int $v): string
    {
        $s = "";
        $n = 0;
        while (true) {
            $b = $v & 0x7F;
            $v = ($v >> 7) & self::MASK57;
            if ($v === 0) {
                $s .= chr($b);
                return $s;
            }
            $s .= chr($b | 0x80);
            $n++;
            if ($n > 9) {
                return $s;   // 防御：正常不会超过 10 字节
            }
        }
    }

    /** sint32/sint64：把符号位挪到最低位，小的负数只占 1 字节 */
    public static function zigzag(int $v): int
    {
        return ($v << 1) ^ ($v >> 63);
    }

    public static function unzigzag(int $v): int
    {
        return ($v >> 1) ^ -($v & 1);
    }

    /** length-delimited：长度前缀 + 原样字节（string / bytes / 嵌套消息 / packed 数组都用它） */
    public static function bytes(string $s): string
    {
        return self::varint(strlen($s)) . $s;
    }

    public static function fixed32(int $v): string
    {
        return pack("V", $v & 0xFFFFFFFF);
    }

    public static function fixed64(int $v): string
    {
        return pack("P", $v);
    }

    /**
     * float / double 用 pack。
     *
     * `Cluster\Packet` 里写着"AOT 下 pack 支持面不确定，所以用 chr/ord"——那是保守选择。
     * 实测（`bench/probe-pack`）pack/unpack 的 g / e / V / P 在 AOT 下全部可用且正确，
     * 包括负数、零、次正规数。手写 IEEE 754 才是真风险，所以这里用 pack。
     */
    public static function float(float $v): string
    {
        return pack("g", $v);
    }

    public static function double(float $v): string
    {
        return pack("e", $v);
    }
}
