<?php

namespace TSF\Db;

/**
 * 一个 PDO 连接的薄封装。只做三件 PDO 自己不好好做的事：
 *
 *   1. **懒连接**——服务启动时不连库，第一次查询才连。库挂了不影响进程起来，
 *      也不影响不需要库的地方（比如大厅）。
 *   2. **断线重连**——MySQL 侧 `wait_timeout` 到点会单方面掐连接，
 *      下一次查询报 2006/2013。这里重连并重试一次。
 *   3. **事务深度**——业务里常有「外层事务里调一个也开事务的函数」，
 *      重入时只计数不开真的 SAVEPOINT。
 *
 * `ATTR_EMULATE_PREPARES = false` 是刻意的：走服务端预处理，
 * 参数和 SQL 分开传输，免掉一整套转义问题。
 */
final class Db extends Source
{
    private string $dsn;

    private string $user;

    private string $pass;

    /** 可空：AOT 下未连接状态需要一个明确的"空"表示 */
    private ?\PDO $pdo = null;

    /** 事务深度；>0 时**不允许**重连重试（重试会丢事务） */
    private int $txDepth = 0;

    private int $queries = 0;

    private int $connects = 0;

    private int $retries = 0;

    /**
     * 预处理语句缓存：同一条 SQL 反复执行时省掉 prepare。
     *
     * 只对显式传 `$reuse = true` 的调用生效。默认不复用是刻意的——
     * 动态拼出来的 SQL（报表、后台查询）每条都不一样，塞进缓存只会
     * 撑大内存并在重连时白白丢弃；而 Repo 那几条固定 SQL 才是热点。
     *
     * @var array<string, \PDOStatement>
     */
    private array $stmts = [];

