<?php

namespace TSF\Tool\Proto;

/**
 * `.proto` 解析器（proto3 子集）。
 *
 * ## 为什么是"子集"而不是完整 proto3
 *
 * 完整 proto3 包含 map / oneof / any / group / 嵌套 / 扩展 / 自定义选项……
 * 每一样都要在生成器、编解码、跨语言兼容上各写一遍，而我们真正需要的纵深很浅：
 * **一堆带类型的字段，前后端都能编解码**。
 *
 * 所以不支持的东西**明确报错并指出行号**，而不是悄悄忽略——悄悄忽略的结果是
 * "我明明写了 map 怎么没生效"，那比不支持糟糕得多。
 *
 * 支持：
 *   syntax = "proto3";
 *   package a.b;                     （只用于报错信息，不影响生成）
 *   // @id 1001                      （消息号指令，proto 本身没有这个概念）
 *   message Foo { int32 a = 1; repeated string b = 2; }
 *   enum Quality { COMMON = 0; RARE = 1; }
 *
 * 不支持（遇到就报错）：map / oneof / nested message / service / import / extend / group
 */
final class Parser
{
    private string $file;

    private array $tokens = [];

    private int $i = 0;

    /** @var string[] 收集到的错误（一次全报） */
    private array $errors = [];

    /** 解析结果 */
    private array $messages = [];

    private array $enums = [];

    private string $package = "";

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /** @return array{messages: array, enums: array, package: string, errors: string[]} */
    public function parse(string $src): array
    {
        $this->tokens = $this->tokenize($src);
        $this->i = 0;
        $this->messages = [];
        $this->enums = [];
        $this->errors = [];
        $this->package = "";

        while (!$this->atEnd()) {
            $t = $this->peek();
            if ($t === "syntax") {
                $this->expectSyntax();
                continue;
            }
            if ($t === "package") {
                $this->next();
                $this->package = $this->expectIdent("包名");
                $this->expectSym(";");
                continue;
            }
            if ($t === "message") {
                $this->parseMessage();
                continue;
            }
            if ($t === "enum") {
                $this->parseEnum();
                continue;
            }
            if ($t === "service" || $t === "extend") {
                $this->error("不支持 " . $t . "（本生成器只处理消息和枚举）");
                $this->skipStatement();
                continue;
            }
            if ($t === "import" || $t === "option") {
                $this->error("不支持 " . $t . "（把所有定义放一个文件里）");
                $this->skipStatement();
                continue;
            }
            if ($t === "") {
                break;
            }
            $this->error("看不懂的顶层声明：" . $t);
            $this->skipStatement();
        }

        return [
            "messages" => $this->messages,
            "enums" => $this->enums,
            "package" => $this->package,
            "errors" => $this->errors,
        ];
    }

    // ───────────────────── 语法单元 ─────────────────────

    private function expectSyntax(): void
    {
        $this->next();   // syntax
        $this->expectSym("=");
        $ver = $this->next();
        if ($ver !== "proto3") {
            $this->error("只支持 proto3，实际 [" . $ver . "]");
        }
        $this->expectSym(";");
    }

    private function parseMessage(): void
    {
        $startLine = $this->line();
        $this->next();   // message

        // 消息号来自紧邻的 `// @id 123` 注释，它挂在 **message 这个 token** 上。
        // 必须在读消息名**之前**取——读完名字再回头取就取到名字那个 token 了
        $id = 0;
        $idLine = 0;
        $tok = $this->tokens[$this->i - 1] ?? null;
        if (is_array($tok) && isset($tok["id"]) && (int) $tok["id"] > 0) {
            $id = (int) $tok["id"];
            $idLine = (int) $tok["line"];
        }

        $name = $this->expectIdent("消息名");
        if ($name === "") {
            $this->skipStatement();
            return;
        }

        if ($id === 0) {
            $this->error("消息 [" . $name . "] 缺少协议号：在 message 上方加一行 `// @id 1001`");
        }

        if ($this->peek() !== "{") {
            $this->error("消息 [" . $name . "] 后面应当是 {");
            $this->skipStatement();
            return;
        }
        $this->next();   // {

        $fields = [];
        $seenNums = [];
        $skipped = 0;
        while (!$this->atEnd() && $this->peek() !== "}") {
            $t = $this->peek();
            if ($t === "message") {
                $this->error("第 " . $this->line() . " 行：不支持嵌套 message（拍平，换个名字）");
                $skipped++;
                $this->skipStatement();
                continue;
            }
            if ($t === "enum") {
                $this->error("第 " . $this->line() . " 行：不支持 message 内定义 enum（挪到顶层）");
                $skipped++;
                $this->skipStatement();
                continue;
            }
            if ($t === "oneof") {
                $this->error("第 " . $this->line() . " 行：不支持 oneof（改成两个可选字段并自行约定）");
                $skipped++;
                $this->skipStatement();
                continue;
            }
            if ($t === "map") {
                $this->error("第 " . $this->line() . " 行：不支持 map（用 repeated 的键值对消息代替）");
                $skipped++;
                $this->skipStatement();
                continue;
            }
            if ($t === "reserved" || $t === "option") {
                $this->skipStatement();
                continue;
            }

            $f = $this->parseField($name);
            if ($f === null) {
                $skipped++;
                $this->skipStatement();
                continue;
            }
            if (isset($seenNums[$f["num"]])) {
                $this->error("消息 [" . $name . "] 字段号 " . $f["num"] . " 重复");
                continue;
            }
            $seenNums[$f["num"]] = true;
            $fields[] = $f;
        }
        $this->expectSym("}");

        // 只有"真的什么都没写"才报空消息；因为不支持而跳过的那些，
        // 上面已经报过原因了，再报一条"没有字段"只是噪音
        if ($fields === [] && $skipped === 0) {
            $this->error("消息 [" . $name . "] 一个字段都没有");
        }

        foreach ($this->messages as $m) {
            if ($m["name"] === $name) {
                $this->error("消息名 [" . $name . "] 重复");
            }
        }
        $this->messages[] = [
            "name" => $name,
            "id" => $id,
            "line" => $startLine,
            "idLine" => $idLine,
            "fields" => $fields,
        ];
    }

