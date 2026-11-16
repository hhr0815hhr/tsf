<?php

namespace TSF\Tool\Config;

/**
 * 最小 xlsx 读取器：只做"把一张表读成二维字符串数组"这一件事。
 *
 * ## 为什么自己读而不装 PhpSpreadsheet
 *
 * PhpSpreadsheet 装下来几十 MB、几十个依赖，而我们只需要 `sharedStrings.xml` +
 * `sheet1.xml` 两个文件里的一点点信息。策划改表最怕的是"工具链又挂了"，
 * 依赖越少越不容易挂。
 *
 * ## 它不做什么
 *
 * 不解析样式、不解析公式（读缓存值）、不处理图表/合并单元格（空单元格留空）、
 * 不写 xlsx。这些对"把配置表变成强类型数据"都用不上。
 *
 * ## 三个必须处理的细节
 *
 * 1. **共享字符串表**：单元格里存的往往不是文本，而是 `sharedStrings.xml` 里
 *    的下标（`t="s"`）。忘了解析就会得到一堆数字。
 * 2. **`<si>` 里的富文本**：一段文字可能被拆成多个 `<r><t>` 片段
 *    （策划只给某几个字改了颜色就会这样），必须**拼起来**而不是只取第一个。
 * 3. **空单元格会被整格省略**：`<row>` 里没有该列的 `<c>`。所以必须按
 *    `r="C5"` 里的列标还原位置，不能用"第几个 c 就是第几列"。
 */
