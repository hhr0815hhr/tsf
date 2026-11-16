<?php

namespace TSF\Db;

/**
 * 一个实体类对应一个 Repo。SQL 在构造时拼好，之后每次调用只做「取值 → 绑参」。
 *
 * 第二个参数是 `Source` 而不是 `Db`：单库传 `Db`，分库传 `Shards`，
 * **业务代码一个字都不用改**。这是分库能落地的关键——否则每次扩分片都要改业务。
 *
 * 和 Doctrine / Eloquent 的取舍：
 *
 * | 能力 | 本实现 | 完整 ORM |
 * |:--|:--|:--|
 * | 字段映射 | ✅ Attribute 声明 | ✅ |
 * | 增删改查 | ✅ | ✅ |
 * | 分库分表键 | ✅ `new Shards(...)` | 要额外 bundle |
 * | UnitOfWork（自动追踪变更） | ❌ | ✅ |
 * | 延迟加载 / 关联图 | ❌ | ✅ |
 * | 反射开销 | **只在构造时一次** | 每次 hydrate 都可能查元数据 |
 *
 * 后两样对游戏服是负担而不是便利：帧循环里要求「先 load 再改再 flush」的
 * 写法和「每帧改内存、定期整存」根本对立；延迟加载还会让某一帧突然查库，
 * 表现为卡帧。
 *
 * 用法：
 * ```php
 * $repo = new Repo(Player::class, new Db("sqlite:/data/game.db"));
 * $p = $repo->find(1001);          // ?object，没有就是 null
 * $p->hp = 50;
 * $repo->update($p);               // UPDATE players SET ... WHERE uid = ?
 * ```
 */
final class Repo
{
    private Source $src;

    private Meta $meta;

    private string $sqlFind;

    private string $sqlUpdate;

    private string $sqlInsert;

    private string $sqlDelete;

    private string $sqlAll;

    /** 是否分片部署。影响两件事：自增主键不可用、跨片查询的排序语义 */
    private bool $sharded = false;

    public function __construct(string $cls, Source $src)
    {
        $this->src = $src;

        $meta = Meta::parse($cls);
        $this->meta = $meta;

        $one = $src instanceof Db;
        $this->sharded = !$one;

        // 自增主键 + 分片 = 插入前不知道落哪一片，这是死结，直接拒绝
        if ($meta->idAuto && $this->sharded) {
            throw new \RuntimeException(
                "db: 实体 [" . $cls . "] 用自增主键，不能分片——分片需要主键由业务分配"
            );
        }

        $this->sqlFind = "SELECT * FROM " . $meta->table . " WHERE " . $meta->idCol . " = ?";
        $this->sqlDelete = "DELETE FROM " . $meta->table . " WHERE " . $meta->idCol . " = ?";
        $this->sqlAll = "SELECT * FROM " . $meta->table;

        // INSERT：自增主键不写（由库生成后回填）
        $cols = [];
        $marks = [];
        if (!$meta->idAuto) {
            $cols[] = $meta->idCol;
            $marks[] = "?";
        }
        foreach ($meta->cols as $prop => $col) {
            $cols[] = (string) $col;
            $marks[] = "?";
        }
        $this->sqlInsert = "INSERT INTO " . $meta->table
            . " (" . implode(",", $cols) . ") VALUES (" . implode(",", $marks) . ")";

        // UPDATE：只更新普通列，主键在 WHERE 里
        $sets = [];
        foreach ($meta->cols as $prop => $col) {
            $sets[] = (string) $col . " = ?";
        }
        $this->sqlUpdate = "UPDATE " . $meta->table . " SET " . implode(",", $sets)
            . " WHERE " . $meta->idCol . " = ?";
    }

    public function meta(): Meta
    {
        return $this->meta;
    }

    public function source(): Source
    {
        return $this->src;
    }

    public function isSharded(): bool
    {
        return $this->sharded;
    }

