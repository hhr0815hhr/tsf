<?php

namespace TSF\Tool\Config;

/**
 * 一列的类型：解析类型声明、校验单元格、把值编码成二进制。
 *
 * ## 类型声明写在 Excel 的第 2 行
 *
 * ```
 *   id          int(1..9999)        区间
 *   name        string(1..16)       长度
 *   attack      int
 *   speed       float(0..9.9)
 *   stackable   bool
 *   tags        int[]               数组
 *   dropItem    ref:item            外键（到 item 表的主键）
 *   dropRate    ref:item.price[]    外键数组（到指定的列）
 * ```
 *
 * 放表头而不是单独写一份 schema 文件，是因为**策划只会改 Excel**。
 * 多一个 schema 文件就多一处会忘记同步的地方，而且改列的时候还得会两种文件格式。
 *
 * ## 校验失败要说清"哪一行哪一列哪个值"
 *
 * 配置表报错最烦的是"第 N 行出错了"却说不出哪个字段——策划得自己一格格找。
 * 所以每条错误都带：文件名、行号（Excel 里的行号，不是数组下标）、列名、原值、期望。
 */
final class Field
{
    public const T_INT = 1;

    public const T_FLOAT = 2;

    public const T_STR = 3;

    public const T_BOOL = 4;

    public const T_INT_ARR = 5;

    public const T_REF = 6;

    public const T_REF_ARR = 7;

    /** 列名（Excel 第 1 行） */
    public string $name;

    /** 类型声明原文（Excel 第 2 行） */
    public string $spec;

    public int $kind;

    public bool $isArray = false;

    /** 是否有区间/长度约束 */
    public bool $hasRange = false;

    public float $min = 0.0;

    public float $max = 0.0;

    /** ref 指向的表 */
    public string $refTable = "";

    /** ref 指向的列（空表示主键列） */
    public string $refField = "";

    /** 在二进制里的列号（= schema 里的顺序） */
    public int $index = 0;

    /** 解析失败抛异常 —— 类型写错是**表的定义**错了，不是数据错了 */
    public static function parse(string $name, string $spec): Field
    {
        $f = new Field();
        $f->name = $name;
        $f->spec = $spec;

        $s = trim($spec);
        if ($s === "") {
            throw new \RuntimeException("列 [" . $name . "] 缺少类型声明（第 2 行）");
        }

        // 数组后缀
        if (substr($s, -2) === "[]") {
            $f->isArray = true;
            $s = substr($s, 0, -2);
        }

        if (strncmp($s, "ref:", 4) === 0) {
            $target = substr($s, 4);
            if ($target === "") {
                throw new \RuntimeException("列 [" . $name . "] 的 ref 没写目标表");
            }
            $dot = strpos($target, ".");
            if ($dot === false) {
                $f->refTable = $target;
            } else {
                $f->refTable = substr($target, 0, $dot);
                $f->refField = substr($target, $dot + 1);
            }
            $f->kind = $f->isArray ? self::T_REF_ARR : self::T_REF;
            return $f;
        }

        // 带区间的形式：int(1..10) / float(0..9.9) / string(1..16)
        $base = $s;
        $lp = strpos($s, "(");
        if ($lp !== false) {
            if (substr($s, -1) !== ")") {
                throw new \RuntimeException("列 [" . $name . "] 类型 [" . $spec . "] 括号没闭合");
            }
            $base = substr($s, 0, $lp);
            $inner = substr($s, $lp + 1, -1);
            $dots = strpos($inner, "..");
            if ($dots === false) {
                throw new \RuntimeException("列 [" . $name . "] 区间要写成 (最小..最大)，实际 [" . $inner . "]");
            }
            $a = substr($inner, 0, $dots);
            $b = substr($inner, $dots + 2);
            if (!is_numeric($a) || !is_numeric($b)) {
                throw new \RuntimeException("列 [" . $name . "] 区间两端必须是数字，实际 [" . $inner . "]");
            }
            $f->min = (float) $a;
            $f->max = (float) $b;
            $f->hasRange = true;
            if ($f->min > $f->max) {
                throw new \RuntimeException("列 [" . $name . "] 区间反了：" . $inner);
            }
        }

        if ($base === "int") {
            $f->kind = self::T_INT;
            if ($f->isArray) {
                $f->kind = self::T_INT_ARR;
                $f->isArray = false;   // int[] 自己就是一种标量类型，不再套数组逻辑
            }
            return $f;
        }
        if ($base === "float") {
            if ($f->isArray) {
                throw new \RuntimeException("列 [" . $name . "] 暂不支持 float[]（需要的话再加）");
            }
            $f->kind = self::T_FLOAT;
            return $f;
        }
        if ($base === "string") {
            if ($f->isArray) {
                throw new \RuntimeException("列 [" . $name . "] 暂不支持 string[]（需要的话再加）");
            }
            $f->kind = self::T_STR;
            return $f;
        }
        if ($base === "bool") {
            if ($f->isArray) {
                throw new \RuntimeException("列 [" . $name . "] 暂不支持 bool[]");
            }
            $f->kind = self::T_BOOL;
            return $f;
        }

        throw new \RuntimeException("列 [" . $name . "] 未知类型 [" . $spec . "]");
    }