final class Xlsx
{
    /** @var array<string, string> 包内路径 => 内容（避免反复 unzip） */
    private array $parts = [];

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $zip = new \ZipArchive();
        $rc = $zip->open($path);
        if ($rc !== true) {
            throw new \RuntimeException("打不开 xlsx [" . $path . "]（ZipArchive 返回 " . (string) $rc . "）");
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            // 只读需要的三四个文件；图片/主题/样式一概不碰
            if (strncmp($name, "xl/", 3) !== 0) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                $this->parts[$name] = $data;
            }
        }
        $zip->close();
    }

    /** 所有工作表名（按工作簿里的顺序） */
    public function sheets(): array
    {
        $names = [];
        $rels = $this->rels();
        $xml = $this->parts["xl/workbook.xml"] ?? "";
        if ($xml === "") {
            return $names;
        }
        // <sheet name="Item" sheetId="1" r:id="rId1"/>
        if (preg_match_all('/<sheet\b[^>]*>/i', $xml, $m) > 0) {
            foreach ($m[0] as $tag) {
                $name = $this->attr($tag, "name");
                $rid = $this->attr($tag, "r:id");
                if ($name === "" || $rid === "") {
                    continue;
                }
                $names[$name] = $rels[$rid] ?? "";
            }
        }
        return $names;
    }

    /**
     * 读一张表，返回二维字符串数组（行优先，第一维是行）。
     *
     * `$sheet` 为空时取第一张表。空单元格是 `""` 而不是 `null`——
     * 上层校验要区分"空"和"缺失"时靠列数，不靠 null。
     */
    public function read(?string $sheet = null): array
    {
        $sheets = $this->sheets();
        if ($sheets === []) {
            throw new \RuntimeException("xlsx 里没有工作表 [" . $this->path . "]");
        }
        $target = "";
        if ($sheet === null || $sheet === "") {
            foreach ($sheets as $name => $p) {
                $target = $p;
                break;
            }
        } else {
            if (!isset($sheets[$sheet])) {
                throw new \RuntimeException(
                    "找不到工作表 [" . $sheet . "]，实际有：" . implode(" / ", array_keys($sheets))
                );
            }
            $target = $sheets[$sheet];
        }

        $xml = $this->parts[$target] ?? "";
        if ($xml === "") {
            throw new \RuntimeException("工作表 [" . $target . "] 内容为空或不存在");
        }

        $shared = $this->sharedStrings();
        $rows = [];

        if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $xml, $rm, PREG_SET_ORDER) === 0) {
            return $rows;
        }

        foreach ($rm as $rowMatch) {
            $rowXml = $rowMatch[1];
            $cells = [];
            $maxCol = -1;

            if (preg_match_all('/<c\b[^>]*?\/>|<c\b[^>]*?>.*?<\/c>/is', $rowXml, $cm, PREG_SET_ORDER) > 0) {
                foreach ($cm as $cellMatch) {
                    $raw = $cellMatch[0];

                    // **自闭合的 `<c r="H7" t="inlineStr" />` 必须单独处理**：
                    // 用 `/([^>]*)(?:\/>|>(.*?)<\/c>)/` 这种写法时，`[^>]*` 会把
                    // 结尾的 `/` 一起吃掉，于是只能走 `>(.*?)</c>` 分支——
                    // 而它没有闭合标签，`.*?` 就一路吞到**下一个单元格的 `</c>`**，
                    // 后一格被静默丢弃（现象：整行少一列，值还往前错位）
                    if (substr($raw, -2) === "/>") {
                        $attrs = substr($raw, 2, -2);
                        $inner = "";
                    } else {
                        $gt = strpos($raw, ">");
                        if ($gt === false) {
                            continue;
                        }
                        $attrs = substr($raw, 2, $gt - 2);
                        $inner = substr($raw, $gt + 1, -4);   // 去掉 <c ...> 和 </c>
                    }

                    $ref = $this->attr($attrs, "r");
                    $type = $this->attr($attrs, "t");

                    $col = $ref === "" ? $maxCol + 1 : $this->colIndex($ref);

                    $value = "";
                    if ($type === "s") {
                        $idx = $this->firstTag($inner, "v");
                        $k = (int) $idx;
                        $value = $shared[$k] ?? "";
                    } elseif ($type === "inlineStr") {
                        $value = $this->richText($inner);
                    } elseif ($type === "b") {
                        $value = $this->firstTag($inner, "v") === "1" ? "1" : "0";
                    } elseif ($type === "e") {
                        // 错误值（#REF! 之类）：当成空，让校验去报"必填缺失"
                        $value = "";
                    } else {
                        $value = $this->firstTag($inner, "v");
                    }

                    if ($col > $maxCol) {
                        $maxCol = $col;
                    }
                    $cells[$col] = $value;
                }
            }

            if ($maxCol < 0) {
                continue;
            }
            $line = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $line[] = isset($cells[$c]) ? $cells[$c] : "";
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /** 共享字符串表。富文本要拼，见类注释第 2 条 */
    private function sharedStrings(): array
    {
        $out = [];
        $xml = $this->parts["xl/sharedStrings.xml"] ?? "";
        if ($xml === "") {
            return $out;
        }
        if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $m, PREG_SET_ORDER) === 0) {
            return $out;
        }
        foreach ($m as $si) {
            $out[] = $this->richText($si[1]);
        }
        return $out;
    }

    /** 把 `<si>` / `<is>` 里的所有 `<t>` 拼成一个串（富文本会被拆成多段） */
    private function richText(string $xml): string
    {
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $xml, $m, PREG_SET_ORDER) === 0) {
            return "";
        }
        $s = "";
        foreach ($m as $t) {
            $s .= $this->unescape($t[1]);
        }
        return $s;
    }

    private function firstTag(string $xml, string $tag): string
    {
        if (preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', $xml, $m) !== 1) {
            return "";
        }
        return $this->unescape($m[1]);
    }

    private function unescape(string $s): string
    {
        // 不用 html_entity_decode：它会按 HTML5 规则把 &nbsp; 之类也解掉，
        // 而 XML 只认这几个预定义实体 + 数字引用
        $s = str_replace(["&lt;", "&gt;", "&quot;", "&apos;"], ["<", ">", "\"", "'"], $s);
        $s = str_replace("&amp;", "&", $s);
        return preg_replace_callback('/&#(x?)([0-9A-Fa-f]+);/', function (array $m): string {
            $n = $m[1] === "x" ? hexdec($m[2]) : (int) $m[2];
            return $this->utf8((int) $n);
        }, $s) ?? $s;
    }

    private function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /** 从 `<c r="AB12" ...>` 里取列号：A=0, Z=25, AA=26 */
    private function colIndex(string $ref): int
    {
        $n = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = $ref[$i];
            if ($ch >= "A" && $ch <= "Z") {
                $n = $n * 26 + (ord($ch) - 64);
                continue;
            }
            if ($ch >= "a" && $ch <= "z") {
                $n = $n * 26 + (ord($ch) - 96);
                continue;
            }
            break;
        }
        return $n > 0 ? $n - 1 : 0;
    }

    private function attr(string $tag, string $name): string
    {
        if (preg_match('/\b' . preg_quote($name, "/") . '\s*=\s*"([^"]*)"/i', $tag, $m) === 1) {
            return $this->unescape($m[1]);
        }
        return "";
    }

    /** @return array<string, string> rId => 包内路径 */
    private function rels(): array
    {
        $out = [];
        $xml = $this->parts["xl/_rels/workbook.xml.rels"] ?? "";
        if ($xml === "") {
            return $out;
        }
        if (preg_match_all('/<Relationship\b[^>]*>/i', $xml, $m, PREG_SET_ORDER) === 0) {
            return $out;
        }
        foreach ($m as $rel) {
            $id = $this->attr($rel[0], "Id");
            $target = $this->attr($rel[0], "Target");
            if ($id === "" || $target === "") {
                continue;
            }
            // Target 可能写成 "/xl/worksheets/sheet1.xml" 或 "worksheets/sheet1.xml"
            $target = ltrim($target, "/");
            if (strncmp($target, "xl/", 3) !== 0) {
                $target = "xl/" . $target;
            }
            $out[$id] = $target;
        }
        return $out;
    }
}