    /**
     * 按主键查一个。没找到返回 null。
     *
     * 返回类型只能是 `?object`——AOT 里没有「动态类名作为返回类型」这回事，
     * 调用方自己知道拿到的应该是什么。
     */
    public function find(int $id): ?object
    {
        // 第三参 true = 复用预处理句柄。find 是游戏服最热的一条路，值得。
        $db = $this->src->forKey($id);
        $rows = $db->select($this->sqlFind, [$id], true);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * 按条件查一批。
     *
     * `$where` 直接拼进 SQL，**只用于固定字符串**（如 "hp < 10"）；
     * 值一律走 `$args` 占位符。不要拿用户输入拼进 `$where`。
     *
     * 分片部署下会扫所有片再合并，且**不支持 ORDER BY / LIMIT**：
     * 那两样一旦下推到单片，得到的是「每片各取 N 条」，跨片顺序是错的。
     * 与其返回一个看起来对、实际错的结果，不如直接拒绝。
     *
     * @return array<int, object>
     */
    public function findAll(string $where = "", array $args = [], string $orderBy = "", int $limit = 0): array
    {
        if ($this->sharded && ($orderBy !== "" || $limit > 0)) {
            throw new \RuntimeException(
                "db: 分片下 findAll 不支持 ORDER BY / LIMIT（会变成每片各取一份），"
                . "请先取出再在内存里排序，或用单库"
            );
        }

        $sql = $this->sqlAll;
        if ($where !== "") {
            $sql .= " WHERE " . $where;
        }
        if ($orderBy !== "") {
            $sql .= " ORDER BY " . $orderBy;
        }
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }

        $out = [];
        foreach ($this->src->every() as $db) {
            if (!$db instanceof Db) {
                continue;
            }
            $rows = $db->select($sql, $args);
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = $this->hydrate($row);
                }
            }
        }
        return $out;
    }

    /** 按条件数行。分片下是各片求和 */
    public function count(string $where = "", array $args = []): int
    {
        $sql = "SELECT COUNT(*) AS c FROM " . $this->meta->table;
        if ($where !== "") {
            $sql .= " WHERE " . $where;
        }

        $total = 0;
        foreach ($this->src->every() as $db) {
            if (!$db instanceof Db) {
                continue;
            }
            $row = $db->first($sql, $args);
            if ($row === [] || !isset($row["c"])) {
                continue;
            }
            $total += (int) $row["c"];
        }
        return $total;
    }

    /** 插入一行，自增主键会回填到实体上（自增 + 分片在构造时已被拒绝） */
    public function insert(object $e): int
    {
        $meta = $this->meta;
        $key = $this->keyOf($this->valueOf($e, $meta->idProp));
        $db = $this->src->forKey($key);

        $args = [];
        if (!$meta->idAuto) {
            $args[] = $this->valueOf($e, $meta->idProp);
        }
        foreach ($meta->cols as $prop => $col) {
            $args[] = $this->valueOf($e, (string) $prop);
        }

        $db->exec($this->sqlInsert, $args, true);

        if ($meta->idAuto) {
            $id = $db->lastId();
            $pr = $meta->props[$meta->idProp];
            if ($pr instanceof \ReflectionProperty) {
                $pr->setValue($e, $id);
            }
            return $id;
        }
        return 0;
    }

    /** 更新，返回受影响行数。0 行说明主键不存在（或值完全没变，MySQL 如此） */
    public function update(object $e): int
    {
        $meta = $this->meta;
        $idVal = $this->valueOf($e, $meta->idProp);
        $db = $this->src->forKey($this->keyOf($idVal));

        $args = [];
        foreach ($meta->cols as $prop => $col) {
            $args[] = $this->valueOf($e, (string) $prop);
        }
        $args[] = $idVal;
        return $db->exec($this->sqlUpdate, $args, true);
    }

    public function delete(int $id): int
    {
        $db = $this->src->forKey($id);
        return $db->exec($this->sqlDelete, [$id], true);
    }

    /**
     * 不管在不在，落成这个状态：先 UPDATE，影响 0 行就 INSERT。
     *
     * 这是「定期整存」最常用的入口——调用方不需要知道玩家是首次载入还是已在库里。
     * 返回 true 表示走了 INSERT。
     *
     * 注意 MySQL 的一个坑：值完全没变时 UPDATE 的 rowCount 也是 0，
     * 会被误判成「不存在」而重复 INSERT（主键冲突）。批量整存时如果实体
     * 可能没变更，请直接用 update()——或者把 `CLIENT_FOUND_ROWS` 打开。
     * 这里不自动处理，因为那个选项要在 DSN/属性层设，影响面更大。
     */
    public function save(object $e): bool
    {
        $n = $this->update($e);
        if ($n > 0) {
            return false;
        }
        $this->insert($e);
        return true;
    }

    /**
     * 批量落库：一个事务里逐条 save。返回插入的条数（其余是更新）。
     *
     * 分片下按分片分组、每组一个事务——不能把不同库包进一个事务，
     * 那需要两阶段提交，代价远大于收益。跨片不保证原子性，这点必须知道。
     */
    public function saveMany(array $list): int
    {
        if ($list === []) {
            return 0;
        }

        $self = $this;
        $inserted = 0;

        if (!$this->sharded) {
            $db = $this->src->forKey(0);
            $db->transaction(function (Db $d) use ($self, $list, &$inserted): void {
                foreach ($list as $e) {
                    if (is_object($e) && $self->save($e)) {
                        $inserted++;
                    }
                }
            });
            return $inserted;
        }

        // 分片：先按片分组，再逐片一个事务
        $groups = [];
        foreach ($list as $e) {
            if (!is_object($e)) {
                continue;
            }
            $k = $this->keyOf($this->valueOf($e, $this->meta->idProp));
            $shard = $k % $this->shardCount();
            if ($shard < 0) {
                $shard += $this->shardCount();
            }
            if (!isset($groups[$shard])) {
                $groups[$shard] = [];
            }
            $groups[$shard][] = $e;
        }

        // 变量名用 $shardNo 而不是 $shard：上面那个循环已经把 $shard 绑成 int，
        // 而 foreach 的 key 在 AOT 里是 Var，同名会报「Int 不能赋 Var」
        foreach ($groups as $shardNo => $items) {
            $db = $this->src->forKey((int) $shardNo);
            $db->transaction(function (Db $d) use ($self, $items, &$inserted): void {
                foreach ($items as $e) {
                    if (is_object($e) && $self->save($e)) {
                        $inserted++;
                    }
                }
            });
        }
        return $inserted;
    }

    private function shardCount(): int
    {
        $s = $this->src;
        if ($s instanceof Shards) {
            return $s->count();
        }
        return 1;
    }

    /**
     * 把主键值转成一个整数，供取模路由。
     *
     * int 直接用；string 主键（uuid / 订单号）用字符和——不追求分布均匀，
     * 只要求**同一个值永远落同一片**（这是分片的唯一硬要求）。
     */
    private function keyOf(int|string|float|bool|null $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v)) {
            $sum = 0;
            $n = strlen($v);
            for ($i = 0; $i < $n; $i++) {
                $sum = ($sum * 31 + ord($v[$i])) & 0x7FFFFFFF;
            }
            return $sum;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return 0;
    }

    /**
     * 把一行关联数组变成实体对象。
     *
     * 不用构造函数（实体的构造函数可能有业务逻辑，比如校验），
     * 属性默认值会保留，所以「库里没这一列」时实体仍然是有意义的。
     */
    private function hydrate(array $row): object
    {
        $meta = $this->meta;
        $rc = $meta->rc;
        if ($rc === null) {
            throw new \RuntimeException("db: 元数据缺失（" . $meta->cls . "）");
        }

        $obj = $rc->newInstanceWithoutConstructor();

        foreach ($meta->colOf as $prop => $col) {
            $key = (string) $col;
            if (!isset($row[$key])) {
                continue;
            }
            $pr = $meta->props[$prop];
            if ($pr instanceof \ReflectionProperty) {
                // setValue 对带类型声明的属性会做弱类型转换："42" → 42
                $pr->setValue($obj, $row[$key]);
            }
        }
        return $obj;
    }

    /** 读实体上的一个属性；属性不存在时返回 null（写进 SQL 会变成 NULL） */
    private function valueOf(object $e, string $prop): int|string|float|bool|null
    {
        $pr = $this->meta->props[$prop];
        if (!$pr instanceof \ReflectionProperty) {
            return null;
        }
        $v = $pr->getValue($e);
        if (is_int($v) || is_string($v) || is_float($v) || is_bool($v)) {
            return $v;
        }
        return null;
    }
}