    /** 是否是主键候选类型（int 或 string 单值） */
    public function isKeyLike(): bool
    {
        return $this->kind === self::T_INT || $this->kind === self::T_STR;
    }

    /**
     * 校验一个单元格。返回错误描述，或 null 表示通过。
     *
     * 外键不在这一步查（需要先收集所有表的主键），见 `Exporter::checkRefs`。
     */
    public function check(string $raw, int $excelRow): ?string
    {
        $v = trim($raw);
        $where = "第 " . $excelRow . " 行「" . $this->name . "」";

        if ($this->kind === self::T_INT || $this->kind === self::T_INT_ARR || $this->kind === self::T_REF
            || $this->kind === self::T_REF_ARR) {
            // 外键的键类型可能不是 int（比如用字符串做主键的表），先按"非空"处理，
            // 具体值留给 checkRefs 判
            if ($this->kind === self::T_REF || $this->kind === self::T_REF_ARR) {
                if ($v === "") {
                    return $where . " 为空（外键必须有值）";
                }
                return null;
            }
        }

        if ($this->kind === self::T_INT) {
            if ($v === "") {
                return $where . " 为空（整数列必须有值）";
            }
            if (!self::looksInt($v)) {
                return $where . " 不是整数：" . $v;
            }
            $n = (float) $v;
            if ($n > 2147483647.0 || $n < -2147483648.0) {
                // 二进制里整数是 32 位：宁可在这里报，也不要在运行期悄悄截断
                return $where . " 超出 32 位整数范围：" . $v;
            }
            if ($this->hasRange && ($n < $this->min || $n > $this->max)) {
                return $where . " 值 " . $v . " 超出 " . $this->spec;
            }
            return null;
        }

        if ($this->kind === self::T_FLOAT) {
            if ($v === "") {
                return $where . " 为空（小数列必须有值）";
            }
            if (!is_numeric($v)) {
                return $where . " 不是数字：" . $v;
            }
            $n = (float) $v;
            if ($this->hasRange && ($n < $this->min || $n > $this->max)) {
                return $where . " 值 " . $v . " 超出 " . $this->spec;
            }
            return null;
        }

        if ($this->kind === self::T_STR) {
            // 空串是合法的（比如空的描述），除非区间写了最小长度
            $len = self::charLen($v);
            if ($this->hasRange && ($len < (int) $this->min || $len > (int) $this->max)) {
                return $where . " 长度 " . $len . " 超出 " . $this->spec;
            }
            if (!$this->hasRange && $len > 4096) {
                return $where . " 长度 " . $len . " 过大（上限 4096，防止误粘一整页）";
            }
            return null;
        }

        if ($this->kind === self::T_BOOL) {
            if ($v === "") {
                return $where . " 为空（布尔列必须有值）";
            }
            if (self::parseBool($v) === null) {
                return $where . " 不是布尔值（用 1/0、是/否、true/false）：" . $v;
            }
            return null;
        }

        if ($this->kind === self::T_INT_ARR) {
            foreach (self::splitList($v) as $one) {
                if (!self::looksInt($one)) {
                    return $where . " 里有非整数：" . $one;
                }
            }
            return null;
        }

        return null;
    }

    /** 把单元格转成运行期值（用于编码） */
    public function value(string $raw): mixed
    {
        $v = trim($raw);
        if ($this->kind === self::T_INT) {
            return (int) $v;
        }
        if ($this->kind === self::T_FLOAT) {
            return (float) $v;
        }
        if ($this->kind === self::T_STR) {
            return $v;
        }
        if ($this->kind === self::T_BOOL) {
            $b = self::parseBool($v);
            return $b === null ? false : $b;
        }
        if ($this->kind === self::T_INT_ARR) {
            $out = [];
            foreach (self::splitList($v) as $one) {
                $out[] = (int) $one;
            }
            return $out;
        }
        if ($this->kind === self::T_REF) {
            return $v;
        }
        if ($this->kind === self::T_REF_ARR) {
            return self::splitList($v);
        }
        return $v;
    }

    /** 声明签名的规范化文本，用来算 schema hash */
    public function signature(): string
    {
        return $this->name . ":" . $this->spec;
    }

