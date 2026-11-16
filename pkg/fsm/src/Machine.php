<?php

namespace TSF\Fsm;

/**
 * 有限状态机。**独立对象，不绑定任何东西**。
 *
 * ```php
 * $fsm = new Machine(Order::PENDING);
 * $fsm->allow(Order::PENDING, "pay", Order::PAID)
 *     ->allow(Order::PAID,    "ship", Order::SHIPPED);
 *
 * $fsm->fire("pay");     // true
 * $fsm->fire("ship");    // 现在还不允许 → false
 * ```
 *
 * ## 为什么它是独立的包
 *
 * 状态机不是一个"房间专属"的东西。会用到它的至少有：
 *
 * | 载体 | 状态机管什么 |
 * |:--|:--|
 * | 房间 | waiting → playing → settled |
 * | 玩家连接 | 登录中 → 在线 → 断线重连中 → 离线 |
 * | 战斗单位 | 待机 → 追击 → 攻击 → 死亡 |
 * | 匹配票 | 排队 → 匹配中 → 已分配 |
 * | 订单/交易 | 待支付 → 已支付 → 已发货 → 已退款 |
 *
 * 把状态机塞进 `Room` 里，上面这些就只能各写一遍。
 *
 * ## 设计取舍
 *
 * - **状态用 int，事件用字符串**：int 在 AOT 下比较/存数组都稳，字符串让
 *   `fire("start")` 读起来是自解释的，也方便打日志。
 * - **非法转移返回 false 而不是抛异常**：状态机通常挂在高频对象上（一局游戏
 *   可能被发几百个包），客户端乱序包会常态性地触发非法转移。抛异常等于
 *   让一条乱序包打死一个服务。
 * - **转移表用二维数组**（`table[from][event] = to`）而不是拼接 `"from:event"`：
 *   少一次字符串拼接，也让 `can()` 的查找是两层哈希而不是一层。
 * - **不做层次状态机（HFSM）**：那层复杂度要等到真的有"父状态共享转移"的
 *   需求时再加，现在加只是给自己找麻烦。
 */
final class Machine
{
    private int $state;

    /** @var array<int, array<string, int>> from => event => to */
    private array $table = [];

    /** @var array<int, \Closure> state => (int $from, int $to): void */
    private array $onEnter = [];

    /** @var array<int, \Closure> state => (int $from, int $to): void */
    private array $onExit = [];

    public function __construct(int $initial)
    {
        $this->state = $initial;
    }

    /**
     * 声明一条转移。可链式。
     *
     * 同一个 (from, event) 重复声明时后者覆盖前者——这样"热更配置"
     * 可以简单地重放一遍声明，不用先清空。
     */
    public function allow(int $from, string $event, int $to): Machine
    {
        if (!isset($this->table[$from])) {
            $this->table[$from] = [];
        }
        $this->table[$from][$event] = $to;
        return $this;
    }

    public function state(): int
    {
        return $this->state;
    }

    /** 当前状态能不能响应这个事件（不改变状态） */
    public function can(string $event): bool
    {
        $row = $this->table[$this->state] ?? null;
        if (!is_array($row)) {
            return false;
        }
        return isset($row[$event]);
    }

    /**
     * 触发一个事件。成功转移返回 true，非法转移返回 false 且状态不变。
     *
     * 回调的调用顺序：`exit(旧状态)` → 改状态 → `enter(新状态)`。
     * 这个顺序让 enter 里能看到"已经切过去"的状态，符合直觉。
     */
    public function fire(string $event): bool
    {
        $row = $this->table[$this->state] ?? null;
        if (!is_array($row)) {
            return false;
        }
        if (!isset($row[$event])) {
            return false;
        }

        $to = (int) $row[$event];
        $from = $this->state;

        $exit = $this->onExit[$from] ?? null;
        if ($exit instanceof \Closure) {
            $exit($from, $to);
        }

        $this->state = $to;

        $enter = $this->onEnter[$to] ?? null;
        if ($enter instanceof \Closure) {
            $enter($from, $to);
        }
        return true;
    }

    /** 进入某状态时做事（初始化、广播、写日志） */
    public function onEnter(int $state, \Closure $cb): void
    {
        $this->onEnter[$state] = $cb;
    }

    /** 离开某状态时做事（收尾、持久化） */
    public function onExit(int $state, \Closure $cb): void
    {
        $this->onExit[$state] = $cb;
    }

    /**
     * 强制设置状态，**不触发回调**。
     *
     * 只给两个场景用：初始化到非默认状态、从快照恢复。
     * 业务流转一律走 `fire()`——否则状态机就退化成"一个 int 字段"了。
     */
    public function force(int $state): void
    {
        $this->state = $state;
    }

    /** 当前状态能响应的事件名（排障/文档用） */
    public function events(): array
    {
        $row = $this->table[$this->state] ?? null;
        if (!is_array($row)) {
            return [];
        }
        $out = [];
        foreach ($row as $ev => $to) {
            $out[] = (string) $ev;
        }
        return $out;
    }
}
