<?php

namespace TSF\Db;

/**
 * 标记实体对应的表名。没有这个 Attribute 的类不能进 Repo。
 *
 * 参数按位置读（`#[Table("players")]`），不支持命名参数——把选择面收窄，
 * 元数据解析就不用处理一堆边界情况。
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(public string $name)
    {
    }
}
