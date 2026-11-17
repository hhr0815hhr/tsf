<?php

namespace TSF\Db;

/**
 * 版本化迁移：一组有序的 `.sql` 文件，跑过的记在库里。
 *
 * ## 为什么不把迁移做成 PHP 类
 *
 * 迁移是**部署时跑一次**的东西，不需要进服务的二进制。做成 PHP 类就得跟着
 * AOT 编译，还得为"线上版本比代码旧"这种情况准备兼容——成本和收益不成比例。
 * SQL 文件谁都能看懂、能 review、能手工补。
 *
 * ## 方言：一个迁移可以有两份实现
 *
 * ```
 * migrations/
 * ├── 001_create_players.sql            ← 通用（两种库都认）
 * ├── 002_add_vip.sqlite.sql            ← 只在 SQLite 上跑
 * └── 002_add_vip.mysql.sql             ← 只在 MySQL 上跑
 * ```
 *
 * 为什么不搞"占位符替换"：`AUTOINCREMENT` 在 SQLite 里是
 * `INTEGER PRIMARY KEY AUTOINCREMENT`，在 MySQL 里是 `INT AUTO_INCREMENT PRIMARY KEY`——
 * **位置和类型都不一样**，替换是补不出来的。老老实实写两份，看得见、改得对。
 *
 * ## 状态存哪：存库里，不存文件
 *
 * 迁移记录表 `schema_migrations` 跟着数据走。存文件的话，换台机器连同一个库，
 * 工具会以为什么都没跑过——这是最典型的"迁移把线上表重建了"事故来源。
 *
 * 分库时每个分片各有一张记录表：**分片之间可能进度不同**（比如某个分片
 * 迁移到一半失败了），合并成一份就再也对不上。
 */
final class Migrator
{
    /** 记录表名。改它等于让所有历史迁移失效，别改 */
    public const TABLE = "schema_migrations";

    private Source $src;

    private string $dir;

    private int $applied = 0;

    /**
     * 最近一次失败的原因（空字符串表示没有失败）。
     *
     * 迁移失败却「没有任何输出」是最危险的状态 —— 部署脚本照跑、表不存在、
     * 线上报错要等到业务触发。所以失败原因必须留得住。
     */
    public string $lastError = "";

    private int $skipped = 0;

    public function __construct(Source $src, string $dir)
    {
        $this->src = $src;
        $this->dir = rtrim($dir, "/");
    }

    /**
     * 查看状态：有哪些迁移、跑过没有、跑过之后文件有没有被改。
     *
     * 只看第一个分片——多分片进度不一致时用 `statusAll()`。
     */
    public function status(): array
    {
        $db = $this->src->forKey(0);
        return $this->statusOf($db);
    }

    /**
     * 每个分片各自的进度。分片多了会打一堆表，但排障时就靠它。
     *
     * @return array<int, array{shard: int, driver: string, rows: array}>
     */
    public function statusAll(): array
    {
        $out = [];
        $i = 0;
        foreach ($this->src->every() as $db) {
            if (!$db instanceof Db) {
                continue;
            }
            $out[] = [
                "shard" => $i,
                "driver" => $db->driver(),
                "rows" => $this->statusOf($db),
            ];
            $i++;
        }
        return $out;
    }

    /**
     * 执行所有未跑的迁移。`$to > 0` 时只跑到那个版本（含）。
     *
     * 每个迁移在**自己的事务**里执行。注意 MySQL 的 DDL 是隐式提交的，
     * 事务对 `CREATE TABLE` / `ALTER TABLE` 起不到回滚作用——
     * 迁移写错时 MySQL 上会留下半成品，SQLite 上能整体回滚。
     * 这不是工具能解决的，属于引擎差异，写迁移时要心里有数。
     *
     * @return array{shards: int, executed: array, skipped: int, failed: array}
     */
    public function up(int $to = 0): array
    {
        $files = $this->scan();
        $executed = [];
        $failed = [];
        $shards = 0;
        $skipped = 0;

        $i = 0;
        foreach ($this->src->every() as $db) {
            if (!$db instanceof Db) {
                continue;
            }
            $shards++;
            $this->ensureTable($db);
            $done = $this->appliedVersions($db);

            foreach ($files as $version => $variants) {
                if ($to > 0 && $version > $to) {
                    break;
                }
                if (isset($done[$version])) {
                    $skipped++;
                    continue;
                }

                $path = $this->pick($variants, $db->driver());
                if ($path === "") {
                    // 这个方言没有对应实现：跳过而不是报错，
                    // 否则"只想给 SQLite 加一个索引"就没法写
                    continue;
                }

                $ok = $this->runOne($db, $version, $path);
                if ($ok) {
                    $executed[] = $version;
                    $this->applied++;
                } else {
                    $failed[] = ["shard" => $i, "version" => $version, "file" => basename($path)];
                }
            }
            $i++;
        }

        return [
            "shards" => $shards,
            "executed" => $executed,
            "skipped" => $skipped,
            "failed" => $failed,
        ];
    }

