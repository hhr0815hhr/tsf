<?php

namespace TSF\Tool\Proto;

/**
 * 从解析结果生成 PHP 代码。
 *
 * 生成三样东西到 `gen/Proto/`：
 *
 * | 文件 | 内容 |
 * |:--|:--|
 * | `<Msg>.php` | 消息类：强类型字段 + `encode()` + `static decode()` |
 * | `Msg.php` | 协议号常量 + 数字 → 名字的反查 |
 * | `Codec.php` | `decode(int $msgId, string $buf): ?Message` 的派发 |
 *
 * ## 编解码直接内联进每个消息类
 *
 * 另一种做法是"字段描述表 + 通用 codec"：代码少一半，但通用 codec 要在运行期
 * 按类型分支处理异构值——那正是 `Kernel\Config` 注释里记过的坑（AOT 下混合类型
 * 的反复下标访问会触发类型校验异常），而且每一项都要过 `mixed`。
 *
 * 生成"直白的、每字段一行的"代码，虽然啰嗦，但：编译得过、跑得快、出错时
 * 看生成物就能定位。代码本来就不是给人手写的。
 *
 * ## proto3 的默认值不序列化
 *
 * `int32 a = 1` 当 `a` 是 0 时**一个字节都不写**。这不是省事，是协议语义：
 * 解析端看到缺字段就填默认值。不遵守的话，和标准 protobuf 实现互通时会出
 * "对方觉得我没设这个字段"的问题。
 */
final class Generator
{
    /** @var string[] */
    private array $errors = [];

    private array $messages = [];

    private array $enums = [];

    /** 消息名 → true，用来判断字段类型是不是消息 */
    private array $msgNames = [];

    /** 枚举名 → true */
    private array $enumNames = [];

    /** @var array<string, string> 类型名 => "int" | "float" | "bool" | "string" | "message" */
    private array $scalar = [
        "int32" => "int", "int64" => "int", "uint32" => "int", "uint64" => "int",
        "sint32" => "int", "sint64" => "int",
        "fixed32" => "int", "fixed64" => "int",
        "float" => "float", "double" => "float",
        "bool" => "bool", "string" => "string", "bytes" => "string",
    ];

