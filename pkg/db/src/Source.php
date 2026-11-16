<?php

namespace TSF\Db;

/**
 * 数据源：给定分片键，返回一个具体的 Db。
 *
 * 为什么要有这一层：单库和分库的**调用代码必须一样**。
 * 如果分库要改业务代码（换成手动挑 Db），那分库就成了一次性改造，
 * 以后想从 4 片扩到 16 片就得再改一遍业务——这种事不该发生。
 *
 * ```php
 * $one   = new Db("mysql:host=db;dbname=game");
 * $many  = new Shards("mysql:host=db;dbname=game_{i}", 4);
 *
 * $repo1 = new Repo(Player::class, $one);    // 单库
 * $repo2 = new Repo(Player::class, $many);   // 按 uid 分 4 片，调用方式完全一样
 * ```
 *
 * 用抽象类而不是 interface：AOT 对抽象类的支持已经在 Service 上验证过了，
 * 而 interface 还没有先例，没必要为了语法上更"纯"去冒编译风险。
 */
abstract class Source
{
    /**
     * 按分片键取库。单库实现直接返回自己——
     * 这样 `Repo` 里不需要任何 `if (分片) ... else ...` 分支。
     */
    abstract public function forKey(int $key): Db;

    /**
     * 全部库。只有「无法按键路由」的操作才用它：建表、迁移、全量统计。
     *
     * 单库返回一个元素，分片返回全部分片。`findAll` / `count` 靠它
     * 在两种部署下都能工作，不用写 if 分支。
     *
     * @return array<int, Db>
     */
    abstract public function every(): array;
}