    /**
     * 只校验，不改动：返回"已执行但文件内容变了"的迁移。
     *
     * 这是迁移工具最有用的一条防线。改了已经跑过的迁移，新环境会得到
     * 和现有环境**不同的表结构**，而这种不一致往往几个月后才发现。
     */
    public function verify(): array
    {
        $files = $this->scan();
        $bad = [];

        $i = 0;
        foreach ($this->src->every() as $db) {
            if (!$db instanceof Db) {
                continue;
            }
            $this->ensureTable($db);
            $rows = $this->appliedRows($db);
            foreach ($rows as $row) {
                $version = (int) $row["version"];
                if (!isset($files[$version])) {
                    $bad[] = ["shard" => $i, "version" => $version, "why" => "已执行但文件不存在"];
                    continue;
                }
                $path = $this->pick($files[$version], $db->driver());
                if ($path === "") {
                    continue;
                }
                $sum = $this->checksumOf($path);
                if ($sum !== (string) $row["checksum"]) {
                    $bad[] = [
                        "shard" => $i,
                        "version" => $version,
                        "why" => "文件已改动（记录 " . $row["checksum"] . "，现在 " . $sum . "）",
                    ];
                }
            }
            $i++;
        }
        return $bad;
    }

    public function stats(): array
    {
        return ["applied" => $this->applied, "skipped" => $this->skipped];
    }

    // ---------------------------------------------------------------- 内部

    private function statusOf(Db $db): array
    {
        $this->ensureTable($db);
        $files = $this->scan();
        $rows = $this->appliedRows($db);

        $appliedMap = [];
        foreach ($rows as $r) {
            $appliedMap[(int) $r["version"]] = $r;
        }

        $out = [];
        foreach ($files as $version => $variants) {
            $path = $this->pick($variants, $db->driver());
            $applied = isset($appliedMap[$version]);
            $sumOk = true;
            if ($applied && $path !== "") {
                $sumOk = (string) $appliedMap[$version]["checksum"] === $this->checksumOf($path);
            }
            $out[] = [
                "version" => $version,
                "name" => $this->nameOf($variants),
                "file" => $path === "" ? "" : basename($path),
                "applied" => $applied,
                "checksum_ok" => $sumOk,
            ];
        }
        return $out;
    }