    public function __construct(string $dsn, string $user = "", string $pass = "")
    {
        $this->dsn = $dsn;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * 单库：不分片，永远返回自己。
     *
     * 有了这个方法，`Repo` 里就不需要区分「单库」和「分片」两条路径——
     * 业务代码写 `new Repo(Player::class, $anySource)` 在两种部署下都成立。
     */
    public function forKey(int $key): Db
    {
        return $this;
    }

    /** 单库：只有自己一个 */
    public function every(): array
    {
        return [$this];
    }

    /**
     * 方言名：`sqlite` / `mysql` / `pgsql` ……
     *
     * 从 DSN 前缀取（`sqlite:/x.db` → `sqlite`）。迁移工具靠它选方言文件——
     * 同一个 `AUTOINCREMENT` 在 SQLite 和 MySQL 里位置都不一样，
     * 靠字符串替换是补不出来的。
     */
    public function driver(): string
    {
        $pos = strpos($this->dsn, ":");
        if ($pos === false) {
            return "";
        }
        return substr($this->dsn, 0, $pos);
    }

    /** DSN 原文（排障用；含密码，别直接写日志） */
    public function dsn(): string
    {
        return $this->dsn;
    }

    /**
     * 取连接，没有就建。
     *
     * **必须是懒连接**，不只是为了启动快：Swoole 是多进程（master fork 出 worker），
     * 在 fork 之前建立的 socket 会被所有子进程共享，多个进程同时读写同一个连接
     * 会串包。懒连接保证每个 worker 在自己的进程里第一次用时才建。
     *
     * 单独提出来是因为 `$this->pdo` 是可空的，而 AOT 的类型收窄
     * 不认 `if ($this->pdo !== null) { return $this->pdo; }` 这种跨属性访问，
     * 先落到局部变量再判断才稳。
     */
    public function conn(): \PDO
    {
        $p = $this->pdo;
        if ($p instanceof \PDO) {
            return $p;
        }

        $pdo = new \PDO($this->dsn, $this->user, $this->pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo = $pdo;
        $this->connects++;
        return $pdo;
    }

    /**
     * 查多行。返回行数组；无结果是空数组，不返回 false。
     *
     * `$reuse = true` 会复用这条 SQL 的预处理句柄——同一条 SQL 反复执行
     * （比如按主键查）能省掉每次的 prepare。
     */
    public function select(string $sql, array $args = [], bool $reuse = false): array
    {
        $st = $this->stmt($sql, $args, $reuse);
        $rows = $st->fetchAll();
        $st->closeCursor();
        if (is_array($rows)) {
            return $rows;
        }
        return [];
    }

    /** 查第一行。空数组表示没有——不用 null，省掉调用方一层判断 */
    public function first(string $sql, array $args = [], bool $reuse = false): array
    {
        $st = $this->stmt($sql, $args, $reuse);
        $row = $st->fetch();
        $st->closeCursor();
        if (is_array($row)) {
            return $row;
        }
        return [];
    }

    /** 查单值。没有结果时返回 $default */
    public function value(string $sql, array $args = [], int|string $default = 0): int|string
    {
        $row = $this->first($sql, $args);
        if ($row === []) {
            return $default;
        }
        $v = array_values($row);
        if ($v === []) {
            return $default;
        }
        $first = $v[0];
        if (is_int($first) || is_string($first)) {
            return $first;
        }
        return $default;
    }

    /** 执行写语句，返回受影响行数 */
    public function exec(string $sql, array $args = [], bool $reuse = false): int
    {
        // 没有参数就不需要预处理：直接走 PDO::exec。
        //
        // 这不只是省一次 prepare —— **MySQL 的服务端预处理不支持 DDL**：
        // `PREPARE` 一个 CREATE TABLE 会报
        //   SQLSTATE[HY000]: General error: 1295 This command is not supported
        //   in the prepared statement protocol yet
        // 而 Migrator 正是用 exec() 跑建表语句的，异常又被它的 catch 吞掉，
        // 结果是「up() 报告执行了、表根本没建、没有任何报错」。
        // 这个 bug 在 sqlite 上永远暴露不出来（sqlite 的 prepare 允许 DDL）。
        if ($args === []) {
            $n = $this->conn()->exec($sql);
            return is_int($n) ? $n : 0;
        }

        $st = $this->stmt($sql, $args, $reuse);
        $n = $st->rowCount();
        $st->closeCursor();
        return $n;
    }

    /**
     * 插入一行，返回自增主键（非自增表返回 0，用 lastId() 取也一样）。
     *
     * `$row` 是 列名 => 值。不要传空数组——生成的 SQL 会语法错误，
     * 这里提前拦掉，比让数据库报错好定位。
     */
    public function insert(string $table, array $row): int
    {
        if ($row === []) {
            throw new \RuntimeException("db: insert 的列清单为空，表 [" . $table . "]");
        }

        $cols = [];
        $marks = [];
        $vals = [];
        foreach ($row as $k => $v) {
            $name = (string) $k;
            $cols[] = $name;
            $marks[] = "?";
            $vals[] = $v;
        }

        $sql = "INSERT INTO " . $table . " (" . implode(",", $cols) . ")"
            . " VALUES (" . implode(",", $marks) . ")";
        $this->exec($sql, $vals);
        return $this->lastId();
    }

    /**
     * 批量插入：一条 SQL 多组 VALUES。
     *
     * 为什么必须支持：游戏服定期整存时是一批实体一起落库，
     * 逐条 INSERT 会把 N 次往返变成 N 次（哪怕同一个连接）。
     *
     * 分批是必须的——SQLite 默认只允许 999 个绑定参数，
     * 30 列的表只能塞 33 行。按参数个数的上限切，不按行数切。
     *
     * @param array<int, array<string, mixed>> $rows 所有行必须有相同的键与顺序
     * @return int 受影响行数
     */
    public function insertMany(string $table, array $rows, int $maxParams = 900): int
    {
        if ($rows === []) {
            return 0;
        }

        $first = $rows[0];
        if ($first === []) {
            throw new \RuntimeException("db: insertMany 的列清单为空，表 [" . $table . "]");
        }

        $cols = [];
        foreach ($first as $k => $v) {
            $cols[] = (string) $k;
        }
        $width = count($cols);
        if ($width === 0) {
            throw new \RuntimeException("db: insertMany 无法确定列，表 [" . $table . "]");
        }

        $perBatch = intdiv($maxParams, $width);
        if ($perBatch < 1) {
            $perBatch = 1;
        }

        $one = "(" . implode(",", array_fill(0, $width, "?")) . ")";
        $head = "INSERT INTO " . $table . " (" . implode(",", $cols) . ") VALUES ";

        $total = 0;
        $n = count($rows);
        $off = 0;
        while ($off < $n) {
            $take = $n - $off;
            if ($take > $perBatch) {
                $take = $perBatch;
            }

            $groups = [];
            $vals = [];
            for ($i = 0; $i < $take; $i++) {
                $r = $rows[$off + $i];
                foreach ($cols as $c) {
                    $vals[] = isset($r[$c]) ? $r[$c] : null;
                }
                $groups[] = $one;
            }

            $sql = $head . implode(",", $groups);
            $total += $this->exec($sql, $vals);
            $off += $take;
        }
        return $total;
    }

    /** 最后一次插入生成的自增 id */
    public function lastId(): int
    {
        $id = $this->conn()->lastInsertId();
        if ($id === false) {
            return 0;
        }
        return (int) $id;
    }

    /**
     * 事务。可重入：嵌套调用只在外层真正 BEGIN/COMMIT。
     *
     * 用「深度」而不是 SAVEPOINT，是因为业务里的嵌套几乎都是
     * 「函数 A 里开事务，A 里调了也会开事务的 B」，语义上就该合成为一个事务。
     * 真正的部分回滚需求目前没有。
     */
    public function transaction(\Closure $fn): void
    {
        $pdo = $this->conn();

        if ($this->txDepth === 0) {
            $pdo->beginTransaction();
        }
        $this->txDepth++;

        try {
            $fn($this);
            if ($this->txDepth === 1) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->txDepth === 1) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $ignored) {
                    // MySQL 里 DDL 会隐式提交事务，此时 rollBack 会报
                    // 「There is no active transaction」。这不是本函数该关心的事，
                    // 吞掉即可，但**不能让它跳过后面的 txDepth--**。
                }
            }
            $this->txDepth--;
            throw $e;
        }
        // 必须放在 try 外：原来写在 try 里，commit() 一抛异常就跳过了这一句，
        // txDepth 永久 +1，之后所有 transaction() 都不再 begin/commit ——
        // 表现是「事务静默失效」，很难查。
        $this->txDepth--;
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    /**
     * 主动断开（进程退出、或想强制换连接时用）。
     *
     * 必须连语句缓存一起清——缓存的 PDOStatement 绑在旧连接上，
     * 留着它们既让旧连接无法真正释放，又会在下次执行时报错。
     */
    public function close(): void
    {
        $this->pdo = null;
        $this->stmts = [];
    }

