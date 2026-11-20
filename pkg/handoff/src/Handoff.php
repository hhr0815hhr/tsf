<?php

namespace TSF\Handoff;

use TSF\Cluster\Addr;
use TSF\Service\Message;

/**
 * 单键状态交接（handoff）：把一份状态从本节点搬到另一个节点。
 *
 * 名字刻意不叫 `Migrator`：`pkg/db` 已经有一个做**数据库结构迁移**的 Migrator，
 * 两个同名类在 grep 里会互相污染，而它们解决的是完全不同的问题
 * （一个是"表结构版本"，一个是"运行中的状态换宿主"）。
 *
 * ## 它解决的那一步
 *
 * `Shard\Ring` 能算出"这个 key 现在该归谁"，但算出之后**没有下文**：
 * 归属变了，数据还在老节点上。扩缩容、故障摘除、负载再平衡都会撞到这一点。
 * 所以缺的不是"算归属"，是"把状态交出去且不丢"。
 *
 * ## 协议（三消息 + 超时）
 *
 * ```
 * 源                                   目标
 *  |  begin(key, ver, state)             |
 *  |---- MIGRATE(key, ver, state) ------>|
 *  |                                    |  install(key, state, ver)
 *  |<--- ACCEPT(key, ver) --------------|  成功
 *  |<--- REJECT(key, ver) --------------|  拒绝（校验不过/已满/版本冲突）
 *  |                                     |
 *  |  超时未收到任何回复 → 本地回滚        |
 * ```
 *
 * ## 三条不变量（测试就是照这三条写的）
 *
 * 1. **失败必须能回滚**。`begin()` 之后源节点只是"暂停拥用"这个 key，
 *    状态没删；收到 REJECT 或超时就把拥有权收回。**迁移失败不能等于数据丢失。**
 * 2. **重复投递不能重复安装**。网络会重发、手会抖；目标侧记 `installed[key] = version`，
 *    同版本再来一次直接回 ACCEPT，不再调 `onInstall`。
 * 3. **同 key 同时只能有一次迁移在飞**。第二次 `begin()` 直接返回 false，
 *    而不是排队——排队会让"谁先谁后"依赖调度顺序，排障时看不出来。
 *
 * ## 为什么状态用闭包进出，而不是继承
 *
 * 状态是什么（房间快照？玩家存档？）只有业务知道。让它实现一个 `MigrationPayload`
 * 接口等于强迫所有业务对象改继承链；用闭包只需要在装配处写两个函数。
 * 这与 `pkg/script`、`pkg/snapshot` 的取舍一致。
 *
 * ## AOT 注意
 *
 * 回调全部显式 `\Closure`，不传引用参数（AOT 下引用形参不写回，见 README 附录 5）。
 */
final class Handoff
{
    /**
     * 协议号。**必须 ≤ 65535**：跨节点包的 msgId 字段只有 2 字节（Packet 头 18 字节里
     * 末尾两个），超过就会被静默截断——表现是"消息发出去了，对方说没收到"，
     * 而且两端日志都干净。这条踩过一次：原来写 69001，落线上变成 3465。
     */
    public const MSG_MIGRATE = 64101;

    /** 目标 → 源：装好了 */
    public const MSG_ACCEPT = 64102;

    /** 目标 → 源：拒绝了（源侧要回滚） */
    public const MSG_REJECT = 64103;

    /** 字段分隔符。状态本身是最后一段，可以包含任意字节 */
    private const SEP = "\x1f";

    private int $nodeId;

    private int $entryId;

    private Transport $net;

    private int $timeoutMs;

    /**
     * 在飞的迁移：key => [目标节点, 目标入口, 版本, 截止时刻]
     *
     * @var array<string, array<int, int>>
     */
    private array $pending = [];

    /** @var array<string, int> key => 已安装的版本 */
    private array $installed = [];

    /** @var \Closure|null function (string $key, int $version): void */
    private ?\Closure $onCommit = null;

    /** @var \Closure|null function (string $key, int $version, string $why): void */
    private ?\Closure $onRollback = null;

    /** @var \Closure|null function (string $key, string $state, int $version): bool */
    private ?\Closure $onInstall = null;

    /** @var array<string, int> */
    private array $stat = [
        "sent" => 0,
        "accepted" => 0,
        "rejected" => 0,
        "timeout" => 0,
        "installed" => 0,
        "dup" => 0,
        "ignored" => 0,
    ];

    /**
     * @param int       $nodeId    本节点号（用于防线：不接受发往自己的迁移）
     * @param int       $entryId   本节点上承载迁移消息的本地服务 id
     * @param Transport $net       跨节点通道（用窄接口而不是直接依赖 Cluster，方便替换与测试）
     * @param int       $timeoutMs 等 ACK 的时限
     */
    public function __construct(int $nodeId, int $entryId, Transport $net, int $timeoutMs = 3000)
    {
        $this->nodeId = $nodeId;
        $this->entryId = $entryId;
        $this->net = $net;
        $this->timeoutMs = $timeoutMs > 0 ? $timeoutMs : 3000;
    }

    /** 迁移成功、源侧可以删本地状态了 */
    public function onCommit(\Closure $cb): void
    {
        $this->onCommit = $cb;
    }

    /** 迁移失败（拒绝/超时），源侧必须恢复本地服务 */
    public function onRollback(\Closure $cb): void
    {
        $this->onRollback = $cb;
    }

    /**
     * 目标侧安装。返回 false 表示拒绝（校验不过、容量满、版本回退……）。
     *
     * 用返回值而不是异常：拒绝是**正常路径**（对方版本更新、我这正忙），
     * 用异常会让正常的拒绝走进错误日志，把真正的故障淹掉。
     */
    public function onInstall(\Closure $cb): void
    {
        $this->onInstall = $cb;
    }

