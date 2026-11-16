<?php

namespace TSF\Tool\Config;

/**
 * 配置表导出：Excel → 强类型代码 + 二进制。
 *
 * ```php
 * $e = new Exporter("/data/TypeServer/demo/duel");
 * exit($e->run(true));   // false = 只校验不写产物（tsf config check）
 * ```
 *
 * ## 为什么数据不进代码
 *
 * 最省事的做法是把每张表生成成 PHP 字面量数组——编译进二进制，读取零成本。
 * 但那等于**每次改数值都要重新编译**，而策划一天改十次。
 * 所以分成两半：
 *
 * | 产物 | 什么时候变 | 编进二进制吗 |
 * |:--|:--|:--:|
 * | `gen/Config/*.php`（字段定义 + 读取代码） | **加列/改类型**才变 | 是 |
 * | `build/config/*.bin`（数据） | 每次改数值都变 | 否，运行期加载 |
 *
 * 这个分界决定了 T6-02 热更能不能做：只有数据在文件里，才存在"换掉文件不重启"。
 *
 * ## 失败即中断，且**一个字节都不写**
 *
 * 校验有任何错误就整体退出，不写 .bin 也不写 gen。
 * 否则会出现"代码是新的、数据是旧的"这种最难查的状态——
 * 运行期表现是某些字段永远是 0，而不是报错。
 */
final class Exporter
{
    private string $projDir;

    private string $tableDir;

    private string $binDir;

    private string $genDir;

    /** @var string[] 所有校验错误（一次性全报，不要让策划改一个跑一次） */
    private array $errors = [];

    /** @var array<string, array> 表名 => ["fields"=>Field[], "rows"=>array, "file"=>string, "name"=>string] */
    private array $tables = [];

    private int $warnings = 0;

    public function __construct(string $projDir)
    {
        $this->projDir = rtrim($projDir, "/");
        $this->tableDir = $this->projDir . "/config/tables";
        $this->binDir = $this->projDir . "/build/config";
        $this->genDir = $this->projDir . "/gen/Config";
    }

    /** @return int 0 = 成功；1 = 校验失败 */
    public function run(bool $write): int
    {
        if (!is_dir($this->tableDir)) {
            $this->errors[] = "找不到配置表目录：" . $this->tableDir
                . "（约定在 <工程>/config/tables/*.xlsx）";
            $this->report();
            return 1;
        }

        $files = glob($this->tableDir . "/*.xlsx");
        if ($files === false || $files === []) {
            $this->errors[] = "配置表目录里没有 .xlsx：" . $this->tableDir;
            $this->report();
            return 1;
        }
        sort($files);

        // 1. 读表 + 表头/类型解析
        foreach ($files as $file) {
            $this->loadTable($file);
        }

        // 2. 逐格校验（不含外键）
        foreach ($this->tables as $name => $t) {
            $this->checkCells($name);
        }

        // 3. 主键唯一性
        foreach ($this->tables as $name => $t) {
            $this->checkPrimaryKey($name);
        }

        // 4. 外键
        $this->checkRefs();

        if ($this->errors !== []) {
            $this->report();
            return 1;
        }

        if (!$write) {
            $this->report();
            return 0;
        }

        // 5. 写产物
        $this->ensureDir($this->binDir);
        $this->ensureDir($this->genDir);
        foreach ($this->tables as $name => $t) {
            $this->writeBin($name);
        }
        $this->writeCode();
        // 清单最后写：它一变，监听端就会去读 .bin，所以必须保证 .bin 已经写完
        $this->writeManifest();
        $this->report();
        return 0;
    }

    // ───────────────────────── 读表 ─────────────────────────

    private function loadTable(string $file): void
    {
        $base = basename($file, ".xlsx");
        $name = strtolower($base);
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $this->errors[] = basename($file) . "：表名只能是 a-z0-9_，并以字母开头（现在叫 [" . $base . "]）";
            return;
        }
        if (isset($this->tables[$name])) {
            $this->errors[] = basename($file) . "：表名 [" . $name . "] 和别的文件撞了";
            return;
        }

