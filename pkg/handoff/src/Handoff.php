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

    /** 源 → 目标：撤销这次交接（源侧反悔了，比如租约丢了） */
    public const MSG_ABORT = 64104;

    /** 目标 → 源：撤销完成（装过的已卸掉，或者根本没装） */
    public const MSG_ABORTED = 64105;

    /** 字段分隔符。状态本身是最后一段，可以包含任意字节 */
    private const SEP = "\x1f";

    /** 载荷格式标签（v2 = 带状态版本段）。见 `begin()` 的注释 */
    private const TAG_V2 = "M2";

    private int $nodeId;

    private int $entryId;

    private Transport $net;

    private int $timeoutMs;

    /**
     * 本节点认识的状态版本（和 `Snapshot\Schema` 的 current 对齐）。
     *
     * 交接前双方要核对这个数：**目标比源旧就拒绝**。
     * 否则会出现"交接成功、对方读不懂"——状态搬过去了，重建时字段缺失，
     * 对新宿主来说是数据分家，对源节点来说是"我已交出去了"，两边都没法兜。
     */
    private int $stateVersion;

    /**
     * 在飞的迁移：key => [目标节点, 目标入口, 版本, 截止时刻]
     *
     * @var array<string, array<int, int>>
     */
    private array $pending = [];

    /** @var array<string, int> key => 已安装的版本 */
    private array $installed = [];

    /**
     * 每个 key 最近一次交接的结局："committed" / "rolledback"。
     *
     * 为什么要它：自动重平衡（`Rebalancer`）需要"事后"知道结果，而回调槽位只有一个——
     * 业务已经占了 `onCommit/onRollback`，调度器再抢就冲突了。留一份可查询的结局，
     * 让调度器用轮询而不是回调，两者就不打架。
     *
     * @var array<string, string>
     */
    private array $result = [];

    /** @var array<string, string> key => 回滚原因 */
    private array $resultWhy = [];

    /** @var array<string, int> 正在撤销的 key => 版本（等 ABORTED） */
    private array $aborting = [];

    /** @var array<string, int> 撤销等待的截止时刻 */
    private array $abortDeadline = [];

    /**
     * 目标侧墓碑：key => 被撤销过的最大版本。
     *
     * 必须有它。撤销消息和 MIGRATE 是两条独立链路，可能乱序：源侧发了 ABORT，
     * 但先发的 MIGRATE 还在路上。没有墓碑的话，那条迟到的 MIGRATE 会把状态又装回去，
     * 而源侧已经回滚成"我持有"——两份状态同时存在。
     *
     * @var array<string, int>
     */
    private array $aborted = [];

    /** @var \Closure|null function (string $key, int $version): void */
    private ?\Closure $onCommit = null;

    /** @var \Closure|null function (string $key, int $version, string $why): void */
    private ?\Closure $onRollback = null;

    /** @var \Closure|null function (string $key, string $state, int $version): bool */
    private ?\Closure $onInstall = null;

    /** @var \Closure|null function (string $key, int $version): void 撤销时要卸掉已装的状态 */
    private ?\Closure $onUninstall = null;

    /** @var array<string, int> */
    private array $stat = [
        "sent" => 0,
        "accepted" => 0,
        "rejected" => 0,
        "timeout" => 0,
        "installed" => 0,
        "dup" => 0,
        "ignored" => 0,
        "uninstalled" => 0,
        "abort_sent" => 0,
        "aborted" => 0,
        "abort_timeout" => 0,
        "version_denied" => 0,
    ];

    /**
     * @param int       $nodeId    本节点号（用于防线：不接受发往自己的迁移）
     * @param int       $entryId   本节点上承载迁移消息的本地服务 id
     * @param Transport $net       跨节点通道（用窄接口而不是直接依赖 Cluster，方便替换与测试）
     * @param int       $timeoutMs 等 ACK 的时限
     */
    public function __construct(
        int $nodeId,
        int $entryId,
        Transport $net,
        int $timeoutMs = 3000,
        int $stateVersion = 1
    ) {
        $this->nodeId = $nodeId;
        $this->entryId = $entryId;
        $this->net = $net;
        $this->timeoutMs = $timeoutMs > 0 ? $timeoutMs : 3000;
        $this->stateVersion = $stateVersion > 0 ? $stateVersion : 1;
    }

    /** 本节点认识的状态版本 */
    public function stateVersion(): int
    {
        return $this->stateVersion;
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
     *
     * ## 装载 ≠ 可以对外服务（重要）
     *
     * 这里被调用只说明"状态字节到了、校验过了"，**不说明这次迁移会成功**：
     *
     *   1. 目标回 ACCEPT，但 ACK 可能在网络上丢了
     *   2. 源侧 `expire()` 超时 → 回滚 → **源继续托管这个 key**
     *   3. 此时目标手里也有一份（已 install）
     *
     * 协议里**没有"最终确认"消息**（只有 MIGRATE/ACCEPT/REJECT/ABORT/ABORTED），
     * 所以目标侧收不到"源已经 commit 了"的通知。源的超时回滚也不会主动发 ABORT
     * （见 `expire()`：只 fire `onRollback`，靠 Rebalancer 重试收敛）。
     *
     * 结论：**`onInstall` 里只做"暂存"**——存进内存/文件，但不要注册路由、
     * 不要让消息路由到这个 key、不要宣称归属。对外的时机由 OwnerShip 决定
     * （环指向本节点 + 状态已装入）。在 `onInstall` 里抢归属，
     * 一旦源侧回滚，就是两个节点同时服务同一个 key——金币复制这类事故的源头。
     */
    public function onInstall(\Closure $cb): void
    {
        $this->onInstall = $cb;
    }

    /**
     * 目标侧卸掉已安装的状态。**只有撤销会走到这里。**
     *
     * 必须实现，否则撤销只是"源侧不认了"，目标侧那份还在——数据分家比不撤销更糟。
     */
    public function onUninstall(\Closure $cb): void
    {
        $this->onUninstall = $cb;
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
        // 载荷带显式标签 `M2`：状态是二进制，可能包含分隔符本身，
        // 靠"数段数"区分新旧格式会误判（老格式的 state 里有一个 \x1f 就会被当成版本段）。
        // 3 个字节换一个不会猜错的分支，值得。
        $payload = self::TAG_V2 . self::SEP . $key . self::SEP . $version
            . self::SEP . $this->stateVersion . self::SEP . $state;
        if (!$this->net->sendRaw($dst, $this->entryId, self::MSG_MIGRATE, $payload)) {
            return false;
        }
        $this->pending[$key] = [$targetNode, $targetEntry, $version, $nowMs + $this->timeoutMs];
        // 新一次交接开始，上一次的结局就作废了，避免调度器把旧结果当成新的
        unset($this->result[$key]);
        unset($this->resultWhy[$key]);
        $this->stat["sent"]++;
        return true;
    }


    /**
     * 撤销一次在飞的交接。返回 false 表示发不出去（没有在飞 / 链路断了）。
     *
     * 什么时候用：源侧反悔了。典型场景是**租约中途丢失**——别的节点已经接手这个 key，
     * 你手上这次交接再继续走完，就等于两份状态同时存在。
     *
     * 撤销不是"本地不认"就完了：目标侧可能已经把状态装进去了，所以必须发 ABORT 让
     * 对方卸掉并回 ABORTED。只有收到 ABORTED（或超时）才真正回滚本地，
     * 这样"装过又卸"和"还没装"两种时序都能收敛。
     */
    public function abort(string $key, int $nowMs): bool
    {
        if (!isset($this->pending[$key])) {
            return false;
        }
        $ver = $this->pending[$key][2];
        $node = $this->pending[$key][0];
        $entry = $this->pending[$key][1];
        $dst = Addr::pack($node, $entry);
        if (!$this->net->sendRaw($dst, $this->entryId, self::MSG_ABORT, $key . self::SEP . $ver)) {
            return false;
        }
        unset($this->pending[$key]);
        $this->aborting[$key] = $ver;
        $this->abortDeadline[$key] = $nowMs + $this->timeoutMs;
        $this->stat["abort_sent"]++;
        return true;
    }

    public function isAborting(string $key): bool
    {
        return isset($this->aborting[$key]);
    }

    /** 处理一条入站迁移消息（所有协议号都在这里收口） */
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
        if ($msgId === self::MSG_ABORT) {
            $this->acceptAbort($m, $raw);
            return;
        }
        if ($msgId === self::MSG_ABORTED) {
            $this->settleAbort($raw);
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
        // 撤销也可能等不到 ABORTED（对端崩了）。超时后照样回滚本地：
        // 此时"目标侧可能还留着那份状态"是已知代价，比永远卡在 aborting 好
        $stale = [];
        foreach ($this->abortDeadline as $key => $dl) {
            if ($nowMs >= $dl) {
                $stale[] = (string) $key;
            }
        }
        foreach ($stale as $key) {
            $ver = $this->aborting[$key] ?? 0;
            unset($this->aborting[$key]);
            unset($this->abortDeadline[$key]);
            $this->stat["abort_timeout"]++;
            $this->result[$key] = "rolledback";
            $this->resultWhy[$key] = "abort-timeout";
            $this->fire($this->onRollback, $key, $ver, "abort-timeout");
        }

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
            $this->result[$key] = "rolledback";
            $this->resultWhy[$key] = "timeout";
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

    /** 目标侧：收到撤销 */
    private function acceptAbort(Message $m, string $raw): void
    {
        $parts = explode(self::SEP, $raw, 2);
        if (count($parts) !== 2) {
            $this->stat["ignored"]++;
            return;
        }
        $key = $parts[0];
        $ver = (int) $parts[1];

        // 装过就卸掉。没装过也要记墓碑：MIGRATE 可能还在路上
        if (($this->installed[$key] ?? 0) === $ver) {
            $cb = $this->onUninstall;
            if ($cb instanceof \Closure) {
                $cb($key, $ver);
            }
            unset($this->installed[$key]);
            $this->stat["uninstalled"]++;
        }
        if (($this->aborted[$key] ?? 0) < $ver) {
            $this->aborted[$key] = $ver;
        }
        $this->reply($m->from, self::MSG_ABORTED, $key, $ver);
    }

    /** 源侧：收到 ABORTED，现在才能安全地回滚 */
    private function settleAbort(string $raw): void
    {
        $parts = explode(self::SEP, $raw, 2);
        if (count($parts) !== 2) {
            $this->stat["ignored"]++;
            return;
        }
        $key = $parts[0];
        $ver = (int) $parts[1];
        if (($this->aborting[$key] ?? 0) !== $ver) {
            $this->stat["ignored"]++;
            return;
        }
        unset($this->aborting[$key]);
        unset($this->abortDeadline[$key]);
        $this->result[$key] = "rolledback";
        $this->resultWhy[$key] = "aborted";
        $this->stat["aborted"]++;
        $this->fire($this->onRollback, $key, $ver, "aborted");
    }

    /** 目标侧：安装 */
    private function acceptMigrate(Message $m, string $raw): void
    {
        // 新格式带 `M2` 标签；没有标签的是滚动升级窗口里旧二进制发的老报文。
        // 老报文按"状态版本未知"（0）放行——不能因为对方没报版本就拒绝，
        // 那会让滚动窗口卡死在第一阶段
        $body = $raw;
        $peerState = 0;
        if (strpos($raw, self::TAG_V2 . self::SEP) === 0) {
            $body = substr($raw, strlen(self::TAG_V2) + 1);
            $parts = explode(self::SEP, $body, 4);
            if (count($parts) !== 4) {
                $this->stat["ignored"]++;
                return;
            }
            $key = $parts[0];
            $ver = (int) $parts[1];
            $peerState = (int) $parts[2];
            $state = $parts[3];
        } else {
            $parts = explode(self::SEP, $body, 3);
            if (count($parts) !== 3) {
                $this->stat["ignored"]++;
                return;
            }
            $key = $parts[0];
            $ver = (int) $parts[1];
            $state = $parts[2];
        }

        // 版本闸门：对方的状态比本节点新 → 装不了，回 REJECT 让源侧留着。
        // 这是唯一能在"搬之前"拦住数据分家的位置
        if ($peerState > 0 && $peerState > $this->stateVersion) {
            $this->stat["version_denied"]++;
            $this->reply($m->from, self::MSG_REJECT, $key, $ver, "version");
            return;
        }

        if (($this->aborted[$key] ?? 0) >= $ver) {
            // 这次交接已经被撤销过了，迟到的 MIGRATE 绝不能装
            $this->stat["ignored"]++;
            $this->reply($m->from, self::MSG_REJECT, $key, $ver);
            return;
        }

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
        // 第三段是可选的拒绝原因。带原因是为了让源侧区分"对方忙/满了"和
        // "对方版本太旧"——后者重试多少次都没用，得换目标或升级对方
        $parts = explode(self::SEP, $raw, 3);
        if (count($parts) < 2) {
            $this->stat["ignored"]++;
            return;
        }
        $key = $parts[0];
        $ver = (int) $parts[1];
        $reason = count($parts) === 3 && $parts[2] !== "" ? $parts[2] : "rejected";
        if (isset($this->aborting[$key])) {
            // 已经在撤销了：这个 ACK 是撤销前发出的，认了它就会留下两份状态
            $this->stat["ignored"]++;
            return;
        }
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
            $this->result[$key] = "committed";
            $this->resultWhy[$key] = "";
            $this->fire($this->onCommit, $key, $ver, "");
            return;
        }
        $this->stat["rejected"]++;
        $this->result[$key] = "rolledback";
        $this->resultWhy[$key] = $reason;
        $this->fire($this->onRollback, $key, $ver, $reason);
    }

    /**
     * 最近一次交接的结局：`committed` / `rolledback` / 空串（没交接过）。
     *
     * 给自动重平衡用：它没法挤进 `onCommit` 回调槽位（业务已经占了），所以用轮询。
     */
    public function lastResult(string $key): string
    {
        return $this->result[$key] ?? "";
    }

    /** 回滚原因（`rejected` / `timeout`），成功或未交接时为空串 */
    public function lastWhy(string $key): string
    {
        return $this->resultWhy[$key] ?? "";
    }

    /**
     * @param string $reason 拒绝原因。带原因是为了让源侧能区分"对方忙/满了"
     *                       和"对方版本太旧"——后者重试多少次都没用
     */
    private function reply(int $to, int $msgId, string $key, int $ver, string $reason = ""): void
    {
        $payload = $key . self::SEP . $ver;
        if ($reason !== "") {
            $payload .= self::SEP . $reason;
        }
        $this->net->sendRaw($to, $this->entryId, $msgId, $payload);
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