    public function stats(): array
    {
        return [
            "queries" => $this->queries,
            "connects" => $this->connects,
            "retries" => $this->retries,
            "tx_depth" => $this->txDepth,
        ];
    }

    /**
     * 准备并执行。
     *
     * 三种情况：
     *   1. 命中复用的句柄 → 直接 execute（省掉 prepare，这是热路径）
     *   2. 没命中 → prepare + execute，`$reuse` 时放进缓存
     *   3. 连接断了 → 重连一次重来。**事务里不重试**，重试会把已执行的语句再做一遍
     */
    private function stmt(string $sql, array $args, bool $reuse): \PDOStatement
    {
        if ($reuse) {
            $cached = $this->stmts[$sql] ?? null;
            if ($cached instanceof \PDOStatement) {
                try {
                    $cached->execute($args);
                    $this->queries++;
                    return $cached;
                } catch (\PDOException $e) {
                    unset($this->stmts[$sql]);
                    if ($this->txDepth > 0 || !self::isLost($e)) {
                        throw $e;
                    }
                    // 连接已断：清空全部句柄（它们都绑在旧连接上），走下面的重建分支
                    $this->retries++;
                    $this->pdo = null;
                    $this->stmts = [];
                }
            }
        }

        $pdo = $this->conn();
        try {
            $st = $this->run($pdo, $sql, $args);
        } catch (\PDOException $e) {
            if ($this->txDepth > 0 || !self::isLost($e)) {
                throw $e;
            }
            $this->retries++;
            $this->pdo = null;
            $this->stmts = [];
            $st = $this->run($this->conn(), $sql, $args);
        }

        if ($reuse) {
            $this->stmts[$sql] = $st;
        }
        return $st;
    }

    private function run(\PDO $pdo, string $sql, array $args): \PDOStatement
    {
        $st = $pdo->prepare($sql);
        if (!$st instanceof \PDOStatement) {
            throw new \RuntimeException("db: prepare 失败 [" . substr($sql, 0, 80) . "]");
        }
        $st->execute($args);
        $this->queries++;
        return $st;
    }

    /**
     * 判断异常是不是「连接没了」。
     *
     * 2006 server has gone away / 2013 lost connection 是 MySQL 的；
     * SQLite 没有这个概念（它是本地文件），所以不在名单里——
     * 把 SQLite 的 `database is locked`(5) 误判成断线去重连，只会更乱。
     */
    private static function isLost(\PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (!is_array($info) || !isset($info[1])) {
            return false;
        }
        $code = (int) $info[1];
        return $code === 2006 || $code === 2013;
    }
}