        try {
            $x = new Xlsx($file);
            $rows = $x->read();
        } catch (\RuntimeException $e) {
            $this->errors[] = basename($file) . "：" . $e->getMessage();
            return;
        }

        if (count($rows) < 2) {
            $this->errors[] = basename($file) . "：至少要有 2 行表头（第 1 行字段名、第 2 行类型）";
            return;
        }

        $nameRow = $rows[0];
        $typeRow = $rows[1];

        // 第 3 行：以 # 开头才算注释行（不写注释也没关系，见下）
        $dataStart = 2;
        if (isset($rows[2]) && isset($rows[2][0]) && substr(trim($rows[2][0]), 0, 1) === "#") {
            $dataStart = 3;
        }

        $fields = [];
        $count = count($nameRow);
        if ($count === 0) {
            $this->errors[] = basename($file) . "：第 1 行是空的";
            return;
        }
        for ($c = 0; $c < $count; $c++) {
            $colName = trim($nameRow[$c] ?? "");
            $spec = isset($typeRow[$c]) ? trim($typeRow[$c]) : "";
            if ($colName === "" && $spec === "") {
                continue;   // 整列空着，忽略（策划常留空列做间隔）
            }
            if ($colName === "") {
                $this->errors[] = basename($file) . " 第 " . ($c + 1) . " 列：有类型 [" . $spec . "] 但没有字段名";
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $colName) !== 1) {
                $this->errors[] = basename($file) . "：「" . $colName . "」不是合法字段名（字母开头，只含字母数字下划线）";
                continue;
            }
            foreach ($fields as $f) {
                if ($f->name === $colName) {
                    $this->errors[] = basename($file) . "：字段名「" . $colName . "」重复";
                    continue 2;
                }
            }
            try {
                $f = Field::parse($colName, $spec);
            } catch (\RuntimeException $e) {
                $this->errors[] = basename($file) . "：" . $e->getMessage();
                continue;
            }
            $f->index = count($fields);
            $fields[] = $f;
        }

        if ($fields === []) {
            $this->errors[] = basename($file) . "：一个有效字段都没有";
            return;
        }
        if (!$fields[0]->isKeyLike()) {
            $this->errors[] = basename($file) . "：第 1 列「" . $fields[0]->name . "」应当是第一主键（int 或 string）";
        }

        // 数据行：整行全空就跳过（Excel 尾部常有一堆看起来空的格式行）
        $data = [];
        $n = count($rows);
        for ($i = $dataStart; $i < $n; $i++) {
            $row = $rows[$i];
            $allEmpty = true;
            foreach ($row as $cell) {
                if (trim($cell) !== "") {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }
            // Excel 行号 = 数组下标 + 1（报错给策划看的是他在 Excel 里看到的那一行）
            $data[] = ["excelRow" => $i + 1, "cells" => $row];
        }

        $this->tables[$name] = [
            "file" => basename($file),
            "fields" => $fields,
            "rows" => $data,
        ];
    }

    // ───────────────────────── 校验 ─────────────────────────

    private function checkCells(string $table): void
    {
        $t = $this->tables[$table];
        /** @var Field[] $fields */
        $fields = $t["fields"];
        foreach ($t["rows"] as $row) {
            $excelRow = (int) $row["excelRow"];
            $cells = $row["cells"];
            foreach ($fields as $f) {
                $raw = isset($cells[$f->index]) ? (string) $cells[$f->index] : "";
                $err = $f->check($raw, $excelRow);
                if ($err !== null) {
                    $this->errors[] = $t["file"] . " " . $err;
                }
            }
        }
    }

    private function checkPrimaryKey(string $table): void
    {
        $t = $this->tables[$table];
        /** @var Field[] $fields */
        $fields = $t["fields"];
        $pk = $fields[0];
        $seen = [];
        foreach ($t["rows"] as $row) {
            $excelRow = (int) $row["excelRow"];
            $cells = $row["cells"];
            $raw = isset($cells[0]) ? trim((string) $cells[0]) : "";
            if ($raw === "") {
                $this->errors[] = $t["file"] . " 第 " . $excelRow . " 行：主键「" . $pk->name . "」为空";
                continue;
            }
            if (isset($seen[$raw])) {
                $this->errors[] = $t["file"] . " 第 " . $excelRow . " 行：主键 " . $raw
                    . " 重复（第 " . (int) $seen[$raw] . " 行已经用过）";
                continue;
            }
            $seen[$raw] = $excelRow;
        }
    }

    private function checkRefs(): void
    {
        foreach ($this->tables as $table => $t) {
            /** @var Field[] $fields */
            $fields = $t["fields"];
            foreach ($fields as $f) {
                if ($f->kind !== Field::T_REF && $f->kind !== Field::T_REF_ARR) {
                    continue;
                }
                if (!isset($this->tables[$f->refTable])) {
                    $this->errors[] = $t["file"] . "：字段「" . $f->name . "」引用了不存在的表 [" . $f->refTable . "]";
                    continue;
                }
                $target = $this->tables[$f->refTable];
                $col = 0;
                if ($f->refField !== "") {
                    $col = -1;
                    foreach ($target["fields"] as $tf) {
                        if ($tf->name === $f->refField) {
                            $col = $tf->index;
                            break;
                        }
                    }
                    if ($col < 0) {
                        $this->errors[] = $t["file"] . "：字段「" . $f->name . "」引用了 [" . $f->refTable
                            . "] 里不存在的列 [" . $f->refField . "]";
                        continue;
                    }
                }

                $allowed = [];
                foreach ($target["rows"] as $r) {
                    $v = isset($r["cells"][$col]) ? trim((string) $r["cells"][$col]) : "";
                    if ($v !== "") {
                        $allowed[$v] = true;
                    }
                }

                foreach ($t["rows"] as $row) {
                    $excelRow = (int) $row["excelRow"];
                    $raw = isset($row["cells"][$f->index]) ? trim((string) $row["cells"][$f->index]) : "";
                    if ($raw === "") {
                        continue;   // 空值已在 checkCells 里报过
                    }
                    $vals = $f->kind === Field::T_REF_ARR ? Field::splitList($raw) : [$raw];
                    foreach ($vals as $v) {
                        if (!isset($allowed[$v])) {
                            $where = $f->refField === "" ? "主键" : "列 " . $f->refField;
                            $this->errors[] = $t["file"] . " 第 " . $excelRow . " 行「" . $f->name
                                . "」：值 " . $v . " 在表 [" . $f->refTable . "] 的" . $where . "里不存在";
                        }
                    }
                }
            }
        }
    }

    // ───────────────────────── 写二进制 ─────────────────────────

    private function writeBin(string $table): void
    {
        $t = $this->tables[$table];
        /** @var Field[] $fields */
        $fields = $t["fields"];

        $body = "";
        $rows = 0;
        foreach ($t["rows"] as $row) {
            $cells = $row["cells"];
            $payload = "";
            foreach ($fields as $f) {
                $raw = isset($cells[$f->index]) ? (string) $cells[$f->index] : "";
                $payload .= $f->encode($f->value($raw));
            }
            // 行首放长度：读的时候即使某列没读，也能跳到下一行，
            // 不会因为生成器/读取端对不上而把整个文件读歪
            $body .= Bin::u32(strlen($payload)) . $payload;
            $rows++;
        }

        $name = $table;
        // 头部带**字段清单**而不是一个 hash：读取端要能说清"第几列不对"，
        // 也要能认出"多出来的尾列"并忽略（末尾追加列不需要重新编译）
        $head = Bin::MAGIC
            . Bin::u16(Bin::VERSION)
            . Bin::u16(strlen($name))
            . Bin::u16(count($fields))
            . Bin::u32($rows)
            . $name;
        foreach ($fields as $f) {
            $head .= Bin::u16(strlen($f->name)) . $f->name
                . Bin::u16(strlen($f->spec)) . $f->spec;
        }

        $path = $this->binDir . "/" . $table . ".bin";
        if (file_put_contents($path, $head . $body) === false) {
            $this->errors[] = "写不进 " . $path;
        }
    }

    /**
     * 写清单：每个 .bin 的大小 + crc32。
     *
     * ## 为什么还要一个清单文件
     *
     * 监听端本来可以看 `.bin` 的 mtime，但 **`filemtime()` 只到秒**：
     * 一秒内改两次、或者改一个等长的数字（`100` → `200`，size 也没变），
     * 都检测不到。T5-02 的服务发现踩过一模一样的坑（心跳用 mtime 判活，
     * 结果判死时间在 2~3 秒之间随机）。
     *
     * crc32 是**内容级**的判据，两个维度都不会漏；而算 crc 的成本由导出端付，
     * 监听端只读这几十个字节。
     *
     * ## 写入必须原子
     *
     * 监听端随时可能在读它。直接写会导致它读到半截内容，把"文件变了"误判成
     * "表全变了"。所以走 tmp + rename（rename 在同一文件系统上是原子的）。
     */
    private function writeManifest(): void
    {
        $lines = ["# tsf config manifest v1"];
        foreach ($this->tables as $name => $t) {
            $path = $this->binDir . "/" . $name . ".bin";
            if (!is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            $lines[] = $name . ".bin " . strlen($content) . " " . crc32($content);
        }
        $content = implode("\n", $lines) . "\n";
        $tmp = $this->binDir . "/manifest.tmp";
        if (file_put_contents($tmp, $content) === false) {
            $this->errors[] = "写不进 " . $tmp;
            return;
        }
        if (!rename($tmp, $this->binDir . "/manifest.txt")) {
            $this->errors[] = "清单改名失败（manifest.txt）";
        }
    }

    /**
     * 字段签名串：`名字:类型;名字:类型...`。
     *
     * 生成代码里以常量形式带上一份，运行期读文件时逐列比对：
     * - 前 N 列必须完全一致（否则报"第 N 列不一样"）
     * - 文件里**多出来的尾列**允许存在，忽略即可
     */
    private function schemaSignature(string $table): string
    {
        $parts = [];
        foreach ($this->tables[$table]["fields"] as $f) {
            $parts[] = $f->signature();
        }
        return implode(";", $parts);
    }

    // ───────────────────────── 写代码 ─────────────────────────

    private function writeCode(): void
    {
        foreach ($this->tables as $table => $t) {
            $class = $this->className($table);
            $this->writeFile($this->genDir . "/" . $class . ".php", $this->rowClassCode($table, $class));
            $this->writeFile($this->genDir . "/" . $class . "Table.php", $this->tableClassCode($table, $class));
        }
        $this->writeFile($this->genDir . "/Tables.php", $this->registryCode());
    }

    private function rowClassCode(string $table, string $class): string
    {
        $t = $this->tables[$table];
        /** @var Field[] $fields */
        $fields = $t["fields"];
        $src = "<?php\n\nnamespace Gen\\Config;\n\n"
            . "/**\n"
            . " * 配置行：" . $table . "（源 config/tables/" . $t["file"] . "）\n"
            . " *\n"
            . " * **自动生成，不要手改**——改了下次 tsf config build 就没了。\n"
            . " * 要加字段就改 Excel 的类型行。\n"
            . " */\n"
            . "final class " . $class . "\n{\n";
        foreach ($fields as $f) {
            $doc = $this->fieldComment($f);
            if ($doc !== "") {
                $src .= $doc;
            }
            $src .= "    public " . $f->phpType() . " $" . $f->name . " = " . $f->phpDefault() . ";\n\n";
        }
        $src = rtrim($src, "\n") . "\n}\n";
        return $src;
    }

    private function fieldComment(Field $f): string
    {
        $hint = "";
        if ($f->kind === Field::T_INT_ARR) {
            $hint = "int[]";
        } elseif ($f->kind === Field::T_REF) {
            $hint = "外键 → " . $f->refTable . ($f->refField === "" ? "" : "." . $f->refField) . "（存的是键值）";
        } elseif ($f->kind === Field::T_REF_ARR) {
            $hint = "外键数组 → " . $f->refTable . ($f->refField === "" ? "" : "." . $f->refField);
        }
        if ($hint === "") {
            return "";
        }
        return "    /** " . $hint . " */\n";
    }

    private function tableClassCode(string $table, string $class): string
    {
        $t = $this->tables[$table];
        /** @var Field[] $fields */
        $fields = $t["fields"];
        $pk = $fields[0];
        $pkType = $pk->phpType();
        $schema = addslashes($this->schemaSignature($table));

        $src = "<?php\n\nnamespace Gen\\Config;\n\nuse TSF\\Config\\Reader;\n\n"
            . "/**\n"
            . " * " . $table . " 表的加载与查询。\n"
            . " *\n"
            . " * **自动生成，不要手改**。数据在 build/config/" . $table . ".bin，运行期读文件——\n"
            . " * 所以改数值不需要重新编译（这是 T6-02 热更的前提）。\n"
            . " */\n"
            . "final class " . $class . "Table\n{\n"
            . "    /**\n"
            . "     * 本代码认识的列（名字:类型，分号分隔）。\n"
            . "     *\n"
            . "     * 运行期会拿它和数据文件的字段清单逐列比对：\n"
            . "     * 前 N 列必须一致，**文件里多出来的尾列会被忽略**——\n"
            . "     * 所以策划在末尾追加一列时，重新导出数据就够了，不用重编程序。\n"
            . "     */\n"
            . "    public const SCHEMA = \"" . $schema . "\";\n\n"
            . "    public const BIN_NAME = \"" . $table . ".bin\";\n\n"
            . "    /** @var array<" . $pkType . ", " . $class . "> */\n"
            . "    private array \$byKey = [];\n\n"
            . "    /** @var " . $class . "[] */\n"
            . "    private array \$rows = [];\n\n"
            . "    /**\n"
            . "     * 读二进制。返回行数。\n"
            . "     *\n"
            . "     * 重复 load 会整体替换（热更要的就是这个语义，不能把旧行留着）。\n"
            . "     */\n"
            . "    public function load(string \$path): int\n    {\n"
            . "        \$r = Reader::open(\$path, \"" . $table . "\", self::SCHEMA);\n"
            . "        \$byKey = [];\n"
            . "        \$rows = [];\n"
            . "        while (\$r->next()) {\n"
            . "            \$o = new " . $class . "();\n";
        foreach ($fields as $f) {
            $src .= "            \$o->" . $f->name . " = \$r->" . $this->readMethod($f) . "();\n";
        }
        $src .= "            \$byKey[\$o->" . $pk->name . "] = \$o;\n"
            . "            \$rows[] = \$o;\n"
            . "        }\n"
            . "        \$this->byKey = \$byKey;\n"
            . "        \$this->rows = \$rows;\n"
            . "        return count(\$rows);\n"
            . "    }\n\n"
            . "    public function get(" . $pkType . " \$" . $pk->name . "): ?" . $class . "\n    {\n"
            . "        return \$this->byKey[\$" . $pk->name . "] ?? null;\n"
            . "    }\n\n"
            . "    /** @return " . $class . "[] */\n"
            . "    public function all(): array\n    {\n"
            . "        return \$this->rows;\n"
            . "    }\n\n"
            . "    public function count(): int\n    {\n"
            . "        return count(\$this->rows);\n"
            . "    }\n}\n";

        return $src;
    }

    private function readMethod(Field $f): string
    {
        if ($f->kind === Field::T_INT) {
            return "int";
        }
        if ($f->kind === Field::T_FLOAT) {
            return "float";
        }
        if ($f->kind === Field::T_STR || $f->kind === Field::T_REF) {
            return $f->kind === Field::T_REF ? "ref" : "str";
        }
        if ($f->kind === Field::T_BOOL) {
            return "bool";
        }
        if ($f->kind === Field::T_INT_ARR) {
            return "ints";
        }
        return "refs";
    }

    private function registryCode(): string
    {
        $src = "<?php\n\nnamespace Gen\\Config;\n\n"
            . "/**\n"
            . " * 全部配置表的入口。\n"
            . " *\n"
            . " * **自动生成，不要手改**。\n"
            . " * 每张表一个静态属性 + 一个访问器：属性带类型，AOT 下比用数组装对象稳。\n"
            . " */\n"
            . "final class Tables\n{\n"
            . "    public static string \$dir = \"\";\n\n";

        foreach ($this->tables as $table => $t) {
            $class = $this->className($table);
            $src .= "    public static ?" . $class . "Table \$" . $table . " = null;\n\n";
        }

        $src .= "    /**\n"
            . "     * 加载全部表。返回 [表名 => 行数]。\n"
            . "     *\n"
            . "     * **先把所有表读进局部变量，全部成功后再一次性替换**：中途任何一张报错都不动现有数据。\n"
            . "     * 热更最怕的不是不生效，而是一半新一半旧——那种状态数据看着都正常，只是对不上。\n"
            . "     */\n"
            . "    public static function load(string \$dir): array\n    {\n"
            . "        \$counts = [];\n";
        foreach ($this->tables as $table => $t) {
            $class = $this->className($table);
            $src .= "        \$first" . $class . " = new " . $class . "Table();\n"
                . "        \$counts[\"" . $table . "\"] = \$first" . $class . "->load(\$dir . \"/" . $table . ".bin\");\n";
        }
        $src .= "\n";
        foreach ($this->tables as $table => $t) {
            $class = $this->className($table);
            $src .= "        self::\$" . $table . " = \$first" . $class . ";\n";
        }
        $src .= "        self::\$dir = \$dir;\n        return \$counts;\n    }\n\n";

        $src .= "    /**\n"
            . "     * 只重载指定的表（\$files 是 .bin 文件名，来自 Watcher 的变更清单）。\n"
            . "     *\n"
            . "     * 按表重载而不是整批：一万行的表读一次要 60 ms 以上，改一张小表却把大表也重读一遍是白花时间。\n"
            . "     */\n"
            . "    public static function reload(array \$files): array\n    {\n"
            . "        \$counts = [];\n";
        foreach ($this->tables as $table => $t) {
            $class = $this->className($table);
            // 每张表用独立局部名：AOT 下同一个变量先后被赋成不同类型会直接编译失败
            $src .= "        if (in_array(\"" . $table . ".bin\", \$files, true)) {\n"
                . "            \$re" . $class . " = new " . $class . "Table();\n"
                . "            \$counts[\"" . $table . "\"] = \$re" . $class . "->load(self::\$dir . \"/" . $table . ".bin\");\n"
                . "            self::\$" . $table . " = \$re" . $class . ";\n"
                . "        }\n";
        }
        $src .= "        return \$counts;\n    }\n\n";

        foreach ($this->tables as $table => $t) {
            $class = $this->className($table);
            $src .= "    public static function " . $table . "(): " . $class . "Table\n    {\n"
                . "        \$t = self::\$" . $table . ";\n"
                . "        if (\$t === null) {\n"
                . "            throw new \\RuntimeException(\"配置表 " . $table . " 还没加载，先调 Tables::load()\");\n"
                . "        }\n"
                . "        return \$t;\n    }\n\n";
        }

        $src = rtrim($src, "\n") . "\n}\n";
        return $src;
    }

    private function className(string $table): string
    {
        $parts = explode("_", $table);
        $s = "";
        foreach ($parts as $p) {
            $s .= ucfirst($p);
        }
        return $s;
    }

    // ───────────────────────── 杂项 ─────────────────────────

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            $this->errors[] = "写不进 " . $path;
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->errors[] = "建不了目录 " . $dir;
        }
    }

    private function report(): void
    {
        if ($this->errors === []) {
            $rows = 0;
            foreach ($this->tables as $t) {
                $rows += count($t["rows"]);
            }
            fwrite(STDOUT, "  OK  " . count($this->tables) . " 张表 / " . $rows . " 行，校验通过\n");
            foreach ($this->tables as $name => $t) {
                $bin = $this->binDir . "/" . $name . ".bin";
                $size = is_file($bin) ? filesize($bin) : 0;
                if ($size > 0) {
                    fwrite(STDOUT, "      " . $name . ": " . count($t["rows"]) . " 行 × "
                        . count($t["fields"]) . " 列 → build/config/" . $name . ".bin（"
                        . number_format($size) . " 字节）\n");
                }
            }
            return;
        }

        fwrite(STDERR, "  ✗ 配置表校验失败（" . count($this->errors) . " 处，未写任何产物）\n");
        foreach ($this->errors as $e) {
            fwrite(STDERR, "    " . $e . "\n");
        }
    }
}
