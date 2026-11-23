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
     * 副作用能力：由宿主**显式**绑定，脚本只能通过这里"做事"。
     *
     * ## 为什么必须有这一层
     *
     * 原来的 Context 只能读写白名单字段——也就是脚本能改数字，但**发不了奖**。
     * 而活动玩法要的正是"发奖"，于是热更只能停留在"调数值"，一到"做活动"就得重启。
     * 这是热更能力上不去的真实原因，不是"没有脚本引擎"。
     *
     * ## 为什么未绑定就拒绝
     *
     * `reward(350, "daily")` 是脚本在**要求发钱**。如果宿主没明确授予这个能力，
     * 静默成功等于"脚本能给自己发钱"，静默失败等于"活动悄悄不生效"——
     * 两种都比抛异常糟。所以未绑定时抛错，让越权当场暴露。
     *
     * （`log` 是唯一例外：日志失败不该把活动逻辑打挂，见 log()）
     */
    private ?\Closure $onReward = null;

    private ?\Closure $onLog = null;

    /** 宿主授予"发奖"能力。`$fn(int $amount, string $reason): bool` */
    public function bindReward(\Closure $fn): void
    {
        $this->onReward = $fn;
    }

    /** 宿主授予"记日志"能力。`$fn(string $msg): void` */
    public function bindLog(\Closure $fn): void
    {
        $this->onLog = $fn;
    }

    /** 脚本申请发奖。返回 false 表示宿主拒绝（比如额度超限） */
    public function reward(int $amount, string $reason): bool
    {
        $fn = $this->onReward;
        if (!($fn instanceof \Closure)) {
            throw new \RuntimeException("script: 没有发奖能力（宿主未 bindReward）");
        }
        $r = $fn($amount, $reason);
        return $r === true;
    }

    public function canReward(): bool
    {
        return $this->onReward instanceof \Closure;
    }

    /** 脚本写日志。未绑定时静默丢弃——日志失败不该让玩法逻辑崩 */
    public function log(string $msg): void
    {
        $fn = $this->onLog;
        if ($fn instanceof \Closure) {
            $fn($msg);
        }
    }

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
