<?php

namespace TSF\Config;

/**
 * 配置表二进制读取器（运行期，AOT 编译）。
 *
 * ```php
 * $r = Reader::open("/app/config/item.bin", "item", ItemTable::SCHEMA);
 * while ($r->next()) {
 *     $id   = $r->int();
 *     $name = $r->str();
 * }
 * ```
 *
 * ## 文件格式（v2）
 *
 * ```
 *   "TSFC"      4   魔数
 *   version     u16
 *   nameLen     u16
 *   fieldNum    u16
 *   rowCount    u32
 *   name        nameLen 字节（表名）
 *   fields      fieldNum × (u16 名字长度 + 名字 + u16 类型长度 + 类型声明)
 *   rows...
 *     rowLen    u32
 *     payload   rowLen 字节：按 fields 顺序排列的各列
 * ```
 *
 * 列编码：`int` = 4 字节有符号；`float` = u16 长度 + 十进制 ASCII；
 * `string` = u32 长度 + 字节；`bool` = 1 字节；`int[]` = u16 个数 + 每个 4 字节。
 *
 * ## 为什么头部带"字段清单"而不是一个 hash
 *
 * v1 只存了一个 crc32 指纹，能挡住"代码和数据不是一批生成的"，但挡的时候只能说
 * "指纹不对，去重新生成"——**说不出哪一列不对**，而配置表出错时人最想知道的就是这个。
 *
 * v2 存完整清单，于是能做两件事：
 *
 * 1. **报出第几列**：`第 3 列不一致：代码期望「quality:int(1..5)」，数据里是「quality:int(1..9)」`。
 * 2. **允许末尾追加列**：代码认识 N 列，文件有 M ≥ N 列时，多出来的尾列直接忽略。
 *    于是策划加一个统计列，只要重新导出数据，**不用重编程序**（每行带长度，
 *    `next()` 会自动跳过没读的部分）。
 *
 * 注意这个放宽**只对末尾追加有效**：中间插列会让后续所有列错位，比对在第一个
 * 不同的位置就失败了，不会静默读错。
 *
 * ## 另外两处刻意的设计
 *
 * - **行首带长度**：万一代码少读一列，没有行长度就会一路读歪到别的行里去，
 *   报出来是"某列值很离谱"而不是"schema 不一致"。
 * - **不用 pack/unpack**：见 `Cluster\Packet` 的说明——AOT 下支持面不确定，
 *   chr/ord 在所有模式下都稳。
 */
final class Reader
{
    private const MAGIC = "TSFC";

    private const VERSION = 2;

    /** 整个文件内容（配置表通常几 MB，一次读进来比拼多次 seek 简单也更快） */
    private string $buf = "";

    private int $pos = 0;

    private int $rows = 0;

    private int $read = 0;

    /** 当前行的结束位置（不含） */
    private int $rowEnd = 0;

    private string $table;

    private string $path;

    /** @var string[] 数据文件里比代码多出来的尾列（没人读，但记下来方便排查） */
    private array $extra = [];

    /**
     * @param string $table  期望的表名，读错文件时能立刻发现
     * @param string $schema 代码认识的列：`名字:类型;名字:类型...`（生成的代码里是常量）
     */
    public static function open(string $path, string $table, string $schema): Reader
    {
        $buf = @file_get_contents($path);
        if ($buf === false || $buf === "") {
            throw new \RuntimeException("配置表读不到：" . $path);
        }

        $r = new Reader();
        $r->buf = $buf;
        $r->path = $path;
        $r->table = $table;

        $len = strlen($buf);
        if ($len < 16) {
            throw new \RuntimeException("配置表文件太短：" . $path);
        }
        if (substr($buf, 0, 4) !== self::MAGIC) {
            throw new \RuntimeException("不是配置表文件（魔数不对）：" . $path);
        }

        $version = $r->u16At(4);
        if ($version !== self::VERSION) {
            throw new \RuntimeException(
                "配置表格式版本 " . $version . " 和运行期（" . self::VERSION . "）不一致，重新跑 tsf config build：" . $path
            );
        }

        $nameLen = $r->u16At(6);
        $fieldNum = $r->u16At(8);
        $r->rows = $r->u32At(10);
        $p = 14;

        if ($p + $nameLen > $len) {
            throw new \RuntimeException("配置表头损坏：" . $path);
        }
        $name = substr($buf, $p, $nameLen);
        if ($name !== $table) {
            throw new \RuntimeException(
                "表名不符：期望 [" . $table . "]，文件里是 [" . $name . "]（" . $path . "）"
            );
        }
        $p += $nameLen;

        // 读出数据文件的列清单
        $binFields = [];
        for ($i = 0; $i < $fieldNum; $i++) {
            if ($p + 2 > $len) {
                throw new \RuntimeException("配置表 [" . $table . "] 字段表损坏（" . $path . "）");
            }
            $nl = $r->u16At($p);
            $p += 2;
            if ($p + $nl + 2 > $len) {
                throw new \RuntimeException("配置表 [" . $table . "] 字段名越界（" . $path . "）");
            }
            $fn = substr($buf, $p, $nl);
            $p += $nl;
            $sl = $r->u16At($p);
            $p += 2;
            if ($p + $sl > $len) {
                throw new \RuntimeException("配置表 [" . $table . "] 字段类型越界（" . $path . "）");
            }
            $fs = substr($buf, $p, $sl);
            $p += $sl;
            $binFields[] = $fn . ":" . $fs;
        }

        // 逐列比对代码认识的列
        $codeFields = $schema === "" ? [] : explode(";", $schema);
        $need = count($codeFields);
        if (count($binFields) < $need) {
            throw new \RuntimeException(
                "配置表 [" . $table . "] 数据文件只有 " . count($binFields) . " 列，代码要求 " . $need
                . " 列 → 重新跑 tsf config build（" . $path . "）"
            );
        }
        for ($i = 0; $i < $need; $i++) {
            if ($binFields[$i] !== $codeFields[$i]) {
                throw new \RuntimeException(
                    "配置表 [" . $table . "] 第 " . ($i + 1) . " 列不一致：代码期望「" . $codeFields[$i]
                    . "」，数据里是「" . $binFields[$i] . "」→ 重新跑 tsf config build"
                );
            }
        }
        // 尾列：数据比代码多出来的（末尾追加列的正常情况）
        for ($i = $need; $i < count($binFields); $i++) {
            $r->extra[] = $binFields[$i];
        }

        $r->pos = $p;
        // 第一次 next() 会从 rowEnd 起步（"跳过上一行剩余"），所以它必须
        // 初始化为**表头之后**的位置。留成 0 的话第一次就把魔数当行长读了
        $r->rowEnd = $r->pos;

        return $r;
    }

