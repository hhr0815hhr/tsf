<?php

namespace TSF\Script;

/**
 * 脚本能看到的「世界视图」。
 *
 * ## 为什么不直接传 Player
 *
 * 直接把业务对象传给脚本，脚本就能：
 *   - 读任意 public 属性
 *   - 写任意 public 属性
 *   - 调任意 public 方法
 *
 * 等于**没有约束**。Lua 时代很多项目就是这么失控的：脚本里
 * `player->hp = 99999`，绕过所有校验，最后只能靠 code review 兜。
 *
 * 这个类把「能读什么、能写什么」变成显式声明 —— 越权的读写会在运行时抛错，
 * 而不是悄悄改掉数据。
 *
 * ## 用法
 *
 * ```php
 * // 宿主侧
 * $ctx = new Context($player, ["uid", "level", "gold"], ["gold"]);
 * $script->callWithContext("onQuest", $ctx);
 *
 * // 脚本里
 * return function (Context $ctx) {
 *     $ctx->addInt("gold", 350);      // 允许（gold 在可写列表）
 *     $ctx->setInt("level", 99);      // 抛错（level 只读）
 * };
 * ```
 *
 * ## 为什么不用 __get / __set
 *
 * 魔术方法在 AOT 下行为不确定（编译期无法确定属性访问），而且会让"哪些字段可访问"
 * 从签名里消失。显式方法虽然啰嗦，但契约清楚、能被 lint 检查。
 */
final class Context
{
    /** @var array<string, true> 可读字段 */
    private array $readable = [];

    /** @var array<string, true> 可写字段 */
    private array $writable = [];

    private object $target;

    /**
     * @param object $target   业务对象（Player / Room 等）
     * @param list<string> $readable 允许读的字段
     * @param list<string> $writable 允许写的字段（必须是 readable 的子集）
     */
    public function __construct(object $target, array $readable, array $writable)
    {
        $this->target = $target;

        foreach ($readable as $f) {
            $this->readable[(string) $f] = true;
        }
        foreach ($writable as $f) {
            $name = (string) $f;
            // 可写必须是可读的子集，否则脚本能写但读不回来，逻辑会很怪
            if (!isset($this->readable[$name])) {
                throw new \InvalidArgumentException("writable field not in readable list: " . $name);
            }
            $this->writable[$name] = true;
        }
    }

    /** 读 int 字段。字段不在白名单时抛错。 */
    public function getInt(string $field): int
    {
        $this->assertReadable($field);
        $vars = get_object_vars($this->target);
        $v = $vars[$field] ?? 0;
        return is_int($v) ? $v : (int) $v;
    }

    /** 读 string 字段。 */
    public function getString(string $field): string
    {
        $this->assertReadable($field);
        $vars = get_object_vars($this->target);
        $v = $vars[$field] ?? "";
        return is_string($v) ? $v : (string) $v;
    }

    /** 写 int 字段。字段不在可写列表时抛错。 */
    public function setInt(string $field, int $value): void
    {
        $this->assertWritable($field);
        $this->target->{$field} = $value;
    }

    /**
     * 原子累加。**推荐脚本用这个而不是 getInt + setInt**。
     *
     * 虽然当前是单线程协程模型，读改写天然原子；但把意图写成 addInt
     * 以后加锁/加校验都只改一处。
     */
    public function addInt(string $field, int $delta): void
    {
        $this->assertWritable($field);
        $cur = $this->getInt($field);
        $this->target->{$field} = $cur + $delta;
    }

    /** 字段是否在可读白名单（脚本可以据此做分支，不必靠异常） */
    public function canRead(string $field): bool
    {
        return isset($this->readable[$field]);
    }

    public function canWrite(string $field): bool
    {
        return isset($this->writable[$field]);
    }

    /** @return list<string> */
    public function readableFields(): array
    {
        $out = [];
        foreach ($this->readable as $k => $_) {
            $out[] = $k;
        }
        return $out;
    }

    private function assertReadable(string $field): void
    {
        if (!isset($this->readable[$field])) {
            throw new \RuntimeException("script cannot read field: " . $field);
        }
    }

    private function assertWritable(string $field): void
    {
        if (!isset($this->writable[$field])) {
            throw new \RuntimeException("script cannot write field: " . $field);
        }
    }
}
