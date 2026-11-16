<?php

namespace TSF\Db;

/**
 * 普通列。没带的属性不参与映射——这条很重要：
 *
 * ```php
 * final class Player
 * {
 *     #[Id]  public int $uid = 0;
 *     #[Col] public int $hp = 100;
 *     public string $cache = "";   // 不落库，随便用
 * }
 * ```
 *
 * 实体因此可以带运行时字段（缓存、临时标记），不需要额外的 DTO 层。
 *
 * `#[Col("nick_name")]` 指定列名；不给就用属性名。
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Col
{
    public function __construct(public string $name = "")
    {
    }
}
