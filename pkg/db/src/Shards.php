<?php

namespace TSF\Db;

/**
 * 分库：按分片键取模路由。
 *
 * DSN 模板里用 `{i}` 表分片序号，例如：
 *
 * ```php
 * new Shards("mysql:host=10.0.0.1;dbname=game_{i}", 4);   // game_0 .. game_3
 * new Shards("sqlite:/data/shard_{i}.db", 8);
 * ```
 *
 * **何时才需要它**：单表超过千万行、或单库连接数打满。分片一旦上线就很难改，
 * 所以别过早用——先用 `Db`，真到瓶颈再换，因为 `Repo` 的调用代码不用改。
 *
 * 一条硬约束：**分片后主键必须由业务分配**。自增主键在分片下没有意义
 * （不知道插哪一片），`#[Id(true)]` 与分库不兼容，`Repo` 会直接拒绝。
 */
final class Shards extends Source
{
    private string $dsnTemplate;

    private int $count;

    private string $user;

    private string $pass;

    /** @var array<int, Db> 已建连接的片，懒建 */
    private array $dbs = [];

    public function __construct(string $dsnTemplate, int $count, string $user = "", string $pass = "")
    {
        if ($count < 1) {
            throw new \RuntimeException("db: 分片数必须 >= 1，给了 " . $count);
        }
        if (!str_contains($dsnTemplate, "{i}")) {
            throw new \RuntimeException("db: 分片 DSN 模板必须包含 {i}，当前 [" . $dsnTemplate . "]");
        }
        $this->dsnTemplate = $dsnTemplate;
        $this->count = $count;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * 取模路由。负数要归一化——PHP 的 `%` 对负数返回负值，
     * 不处理会出现 `-1 % 4 = -1` 这种索引。
     */
    public function forKey(int $key): Db
    {
        $i = $key % $this->count;
        if ($i < 0) {
            $i += $this->count;
        }
        return $this->dbOf($i);
    }

    public function count(): int
    {
        return $this->count;
    }

    /** 分片序号（调试/日志用）：`Shards::of(uid)` 能看出这条数据落在哪片 */
    public function shardOf(int $key): int
    {
        $i = $key % $this->count;
        if ($i < 0) {
            $i += $this->count;
        }
        return $i;
    }

    /**
     * 所有分片的连接。**建表 / 迁移 / 全量统计**用，
     * 业务查询不要用它——那等于放弃分片的意义。
     *
     * @return array<int, Db>
     */
    public function all(): array
    {
        $out = [];
        for ($i = 0; $i < $this->count; $i++) {
            $out[] = $this->dbOf($i);
        }
        return $out;
    }

    /** `Source` 要求：跨片聚合（findAll / count）走这条 */
    public function every(): array
    {
        return $this->all();
    }

    /** 对每个分片执行同一段 DDL（建表、加索引） */
    public function each(\Closure $fn): void
    {
        foreach ($this->all() as $db) {
            $fn($db);
        }
    }

    public function closeAll(): void
    {
        foreach ($this->dbs as $db) {
            if ($db instanceof Db) {
                $db->close();
            }
        }
        $this->dbs = [];
    }

    public function stats(): array
    {
        $q = 0;
        $c = 0;
        $r = 0;
        foreach ($this->dbs as $db) {
            if (!$db instanceof Db) {
                continue;
            }
            $s = $db->stats();
            $q += (int) $s["queries"];
            $c += (int) $s["connects"];
            $r += (int) $s["retries"];
        }
        return ["shards" => $this->count, "opened" => count($this->dbs), "queries" => $q, "connects" => $c, "retries" => $r];
    }

    private function dbOf(int $i): Db
    {
        $db = $this->dbs[$i] ?? null;
        if ($db instanceof Db) {
            return $db;
        }
        $dsn = str_replace("{i}", (string) $i, $this->dsnTemplate);
        $new = new Db($dsn, $this->user, $this->pass);
        $this->dbs[$i] = $new;
        return $new;
    }
}