    public function __construct(array $parsed)
    {
        $this->messages = $parsed["messages"];
        $this->enums = $parsed["enums"];
        $this->errors = $parsed["errors"];
        foreach ($this->messages as $m) {
            $this->msgNames[(string) $m["name"]] = true;
        }
        foreach ($this->enums as $e) {
            $this->enumNames[(string) $e["name"]] = true;
        }
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private bool $validated = false;

    /** @return array<string, string> 文件名 => 内容 */
    public function generate(): array
    {
        $this->validate();

        $out = [];
        foreach ($this->messages as $m) {
            $out[(string) $m["name"] . ".php"] = $this->messageCode($m);
        }
        $out["Msg.php"] = $this->msgConstCode();
        $out["Codec.php"] = $this->codecCode();
        foreach ($this->enums as $e) {
            $out[(string) $e["name"] . ".php"] = $this->enumCode($e);
        }
        return $out;
    }

    // ───────────────────── 校验 ─────────────────────

    /**
     * 语义校验。**公开是为了让调用方能在"解析阶段已经有错"时也跑一遍**——
     * 否则用户得先修完语法错误、再跑一次才能看到类型错误，改一轮跑一轮。
     */
    public function validate(): void
    {
        if ($this->validated) {
            return;
        }
        $this->validated = true;
        $ids = [];
        foreach ($this->messages as $m) {
            $id = (int) $m["id"];
            if ($id <= 0) {
                continue;
            }
            if (isset($ids[$id])) {
                $this->errors[] = "协议号 " . $id . " 被 " . $ids[$id] . " 和 " . $m["name"] . " 同时使用";
            }
            $ids[$id] = (string) $m["name"];
        }

        // 生成的类名不能撞车：Msg / Codec 是脚手架自己占用的名字
        foreach ($this->messages as $m) {
            $n = (string) $m["name"];
            if ($n === "Msg" || $n === "Codec") {
                $this->errors[] = "消息名 [" . $n . "] 和生成器自己用的类名冲突，改个名字";
            }
        }

        foreach ($this->messages as $m) {
            foreach ($m["fields"] as $f) {
                $t = (string) $f["type"];
                if (isset($this->scalar[$t]) || isset($this->msgNames[$t]) || isset($this->enumNames[$t])) {
                    continue;
                }
                if ($t === "sfixed32" || $t === "sfixed64") {
                    // 不是"懒得实现"，是无符号解出来是错的，宁可不支持也不要静默错
                    $this->errors[] = "消息 [" . $m["name"] . "] 字段 [" . $f["name"] . "] 不支持 " . $t
                        . "（有符号定点请用 sint32/sint64）";
                    continue;
                }
                $this->errors[] = "消息 [" . $m["name"] . "] 字段 [" . $f["name"] . "] 的类型 [" . $t . "] 不认识"
                    . "（支持标量、本文件里的 message / enum）";
            }
        }
    }

    // ───────────────────── 消息类 ─────────────────────

    private function messageCode(array $m): string
    {
        $name = (string) $m["name"];
        $pkg = "Gen\\Proto";

        $fields = "";
        $encode = "";
        $branches = [];

        foreach ($m["fields"] as $f) {
            $fname = (string) $f["name"];
            $type = (string) $f["type"];
            $num = (int) $f["num"];
            $rep = (bool) $f["repeated"];
            $vt = $this->varName($fname);

            // 属性
            $fields .= $this->propertyDecl($type, $fname, $rep);

            // 编码
            $encode .= $this->encodeField($type, $fname, $num, $rep, $vt);

            // 解码
            $branches[] = ["num" => $num, "body" => $this->decodeBody($type, $fname, $rep)];
        }

        $decode = "";
        $first = true;
        foreach ($branches as $br) {
            $kw = $first ? "if" : "elseif";
            $first = false;
            $decode .= "            " . $kw . " (\$f === " . (int) $br["num"] . ") {\n"
                . (string) $br["body"]
                . "            }\n";
        }
        // **不认识的字段必须 skip**。少这一句的话游标会停在字段中间，
        // 下一轮 next() 读到的是字段体，整个消息从此解歪——而现象只是"某些值不对"
        $decode .= "else {\n                \$r->skip();\n            }\n";

        return "<?php\n\nnamespace " . $pkg . ";\n\n"
            . "use TSF\\Proto\\Message;\n"
            . "use TSF\\Proto\\Reader;\n"
            . "use TSF\\Proto\\Wire;\n\n"
            . "/**\n"
            . " * " . $name . "（协议号 " . (int) $m["id"] . "）\n"
            . " *\n"
            . " * **自动生成，不要手改**。源是 .proto，改完跑 tsf proto build。\n"
            . " */\n"
            . "final class " . $name . " extends Message\n{\n"
            . $fields
            . "\n    public function msgId(): int\n    {\n        return " . (int) $m["id"] . ";\n    }\n\n"
            . "    public function encode(): string\n    {\n"
            . "        \$b = \"\";\n"
            . $encode
            . "        return \$b;\n    }\n\n"
            . "    public static function decode(string \$buf): " . $name . "\n    {\n"
            . "        \$m = new " . $name . "();\n"
            . "        \$r = new Reader(\$buf, \"" . $name . "\");\n"
            . "        while (\$r->next()) {\n"
            . "            \$f = \$r->field();\n"
            . $decode
            . "        }\n"
            . "        return \$m;\n    }\n}\n";
    }

    private function propertyDecl(string $type, string $fname, bool $rep): string
    {
        if ($rep) {
            $doc = "";
            if (isset($this->msgNames[$type])) {
                $doc = "    /** @var " . $type . "[] */\n";
            } elseif (isset($this->enumNames[$type])) {
                $doc = "    /** @var int[] 枚举 " . $type . " */\n";
            } elseif (isset($this->scalar[$type])) {
                $doc = "    /** @var " . $this->scalar[$type] . "[] */\n";
            }
            return $doc . "    public array \$" . $fname . " = [];\n\n";
        }
        if (isset($this->msgNames[$type])) {
            return "    public ?" . $type . " \$" . $fname . " = null;\n\n";
        }
        if (isset($this->enumNames[$type])) {
            return "    /** 枚举 " . $type . "，值见 " . $type . " 类常量 */\n"
                . "    public int \$" . $fname . " = 0;\n\n";
        }
        $php = $this->scalar[$type] ?? "string";
        $def = $php === "int" ? "0" : ($php === "float" ? "0.0" : ($php === "bool" ? "false" : "\"\""));
        return "    public " . $php . " \$" . $fname . " = " . $def . ";\n\n";
    }

    private function encodeField(string $type, string $fname, int $num, bool $rep, string $vt): string
    {
        $self = "\$this->" . $fname;
        $ind = "        ";

        if (isset($this->msgNames[$type])) {
            if ($rep) {
                return $ind . "foreach (" . $self . " as " . $vt . ") {\n"
                    . $ind . "    \$b .= Wire::tag(" . $num . ", 2) . Wire::bytes(" . $vt . "->encode());\n"
                    . $ind . "}\n";
            }
            return $ind . "if (" . $self . " !== null) {\n"
                . $ind . "    \$b .= Wire::tag(" . $num . ", 2) . Wire::bytes(" . $self . "->encode());\n"
                . $ind . "}\n";
        }

        $isString = $type === "string" || $type === "bytes";

        if ($rep) {
            if ($isString) {
                return $ind . "foreach (" . $self . " as " . $vt . ") {\n"
                    . $ind . "    \$b .= Wire::tag(" . $num . ", 2) . Wire::bytes(" . $vt . ");\n"
                    . $ind . "}\n";
            }
            // packed：一个 tag 里塞 N 个值（proto3 对数值型 repeated 的默认）
            $expr = $this->scalarEncodeExpr($type, $vt);
            $acc = "\$p_" . $fname;
            return $ind . "if (" . $self . " !== []) {\n"
                . $ind . "    " . $acc . " = \"\";\n"
                . $ind . "    foreach (" . $self . " as " . $vt . ") {\n"
                . $ind . "        " . $acc . " .= " . $expr . ";\n"
                . $ind . "    }\n"
                . $ind . "    \$b .= Wire::tag(" . $num . ", 2) . Wire::bytes(" . $acc . ");\n"
                . $ind . "}\n";
        }

        // 单值：默认值不写（proto3 语义）
        $zero = $this->isZeroCheck($type, $self);
        $wt = $this->wireType($type);
        $expr = $this->scalarEncodeExpr($type, $self);
        return $ind . "if (" . $zero . ") {\n"
            . $ind . "    \$b .= Wire::tag(" . $num . ", " . $wt . ") . " . $expr . ";\n"
            . $ind . "}\n";
    }

    /** 只生成 `if` 分支的**体**，外层 if/elseif/else 由 messageCode 拼 */
    private function decodeBody(string $type, string $fname, bool $rep): string
    {
        $ind = "                ";
        $lhs = "\$m->" . $fname;

        if (isset($this->msgNames[$type])) {
            if ($rep) {
                return $ind . $lhs . "[] = " . $type . "::decode(\$r->string());\n";
            }
            return $ind . $lhs . " = " . $type . "::decode(\$r->string());\n";
        }

        $isString = $type === "string" || $type === "bytes";

        if ($rep) {
            if ($isString) {
                return $ind . $lhs . "[] = \$r->string();\n";
            }
            // packed 与逐个两种都要收（老实现/手写客户端会逐个发）
            $pack = $this->packedReader($type);
            $one = $this->scalarDecodeExpr($type);
            return $ind . "if (\$r->wireType() === 2) {\n"
                . $ind . "    " . $lhs . " = array_merge(" . $lhs . ", \$r->" . $pack . "());\n"
                . $ind . "} else {\n"
                . $ind . "    " . $lhs . "[] = \$r->" . $one . "();\n"
                . $ind . "}\n";
        }

        $one = $this->scalarDecodeExpr($type);
        return $ind . $lhs . " = \$r->" . $one . "();\n";
    }

    private function wireType(string $type): int
    {
        if ($type === "float" || $type === "fixed32" || $type === "sfixed32") {
            return 5;
        }
        if ($type === "double" || $type === "fixed64" || $type === "sfixed64") {
            return 1;
        }
        if ($type === "string" || $type === "bytes") {
            return 2;
        }
        return 0;
    }

    private function scalarEncodeExpr(string $type, string $v): string
    {
        if ($type === "sint32" || $type === "sint64") {
            return "Wire::varint(Wire::zigzag(" . $v . "))";
        }
        if ($type === "fixed32" || $type === "sfixed32") {
            return "Wire::fixed32(" . $v . ")";
        }
        if ($type === "fixed64" || $type === "sfixed64") {
            return "Wire::fixed64(" . $v . ")";
        }
        if ($type === "float") {
            return "Wire::float(" . $v . ")";
        }
        if ($type === "double") {
            return "Wire::double(" . $v . ")";
        }
        if ($type === "bool") {
            return "Wire::varint(" . $v . " ? 1 : 0)";
        }
        if ($type === "string" || $type === "bytes") {
            return "Wire::bytes(" . $v . ")";
        }
        return "Wire::varint(" . $v . ")";
    }

    private function scalarDecodeExpr(string $type): string
    {
        if ($type === "sint32" || $type === "sint64") {
            return "sint";
        }
        if ($type === "fixed32" || $type === "sfixed32") {
            return "fixed32";
        }
        if ($type === "fixed64" || $type === "sfixed64") {
            return "fixed64";
        }
        if ($type === "float") {
            return "float";
        }
        if ($type === "double") {
            return "double";
        }
        if ($type === "bool") {
            return "boolean";
        }
        if ($type === "string" || $type === "bytes") {
            return "string";
        }
        if ($type === "int32" || $type === "sfixed32") {
            return "int32";
        }
        return "varint";
    }

    private function packedReader(string $type): string
    {
        if ($type === "sint32" || $type === "sint64") {
            return "packedSints";
        }
        if ($type === "float") {
            return "packedFloats";
        }
        if ($type === "double") {
            return "packedDoubles";
        }
        return "packedVarints";
    }

    private function isZeroCheck(string $type, string $expr): string
    {
        if ($type === "string" || $type === "bytes") {
            return $expr . " !== \"\"";
        }
        if ($type === "bool") {
            return $expr;
        }
        if ($type === "float" || $type === "double") {
            return $expr . " !== 0.0";
        }
        return $expr . " !== 0";
    }

    private function varName(string $fname): string
    {
        return "\$v_" . $fname;
    }

    // ───────────────────── 脚手架 ─────────────────────

    private function msgConstCode(): string
    {
        $src = "<?php\n\nnamespace Gen\\Proto;\n\n"
            . "/**\n"
            . " * 协议号常量。**自动生成，不要手改**。\n"
            . " *\n"
            . " * 数字来自 .proto 里每个 message 上方的 `// @id N` 注释。\n"
            . " */\n"
            . "final class Msg\n{\n";

        foreach ($this->messages as $m) {
            $src .= "    public const " . $this->constName((string) $m["name"]) . " = " . (int) $m["id"] . ";\n";
        }
        $src .= "\n    /** 数字 → 名字，日志/抓包用 */\n"
            . "    public static function name(int \$msgId): string\n    {\n"
            . "        \$m = self::all();\n"
            . "        return \$m[\$msgId] ?? (\"#\" . \$msgId);\n    }\n\n"
            . "    /** @return array<int, string> */\n"
            . "    public static function all(): array\n    {\n"
            . "        return [\n";
        foreach ($this->messages as $m) {
            $src .= "            " . (int) $m["id"] . " => \"" . $m["name"] . "\",\n";
        }
        $src .= "        ];\n    }\n}\n";
        return $src;
    }

    private function codecCode(): string
    {
        $src = "<?php\n\nnamespace Gen\\Proto;\n\n"
            . "use TSF\\Proto\\Message;\n\n"
            . "/**\n"
            . " * 按协议号派发到具体的消息类。**自动生成，不要手改**。\n"
            . " *\n"
            . " * 返回 `?Message`：不认识的协议号返回 null，由调用方决定是丢包还是报错。\n"
            . " * 这里不抛异常——收到一个未来版本才有的协议号是正常现象（灰度发布），\n"
            . " * 不该因此断连接。\n"
            . " */\n"
            . "final class Codec\n{\n"
            . "    public static function decode(int \$msgId, string \$buf): ?Message\n    {\n";

        foreach ($this->messages as $m) {
            $src .= "        if (\$msgId === " . (int) $m["id"] . ") {\n"
                . "            return " . $m["name"] . "::decode(\$buf);\n"
                . "        }\n";
        }
        $src .= "        return null;\n    }\n}\n";
        return $src;
    }

    private function enumCode(array $e): string
    {
        $name = (string) $e["name"];
        $src = "<?php\n\nnamespace Gen\\Proto;\n\n"
            . "/**\n"
            . " * 枚举 " . $name . "。**自动生成，不要手改**。\n"
            . " *\n"
            . " * 生成成常量而不是 PHP 8.1 的 enum：协议字段在 wire 上就是 int32，\n"
            . " * 用 int 存最直接，也不会在 AOT 的类型推断上多绕一层。\n"
            . " */\n"
            . "final class " . $name . "\n{\n";
        foreach ($e["entries"] as $entry) {
            $src .= "    public const " . (string) $entry["name"] . " = " . (int) $entry["value"] . ";\n";
        }
        $src .= "\n    /** @return array<int, string> */\n"
            . "    public static function all(): array\n    {\n        return [\n";
        foreach ($e["entries"] as $entry) {
            $src .= "            " . (int) $entry["value"] . " => \"" . (string) $entry["name"] . "\",\n";
        }
        $src .= "        ];\n    }\n}\n";
        return $src;
    }

    /** LoginReq → LOGIN_REQ */
    private function constName(string $name): string
    {
        $out = "";
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $c = $name[$i];
            if ($c >= "A" && $c <= "Z" && $i > 0) {
                $out .= "_";
            }
            $out .= strtoupper($c);
        }
        return $out;
    }
}
