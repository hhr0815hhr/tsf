<?php

namespace TSF\Db;

/**
 * 主键。列名默认等于属性名，需要不同名时用 #[Col] 之外的方式会歧义，
 * 所以主键列名固定为属性名——主键叫 `id` 或 `uid` 都是常规做法，
 * 不值得为它加一层映射。
 *
 * `auto: true` 表示自增主键：INSERT 时不写这一列，插入后把 lastInsertId 回填到属性。
 * 默认 false——游戏服的 uid 由业务分配（账号服/雪花号），不是数据库自增。
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Id
{
    public function __construct(public bool $auto = false)
    {
    }
}