    private function parseField(string $msg): ?array
    {
        $line = $this->line();
        $repeated = false;
        if ($this->peek() === "repeated") {
            $this->next();
            $repeated = true;
        } elseif ($this->peek() === "optional" || $this->peek() === "required") {
            // proto3 里 optional 有 presence 语义，我们按"默认值即未设置"处理，等同忽略
            $this->next();
        }

        $type = $this->expectIdent("字段类型");
        if ($type === "") {
            return null;
        }
        $name = $this->expectIdent("字段名");
        if ($name === "") {
            return null;
        }
        $this->expectSym("=");
        $numText = $this->next();
        if (!is_numeric($numText)) {
            $this->error("消息 [" . $msg . "] 字段 [" . $name . "] 的字段号不是数字：" . $numText);
            return null;
        }
        $num = (int) $numText;
        if ($num < 1 || $num > 536870911) {
            $this->error("消息 [" . $msg . "] 字段 [" . $name . "] 的字段号超出范围：" . $num);
        }
        // 19000-19999 是 protobuf 保留段
        if ($num >= 19000 && $num <= 19999) {
            $this->error("消息 [" . $msg . "] 字段 [" . $name . "] 用了 protobuf 保留段 19000-19999");
        }
        // 字段选项 [deprecated = true] 之类：跳过
        if ($this->peek() === "[") {
            while (!$this->atEnd() && $this->peek() !== "]") {
                $this->next();
            }
            $this->expectSym("]");
        }
        $this->expectSym(";");

        return [
            "repeated" => $repeated,
            "type" => $type,
            "name" => $name,
            "num" => $num,
            "line" => $line,
        ];
    }

    private function parseEnum(): void
    {
        $this->next();   // enum
        $name = $this->expectIdent("枚举名");
        if ($name === "") {
            $this->skipStatement();
            return;
        }
        if ($this->peek() !== "{") {
            $this->error("枚举 [" . $name . "] 后面应当是 {");
            $this->skipStatement();
            return;
        }
        $this->next();

        $entries = [];
        while (!$this->atEnd() && $this->peek() !== "}") {
            $key = $this->expectIdent("枚举项");
            if ($key === "") {
                $this->skipStatement();
                continue;
            }
            $this->expectSym("=");
            $v = $this->next();
            if (!is_numeric($v)) {
                $this->error("枚举 [" . $name . "] 项 [" . $key . "] 的值不是数字：" . $v);
                $this->skipStatement();
                continue;
            }
            if ($this->peek() === "[") {
                while (!$this->atEnd() && $this->peek() !== "]") {
                    $this->next();
                }
                $this->expectSym("]");
            }
            $this->expectSym(";");
            $entries[] = ["name" => $key, "value" => (int) $v];
        }
        $this->expectSym("}");

        foreach ($this->enums as $e) {
            if ($e["name"] === $name) {
                $this->error("枚举名 [" . $name . "] 重复");
            }
        }
        $this->enums[] = ["name" => $name, "entries" => $entries];
    }

    // ───────────────────── 词法 ─────────────────────