    /** 生成 PHP 属性类型 */
    public function phpType(): string
    {
        if ($this->kind === self::T_INT) {
            return "int";
        }
        if ($this->kind === self::T_FLOAT) {
            return "float";
        }
        if ($this->kind === self::T_STR) {
            return "string";
        }
        if ($this->kind === self::T_BOOL) {
            return "bool";
        }
        if ($this->kind === self::T_INT_ARR) {
            return "array";
        }
        // 外键：目标表的主键可能是 int 也可能是 string，统一按"值"存
        return $this->kind === self::T_REF ? "string" : "array";
    }

    /** 生成 PHP 默认值 */
    public function phpDefault(): string
    {
        if ($this->kind === self::T_INT) {
            return "0";
        }
        if ($this->kind === self::T_FLOAT) {
            return "0.0";
        }
        if ($this->kind === self::T_STR) {
            return "\"\"";
        }
        if ($this->kind === self::T_BOOL) {
            return "false";
        }
        if ($this->kind === self::T_INT_ARR) {
            return "[]";
        }
        return $this->kind === self::T_REF ? "\"\"" : "[]";
    }

    /** 生成读取该列的一行代码 */
    public function readExpr(string $readerVar): string
    {
        if ($this->kind === self::T_INT) {
            return $readerVar . "->int()";
        }
        if ($this->kind === self::T_FLOAT) {
            return $readerVar . "->float()";
        }
        if ($this->kind === self::T_STR) {
            return $readerVar . "->str()";
        }
        if ($this->kind === self::T_BOOL) {
            return $readerVar . "->bool()";
        }
        if ($this->kind === self::T_INT_ARR) {
            return $readerVar . "->ints()";
        }
        if ($this->kind === self::T_REF) {
            return $readerVar . "->str()";
        }
        return $readerVar . "->strs()";
    }

    /** 编码一个已校验的值（写二进制时用） */
    public function encode(mixed $v): string
    {
        if ($this->kind === self::T_INT) {
            return Bin::int32((int) $v);
        }
        if ($this->kind === self::T_FLOAT) {
            return Bin::asciiFloat((float) $v);
        }
        if ($this->kind === self::T_STR) {
            return Bin::str((string) $v);
        }
        if ($this->kind === self::T_BOOL) {
            return $v === true ? "\x01" : "\x00";
        }
        if ($this->kind === self::T_INT_ARR) {
            $arr = is_array($v) ? $v : [];
            $n = count($arr);
            $s = Bin::u16($n);
            foreach ($arr as $one) {
                $s .= Bin::int32((int) $one);
            }
            return $s;
        }
        if ($this->kind === self::T_REF) {
            return Bin::str((string) $v);
        }
        $arr = is_array($v) ? $v : [];
        $n = count($arr);
        $s = Bin::u16($n);
        foreach ($arr as $one) {
            $s .= Bin::str((string) $one);
        }
        return $s;
    }

    public static function looksInt(string $v): bool
    {
        if ($v === "") {
            return false;
        }
        $len = strlen($v);
        $i = 0;
        if ($v[0] === "-" || $v[0] === "+") {
            $i = 1;
            if ($len === 1) {
                return false;
            }
        }
        for (; $i < $len; $i++) {
            $c = $v[$i];
            if ($c < "0" || $c > "9") {
                return false;
            }
        }
        return true;
    }

    public static function parseBool(string $v): ?bool
    {
        $s = strtolower(trim($v));
        if ($s === "1" || $s === "true" || $s === "yes" || $s === "y" || $s === "是") {
            return true;
        }
        if ($s === "0" || $s === "false" || $s === "no" || $s === "n" || $s === "否") {
            return false;
        }
        return null;
    }

    /**
     * 拆数组：支持逗号、中文逗号、竖线、顿号。
     *
     * 策划在 Excel 里写列表时这几种都用过——不兼容的话就是"我这明明填了但工具说空"，
     * 而那种反馈会让你去查代码，最后发现是一个全角逗号。
     */
    public static function splitList(string $v): array
    {
        $v = trim($v);
        if ($v === "") {
            return [];
        }
        $v = str_replace(["|", "、", "，"], ",", $v);
        $out = [];
        foreach (explode(",", $v) as $one) {
            $t = trim($one);
            if ($t !== "") {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** UTF-8 字符数（不是字节数——`长度 16` 对策划来说指的是字） */
    public static function charLen(string $s): int
    {
        if ($s === "") {
            return 0;
        }
        $n = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($s[$i]);
            if (($b & 0xC0) !== 0x80) {
                $n++;
            }
        }
        return $n;
    }
}