    /**
     * 前进到下一行。返回 false 表示读完了。
     *
     * 上一行没读的字节会被跳过（按行首长度）——末尾追加列能兼容就靠这个。
     */
    public function next(): bool
    {
        if ($this->read >= $this->rows) {
            return false;
        }
        // 跳过上一行剩下的部分
        $this->pos = $this->rowEnd;

        if ($this->pos + 4 > strlen($this->buf)) {
            throw new \RuntimeException("配置表 [" . $this->table . "] 在第 " . $this->read . " 行处截断（" . $this->path . "）");
        }
        $rowLen = $this->u32At($this->pos);
        $this->pos += 4;
        $this->rowEnd = $this->pos + $rowLen;
        if ($this->rowEnd > strlen($this->buf)) {
            throw new \RuntimeException("配置表 [" . $this->table . "] 第 " . $this->read . " 行长度越界（" . $this->path . "）");
        }
        $this->read++;
        return true;
    }

    public function int(): int
    {
        $this->need(4);
        $v = $this->u32At($this->pos);
        $this->pos += 4;
        // 32 位补码还原成有符号
        if ($v >= 2147483648) {
            $v -= 4294967296;
        }
        return $v;
    }

    public function float(): float
    {
        $this->need(2);
        $n = $this->u16At($this->pos);
        $this->pos += 2;
        $this->need($n);
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return (float) $s;
    }

    public function str(): string
    {
        $this->need(4);
        $n = $this->u32At($this->pos);
        $this->pos += 4;
        if ($n < 0 || $n > 1048576) {
            throw new \RuntimeException("配置表 [" . $this->table . "] 字符串长度异常：" . $n);
        }
        $this->need($n);
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    public function bool(): bool
    {
        $this->need(1);
        $b = $this->buf[$this->pos];
        $this->pos++;
        return $b !== "\x00";
    }

    /** int[] */
    public function ints(): array
    {
        $this->need(2);
        $n = $this->u16At($this->pos);
        $this->pos += 2;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->int();
        }
        return $out;
    }

    /** 外键：存的是目标表的键值，类型可能是 int 也可能是字符串，统一按字符串给 */
    public function ref(): string
    {
        return $this->str();
    }

    /** 外键数组 */
    public function refs(): array
    {
        $this->need(2);
        $n = $this->u16At($this->pos);
        $this->pos += 2;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->str();
        }
        return $out;
    }

    /** 已读行数 */
    public function readRows(): int
    {
        return $this->read;
    }

    public function totalRows(): int
    {
        return $this->rows;
    }

    /**
     * 数据文件里比代码多出来的尾列（`名字:类型`）。
     *
     * 正常情况下是策划在末尾加了列、程序还没重编。业务侧想知道"有没有新数据没用上"
     * 可以打条日志；不管它也不影响正确性。
     */
    public function extraFields(): array
    {
        return $this->extra;
    }

    private function need(int $n): void
    {
        if ($this->pos + $n > $this->rowEnd) {
            // 读到行尾之外 = 代码少读/多读了一列，或者二进制是别的 schema 生成的
            throw new \RuntimeException(
                "配置表 [" . $this->table . "] 第 " . $this->read . " 行越界：字段顺序和 schema 不一致"
            );
        }
    }

    private function u16At(int $p): int
    {
        return ord($this->buf[$p]) | (ord($this->buf[$p + 1]) << 8);
    }

    private function u32At(int $p): int
    {
        return ord($this->buf[$p])
            | (ord($this->buf[$p + 1]) << 8)
            | (ord($this->buf[$p + 2]) << 16)
            | (ord($this->buf[$p + 3]) << 24);
    }
}