    private function runOne(Db $db, int $version, string $path): bool
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return false;
        }
        $stmts = $this->split($this->stripComments($raw));
        if ($stmts === []) {
            return false;
        }

        $sum = hash("crc32b", $raw);
        $name = basename($path);

        try {
            if ($db->driver() === "mysql") {
                // MySQL 的 DDL 会**隐式提交**当前事务：把 CREATE TABLE 包在
                // transaction() 里，commit 时会发现「没有活动事务」而抛错，
                // 结果整条迁移失败、表没建，而 up() 又把这个异常吞掉了 ——
                // 表现为「迁移报告执行成功、表根本不存在」，且 sqlite 上永远复现不了。
                //
                // 所以 MySQL 这条路径不做事务包裹：DDL 直接执行，版本记录单独写。
                // 代价是「DDL 成功但记录失败」时会留下一个未记录的表；
                // 用 IF NOT EXISTS 重跑一次即可（迁移语句本身要求幂等）。
                foreach ($stmts as $one) {
                    $db->exec((string) $one);
                }
                $db->exec(
                    "INSERT INTO " . self::TABLE . " (version, name, applied_at, checksum) VALUES (?, ?, ?, ?)",
                    [$version, $name, time(), $sum]
                );
                return true;
            }

            $db->transaction(function (Db $d) use ($stmts, $version, $name, $sum): void {
                foreach ($stmts as $one) {
                    $d->exec((string) $one);
                }
                $d->exec(
                    "INSERT INTO " . self::TABLE . " (version, name, applied_at, checksum) VALUES (?, ?, ?, ?)",
                    [$version, $name, time(), $sum]
                );
            });
            return true;
        } catch (\Throwable $e) {
            $this->lastError = basename($path) . ": " . $e->getMessage();
            return false;
        }
    }

    /** 建记录表。用 IF NOT EXISTS，所以每次跑都安全 */
    private function ensureTable(Db $db): void
    {
        $driver = $db->driver();
        if ($driver === "mysql") {
            $db->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                version    INT PRIMARY KEY,
                name       VARCHAR(200) NOT NULL,
                applied_at BIGINT NOT NULL,
                checksum   VARCHAR(16) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        // SQLite 及其它
        $db->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            version    INTEGER PRIMARY KEY,
            name       TEXT NOT NULL,
            applied_at INTEGER NOT NULL,
            checksum   TEXT NOT NULL
        )");
    }

    private function appliedRows(Db $db): array
    {
        try {
            return $db->select("SELECT version, name, applied_at, checksum FROM " . self::TABLE . " ORDER BY version");
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int, true> version => true */
    private function appliedVersions(Db $db): array
    {
        $out = [];
        foreach ($this->appliedRows($db) as $r) {
            $out[(int) $r["version"]] = true;
        }
        return $out;
    }

    /**
     * 扫目录，返回 version => [driver => path]。
     *
     * 目录不存在时返回空数组而不是抛异常：有些工程还没写迁移，
     * 让它启动失败没道理。
     */
    private function scan(): array
    {
        $out = [];
        if (!is_dir($this->dir)) {
            return $out;
        }
        $names = scandir($this->dir);
        if (!is_array($names)) {
            return $out;
        }

        foreach ($names as $n) {
            $file = (string) $n;
            $info = $this->parseName($file);
            if ($info === null) {
                continue;
            }
            $version = (int) $info["version"];
            $driver = (string) $info["driver"];
            if (!isset($out[$version])) {
                $out[$version] = [];
            }
            $out[$version][$driver] = $this->dir . "/" . $file;
        }

        ksort($out);
        return $out;
    }

    /**
     * 从文件名解析版本与方言。
     *
     * `001_create_players.sql`        → v1, driver ""
     * `002_add_vip.sqlite.sql`        → v2, driver "sqlite"
     * `003_x.mysql.sql`               → v3, driver "mysql"
     */
    private function parseName(string $file): ?array
    {
        if (!str_ends_with($file, ".sql")) {
            return null;
        }
        $base = substr($file, 0, -4);   // 去掉 .sql

        $driver = "";
        if (str_ends_with($base, ".sqlite")) {
            $driver = "sqlite";
            $base = substr($base, 0, -7);
        } elseif (str_ends_with($base, ".mysql")) {
            $driver = "mysql";
            $base = substr($base, 0, -6);
        } elseif (str_ends_with($base, ".pgsql")) {
            $driver = "pgsql";
            $base = substr($base, 0, -6);
        }

        $pos = strpos($base, "_");
        if ($pos === false) {
            return null;
        }
        $verStr = substr($base, 0, $pos);
        if ($verStr === "" || preg_match('/^\d+$/', $verStr) !== 1) {
            return null;
        }
        return ["version" => (int) $verStr, "driver" => $driver, "name" => substr($base, $pos + 1)];
    }

    /** 选方言文件：优先精确匹配，其次通用实现 */
    private function pick(array $variants, string $driver): string
    {
        $exact = $variants[$driver] ?? null;
        if (is_string($exact)) {
            return $exact;
        }
        $common = $variants[""] ?? null;
        if (is_string($common)) {
            return $common;
        }
        return "";
    }

    private function nameOf(array $variants): string
    {
        foreach ($variants as $path) {
            $info = $this->parseName(basename((string) $path));
            if ($info !== null) {
                return (string) $info["name"];
            }
        }
        return "";
    }

    /**
     * 去掉整行的 `--` 注释。
     *
     * 只处理"整行注释"：行尾注释的判定要理解 SQL 语境，而迁移脚本里
     * 行尾注释很少见，为它引入一个半吊子解析器不值得。
     */
    private function stripComments(string $sql): string
    {
        $out = "";
        foreach (explode("\n", $sql) as $ln) {
            $t = ltrim($ln);
            if ($t === "" || str_starts_with($t, "--")) {
                continue;
            }
            $out .= $ln . "\n";
        }
        return $out;
    }

    /**
     * 按 `;` 切成单条，**引号内的分号不切**。
     *
     * 需要状态机而不是 `explode(";")`，因为 `INSERT INTO t VALUES ('a;b')`
     * 是合法 SQL——按分号硬切会把它切成两半，然后两条都语法错误。
     */
    private function split(string $sql): array
    {
        $out = [];
        $buf = "";
        $inStr = false;
        $quote = "";
        $n = strlen($sql);

        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            if ($inStr) {
                $buf .= $c;
                if ($c === $quote) {
                    $inStr = false;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === "`") {
                $inStr = true;
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ";") {
                $stmt = trim($buf);
                if ($stmt !== "") {
                    $out[] = $stmt;
                }
                $buf = "";
                continue;
            }
            $buf .= $c;
        }

        $tail = trim($buf);
        if ($tail !== "") {
            $out[] = $tail;
        }
        return $out;
    }

    private function checksumOf(string $path): string
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return "";
        }
        return hash("crc32b", $raw);
    }
}