    /**
     * 发起迁移。返回 false 表示发不出去（链路未握手 / 同 key 已有迁移在飞）。
     *
     * 注意：返回 true 只代表"已发出"，不代表成功——成功看 `onCommit`。
     * 这两个状态分开是刻意的：调用方在 begin 之后就该冻结这个 key 的入口。
     */
    public function begin(int $targetNode, int $targetEntry, string $key, int $version, string $state, int $nowMs): bool
    {
        if ($key === "" || isset($this->pending[$key])) {
            return false;
        }
        if ($targetNode === $this->nodeId) {
            // 搬到自己是无意义的，而且会让 pending 永远等不到 ACCEPT
            return false;
        }
        $dst = Addr::pack($targetNode, $targetEntry);
        $payload = $key . self::SEP . $version . self::SEP . $state;
        if (!$this->net->sendRaw($dst, $this->entryId, self::MSG_MIGRATE, $payload)) {
            return false;
        }
        $this->pending[$key] = [$targetNode, $targetEntry, $version, $nowMs + $this->timeoutMs];
        $this->stat["sent"]++;
        return true;
    }

    /** 处理一条入站迁移消息（三个 msgId 都在这里收口） */
    public function handle(Message $m): void
    {
        $msgId = $m->msgId;
        $raw = is_string($m->data) ? $m->data : "";

        if ($msgId === self::MSG_MIGRATE) {
            $this->acceptMigrate($m, $raw);
            return;
        }
        if ($msgId === self::MSG_ACCEPT) {
            $this->settle($raw, true);
            return;
        }
        if ($msgId === self::MSG_REJECT) {
            $this->settle($raw, false);
            return;
        }
        $this->stat["ignored"]++;
    }

    /**
     * 超时扫描。**必须由调用方驱动**（和 FrameLoop 一样），不内置定时器：
     * 扫描频率取决于业务对"迁移卡住多久算失败"的容忍度，框架不该替它决定。
     */
    public function expire(int $nowMs): void
    {
        if ($this->pending === []) {
            return;
        }
        $dead = [];
        foreach ($this->pending as $key => $p) {
            if ($nowMs >= $p[3]) {
                $dead[] = (string) $key;
            }
        }
        foreach ($dead as $key) {
            $ver = $this->pending[$key][2];
            unset($this->pending[$key]);
            $this->stat["timeout"]++;
            $this->fire($this->onRollback, $key, $ver, "timeout");
        }
    }

    public function isPending(string $key): bool
    {
        return isset($this->pending[$key]);
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function installedVersion(string $key): int
    {
        return $this->installed[$key] ?? 0;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $out = $this->stat;
        $out["pending"] = count($this->pending);
        return $out;
    }

    // ---------------------------------------------------------------- 内部

    /** 目标侧：安装 */
    private function acceptMigrate(Message $m, string $raw): void
    {
        $parts = explode(self::SEP, $raw, 3);
        if (count($parts) !== 3) {
            $this->stat["ignored"]++;
            return;
        }
        $key = $parts[0];
        $ver = (int) $parts[1];
        $state = $parts[2];

        $have = $this->installed[$key] ?? 0;
        if ($have >= $ver) {
            // 重复投递：已经装过这个版本了。回 ACK 让源侧推进，但**不再安装**——
            // 否则每重发一次就覆盖一次，正在跑的对局会被回滚到旧状态
            $this->stat["dup"]++;
            $this->reply($m->from, self::MSG_ACCEPT, $key, $ver);
            return;
        }

        $ok = false;
        $cb = $this->onInstall;
        if ($cb instanceof \Closure) {
            $ok = (bool) $cb($key, $state, $ver);
        }
        if (!$ok) {
            $this->stat["rejected"]++;
            $this->reply($m->from, self::MSG_REJECT, $key, $ver);
            return;
        }
        $this->installed[$key] = $ver;
        $this->stat["installed"]++;
        $this->reply($m->from, self::MSG_ACCEPT, $key, $ver);
    }

    /** 源侧：收到 ACK/REJECT */
    private function settle(string $raw, bool $ok): void
    {
        $parts = explode(self::SEP, $raw, 2);
        if (count($parts) !== 2) {
            $this->stat["ignored"]++;
            return;
        }
        $key = $parts[0];
        $ver = (int) $parts[1];
        if (!isset($this->pending[$key])) {
            // 迟到的 ACK（已经超时回滚过了）。忽略是唯一正确的处理：
            // 这时候业务已经在本地继续跑，再"提交"会把状态凭空删掉
            $this->stat["ignored"]++;
            return;
        }
        if ($this->pending[$key][2] !== $ver) {
            $this->stat["ignored"]++;
            return;
        }
        unset($this->pending[$key]);
        if ($ok) {
            $this->stat["accepted"]++;
            $this->fire($this->onCommit, $key, $ver, "");
            return;
        }
        $this->stat["rejected"]++;
        $this->fire($this->onRollback, $key, $ver, "rejected");
    }

    private function reply(int $to, int $msgId, string $key, int $ver): void
    {
        $this->net->sendRaw($to, $this->entryId, $msgId, $key . self::SEP . $ver);
    }

    private function fire(?\Closure $cb, string $key, int $ver, string $why): void
    {
        if ($cb instanceof \Closure) {
            if ($why === "") {
                $cb($key, $ver);
            } else {
                $cb($key, $ver, $why);
            }
        }
    }
}
