<?php

namespace TSF\Snapshot;

/**
 * 状态版本迁移链：把旧快照"升级"到当前代码认识的样子。
 *
 * ## 它补的是 T2 滚动重启缺的那一环
 *
 * 类不可在进程内重定义，所以改逻辑只能滚动重启。但重启前后的**状态结构**经常不同：
 * 老二进制写的快照里没有 `hp`，新代码默认它有，读出来就是缺字段的"半坏"对象。
 *
 * Erlang 用 `code_change(OldVsn, State, Extra)` 解决这件事。机制抄不了，
 * **协议可以抄**：
 *
 * ```
 * 快照里带 _v → 缺失视为 0 → 按登记的顺序逐级升级 → 最终 _v 等于当前版本
 * ```
 *
 * ## 为什么用"阈值"而不是"逐版本"
 *
 * 登记的是"把 < N 的都升到 N"（`step(5, fn)`、`step(7, fn)`），而不是 0→1、1→2 每一级
 * 都写一个函数。理由很实际：结构改动是稀疏的，v1→v4 之间可能什么都没变，
 * 逼着写三个恒等函数只会让人偷懒写成一个大函数，反而丢了可追溯性。
 *
 * 登记器不检查"这些阈值是否连续"——稀疏本来就是允许的。它只保证
 * **执行顺序按阈值升序**，以及**漏到当前版本以下的会报错**（见 `migrate`）。
 *
 * ## 三条纪律（写在这里，因为它们是这套东西的全部价值）
 *
 * 1. **迁移函数必须是纯函数**：同样的输入永远同样的输出，不读时间、不读全局。
 *    否则"重放同一个快照两次得到不同结果"，排障时无法对拍。
 * 2. **只向前**。`_v` 比当前代码新时**拒绝**，而不是尽力而为地读——
 *    那是新二进制写的，硬读会把不认识的字段丢掉后再写回去，等于静默降级。
 * 3. **破坏性字段变更分两步**：先双写新旧字段 → 滚动重启 → 再清理旧字段。
 *    这样滚动窗口内新旧二进制都能读（等价于 Erlang 的两版本共存）。
 *    这条不是代码能强制的，只能写在这里。
 */
final class Schema
{
    /** 当前代码认识的最高版本 */
    private int $current;

    /** @var array<int, \Closure> "升到 N"的函数，键是 N */
    private array $steps = [];

    /** @var array<int, int> $steps 的键，升序缓存 */
    private array $order = [];

    /** 版本字段名。用 `_v` 而不是 `v`：业务字段不该和元信息撞名 */
    public const VERSION_KEY = "_v";

    /** @var array<string, int> */
    private array $stat = [
        "migrated" => 0,
        "untouched" => 0,
        "rejected" => 0,
    ];

    public function __construct(int $current)
    {
        $this->current = $current > 0 ? $current : 1;
    }

    public function current(): int
    {
        return $this->current;
    }

    /**
     * 登记"把 < $to 的状态升到 $to"。
     *
     * @param \Closure $fn function (array $state): array —— 必须是纯函数
     */
    public function step(int $to, \Closure $fn): void
    {
        if ($to <= 0 || $to > $this->current) {
            throw new \RuntimeException(
                "schema: 迁移目标 " . $to . " 超出当前版本 " . $this->current
            );
        }
        $this->steps[$to] = $fn;
        $order = array_keys($this->steps);
        sort($order);
        $this->order = $order;
    }

    /**
     * 把一份状态升到当前版本。返回新数组，不修改入参。
     *
     * 抛异常而不是返回 false：迁移失败意味着"这份数据读不懂"，
     * 调用方该决定是丢弃还是保留待人工处理，而不是拿到一份看起来正常、
     * 实际缺字段的状态继续跑。
     *
     * @param string $what 用于报错的标识（快照种类/块名）
     * @param array<string, array> $state
     *
     * @return array<string, array>
     */
    public function migrate(string $what, array $state): array
    {
        $from = isset($state[self::VERSION_KEY]) ? (int) $state[self::VERSION_KEY] : 0;

        if ($from > $this->current) {
            $this->stat["rejected"]++;
            throw new \RuntimeException(
                "schema: [" . $what . "] 版本 " . $from . " 比当前代码 " . $this->current
                . " 还新——这是新二进制写的快照，拒绝降级读取（硬读会丢字段后再写回）"
            );
        }
        if ($from === $this->current) {
            $this->stat["untouched"]++;
            return $state;
        }

        $out = $state;
        $v = $from;
        foreach ($this->order as $to) {
            if ($v >= $to) {
                continue;
            }
            $fn = $this->steps[$to];
            $out = $fn($out);
            $v = $to;
        }

        if ($v < $this->current) {
            // 登记表漏了一段。这里必须报错：放过去就等于把旧结构当成新结构用
            $this->stat["rejected"]++;
            throw new \RuntimeException(
                "schema: [" . $what . "] 缺少到版本 " . $this->current . " 的迁移（停在 " . $v . "）"
            );
        }
        $out[self::VERSION_KEY] = $this->current;
        $this->stat["migrated"]++;
        return $out;
    }

    /** 一份快照的所有块都过一遍迁移 */
    public function migrateSnapshot(Snapshot $snap): void
    {
        foreach ($snap->blocks() as $name) {
            $block = $snap->get($name);
            if ($block === []) {
                continue;   // 空块没什么可升的
            }
            $snap->put($name, $this->migrate($snap->kind . "." . $name, $block));
        }
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["current"] = $this->current;
        $out["steps"] = count($this->steps);
        return $out;
    }
}
