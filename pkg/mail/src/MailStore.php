<?php

namespace TSF\Mail;

use TSF\Db\Db;
use TSF\Db\Source;

/**
 * 站内信读写。
 *
 * 用 `Source` 而不是 `Db`：单库和分库的调用代码完全一样（见 04 篇"分片"）。
 * 分片键是收件人 uid，所以一个玩家的邮件永远落在同一个库。
 *
 * ## 为什么不用 Repo
 *
 * `Repo` 按主键读写，而邮件的查询几乎都是"按 uid 找所有"，主键在这里只用得上
 * 领取/已读那两条 UPDATE。两套接口混用反而乱，所以这里直接写 SQL —— 语句少、
 * 每个都能一眼看懂。
 *
 * ## 领取的并发安全
 *
 * `claim()` 用一条带条件的 UPDATE 做原子判断：
 *
 *     UPDATE mail SET claimed_at = ? WHERE id = ? AND uid = ? AND claimed_at = 0
 *
 * 返回受影响行数，只有 1 才算领到。两条并发的领取请求里，数据库保证只有一条
 * 能改到行，另一条拿到 0 —— 不需要额外的锁，也不需要事务。
 *
 * 这个模式比"先查状态再更新"可靠得多：后者在查和更之间有机会被插进来。
 */
final class MailStore
{
    private Source $src;

    private string $table;

    public function __construct(Source $src, string $table = "mail")
    {
        $this->src = $src;
        $this->table = $table;
    }

    /**
     * 发一封邮件。
     *
     * @param array<int, array<string, int>> $items 附件，形如 [["id" => 1001, "n" => 2]]
     * @param int $expireDays 有效天数，0 表示永不过期
     * @return int 邮件 id，失败返回 0
     */
    public function send(int $uid, string $title, string $body = "", array $items = [], int $expireDays = 30): int
    {
        $now = time();
        $expire = $expireDays > 0 ? $now + $expireDays * 86400 : 0;
        $itemsJson = $items === [] ? "" : (string) json_encode($items);

        $db = $this->src->forKey($uid);
        return $db->insert($this->table, [
            "uid" => $uid,
            "title" => $title,
            "body" => $body,
            "items" => $itemsJson,
            "created_at" => $now,
            "expire_at" => $expire,
            "read_at" => 0,
            "claimed_at" => 0,
        ]);
    }

    /**
     * 列出某人的邮件（不含已过期）。
     *
     * @return array<int, Mail>
     */
    public function listOf(int $uid, int $limit = 50, bool $onlyUnread = false): array
    {
        $now = time();
        $db = $this->src->forKey($uid);

        $sql = "SELECT id, uid, title, body, items, created_at, expire_at, read_at, claimed_at"
            . " FROM " . $this->table
            . " WHERE uid = ? AND (expire_at = 0 OR expire_at > ?)";
        if ($onlyUnread) {
            $sql .= " AND read_at = 0";
        }
        $sql .= " ORDER BY id DESC LIMIT " . (int) $limit;

        $rows = $db->select($sql, [$uid, $now]);
        $out = [];
        foreach ($rows as $r) {
            $m = new Mail();
            $m->id = (int) ($r["id"] ?? 0);
            $m->uid = (int) ($r["uid"] ?? 0);
            $m->title = (string) ($r["title"] ?? "");
            $m->body = (string) ($r["body"] ?? "");
            $m->items = (string) ($r["items"] ?? "");
            $m->createdAt = (int) ($r["created_at"] ?? 0);
            $m->expireAt = (int) ($r["expire_at"] ?? 0);
            $m->readAt = (int) ($r["read_at"] ?? 0);
            $m->claimedAt = (int) ($r["claimed_at"] ?? 0);
            $out[] = $m;
        }
        return $out;
    }

    /** 标记已读。已经是已读时返回 false（调用方可忽略） */
    public function markRead(int $uid, int $mailId): bool
    {
        $db = $this->src->forKey($uid);
        $n = $db->exec(
            "UPDATE " . $this->table . " SET read_at = ? WHERE id = ? AND uid = ? AND read_at = 0",
            [time(), $mailId, $uid]
        );
        return $n > 0;
    }

    /**
     * 领取附件。**原子**：只有 claimed_at = 0 时才能领到。
     *
     * @return array<int, array<string, int>> 领到的附件；已领过 / 不存在 / 已过期返回空数组
     */
    public function claim(int $uid, int $mailId): array
    {
        $now = time();
        $db = $this->src->forKey($uid);

        // 一条 UPDATE 完成"判断 + 标记"，这是并发安全的全部
        $n = $db->exec(
            "UPDATE " . $this->table . " SET claimed_at = ?"
                . " WHERE id = ? AND uid = ? AND claimed_at = 0 AND (expire_at = 0 OR expire_at > ?)",
            [$now, $mailId, $uid, $now]
        );
        if ($n !== 1) {
            return [];
        }

        $row = $db->first(
            "SELECT items FROM " . $this->table . " WHERE id = ? AND uid = ?",
            [$mailId, $uid]
        );
        $raw = (string) ($row["items"] ?? "");
        if ($raw === "") {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $one) {
            if (is_array($one)) {
                $out[] = $one;
            }
        }
        return $out;
    }

    /** 删除一封（玩家手动删，或已领完清理） */
    public function del(int $uid, int $mailId): bool
    {
        $db = $this->src->forKey($uid);
        $n = $db->exec(
            "DELETE FROM " . $this->table . " WHERE id = ? AND uid = ?",
            [$mailId, $uid]
        );
        return $n > 0;
    }

    /** 删掉某人所有已过期的邮件，返回删除条数 */
    public function cleanExpired(int $uid): int
    {
        $now = time();
        $db = $this->src->forKey($uid);
        return $db->exec(
            "DELETE FROM " . $this->table . " WHERE uid = ? AND expire_at > 0 AND expire_at <= ?",
            [$uid, $now]
        );
    }

    /** 未读数（列表页的红点） */
    public function unreadCount(int $uid): int
    {
        $now = time();
        $db = $this->src->forKey($uid);
        $v = $db->value(
            "SELECT COUNT(*) FROM " . $this->table
                . " WHERE uid = ? AND read_at = 0 AND (expire_at = 0 OR expire_at > ?)",
            [$uid, $now],
            0
        );
        return (int) $v;
    }
}