    /**
     * 切成 token。每个 token 形如 ["t"=>文本,"line"=>行号,"id"=>@id指令]。
     *
     * `// @id 1001` 这种注释不当注释丢掉，而是挂到**下一个** token 上——
     * 生成器靠它拿协议号。放在解析器里而不是正则扫全局，是因为"这条注释属于
     * 哪个 message"必须靠位置判断，全局扫会张冠李戴。
     */
    private function tokenize(string $src): array
    {
        $out = [];
        $len = strlen($src);
        $pos = 0;
        $line = 1;
        $pendingId = 0;
        $pendingIdLine = 0;

        while ($pos < $len) {
            $c = $src[$pos];

            if ($c === "\n") {
                $line++;
                $pos++;
                continue;
            }
            if ($c === " " || $c === "\t" || $c === "\r") {
                $pos++;
                continue;
            }

            // 注释
            if ($c === "/" && $pos + 1 < $len) {
                $n = $src[$pos + 1];
                if ($n === "/") {
                    $end = strpos($src, "\n", $pos);
                    $text = $end === false ? substr($src, $pos) : substr($src, $pos, $end - $pos);
                    if (preg_match('/@id\s+(\d+)/', $text, $m) === 1) {
                        $pendingId = (int) $m[1];
                        $pendingIdLine = $line;
                    }
                    $pos = $end === false ? $len : $end;
                    continue;
                }
                if ($n === "*") {
                    $end = strpos($src, "*/", $pos + 2);
                    if ($end === false) {
                        $line += substr_count(substr($src, $pos), "\n");
                        $pos = $len;
                        continue;
                    }
                    $chunk = substr($src, $pos, $end - $pos);
                    $line += substr_count($chunk, "\n");
                    $pos = $end + 2;
                    continue;
                }
            }

            // 字符串
            if ($c === "\"" || $c === "'") {
                $quote = $c;
                $pos++;
                $buf = "";
                while ($pos < $len && $src[$pos] !== $quote) {
                    if ($src[$pos] === "\\" && $pos + 1 < $len) {
                        $pos++;
                    }
                    $buf .= $src[$pos];
                    $pos++;
                }
                $pos++;   // 收尾引号
                $out[] = ["t" => $buf, "line" => $line, "id" => $pendingId, "idLine" => $pendingIdLine];
                $pendingId = 0;
                continue;
            }

            // 标识符 / 数字
            if (ctype_alpha($c) || $c === "_" || ctype_digit($c)) {
                $start = $pos;
                while ($pos < $len) {
                    $d = $src[$pos];
                    if (ctype_alnum($d) || $d === "_" || $d === ".") {
                        $pos++;
                        continue;
                    }
                    break;
                }
                $out[] = ["t" => substr($src, $start, $pos - $start), "line" => $line, "id" => $pendingId, "idLine" => $pendingIdLine];
                $pendingId = 0;
                continue;
            }

            // 符号
            $out[] = ["t" => $c, "line" => $line, "id" => $pendingId, "idLine" => $pendingIdLine];
            $pendingId = 0;
            $pos++;
        }

        return $out;
    }

    // ───────────────────── 小工具 ─────────────────────

    private function atEnd(): bool
    {
        return $this->i >= count($this->tokens);
    }

    private function peek(): string
    {
        return $this->atEnd() ? "" : (string) $this->tokens[$this->i]["t"];
    }

    private function next(): string
    {
        if ($this->atEnd()) {
            return "";
        }
        $t = (string) $this->tokens[$this->i]["t"];
        $this->i++;
        return $t;
    }

    private function line(): int
    {
        if ($this->atEnd()) {
            return count($this->tokens) > 0 ? (int) $this->tokens[count($this->tokens) - 1]["line"] : 0;
        }
        return (int) $this->tokens[$this->i]["line"];
    }

    private function expectIdent(string $what): string
    {
        $t = $this->peek();
        if ($t === "") {
            $this->error("源码在期待" . $what . "的地方结束了");
            return "";
        }
        if (!ctype_alpha($t[0]) && $t[0] !== "_") {
            $this->error("第 " . $this->line() . " 行：这里应当是" . $what . "，实际是 [" . $t . "]");
            $this->next();
            return "";
        }
        return $this->next();
    }

    private function expectSym(string $sym): void
    {
        if ($this->peek() !== $sym) {
            $this->error("第 " . $this->line() . " 行：应当是 [" . $sym . "]，实际是 [" . $this->peek() . "]");
            return;
        }
        $this->next();
    }

    /** 出错后跳到下一个 `;` 或 `}`，避免一个错误引发雪崩式误报 */
    private function skipStatement(): void
    {
        $depth = 0;
        while (!$this->atEnd()) {
            $t = $this->peek();
            if ($t === "{") {
                $depth++;
            } elseif ($t === "}") {
                if ($depth === 0) {
                    return;
                }
                $depth--;
                $this->next();
                if ($depth === 0) {
                    return;
                }
                continue;
            } elseif ($t === ";" && $depth === 0) {
                $this->next();
                return;
            }
            $this->next();
        }
    }

    private function error(string $msg): void
    {
        $this->errors[] = basename($this->file) . "：" . $msg;
    }
}
