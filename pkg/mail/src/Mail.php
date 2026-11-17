<?php

namespace TSF\Mail;

use TSF\Db\Col;
use TSF\Db\Id;
use TSF\Db\Table;

/**
 * 一封站内信。
 *
 * 附件存在 `items` 字段里（JSON 字符串），不另建表 —— 邮件附件通常是
 * `[{"id":1001,"n":2},{"id":2003,"n":1}]` 这种小数组，一张附表反而多一次 join。
 *
 * 时间字段用 BIGINT 存 unix 时间戳（不是 DATETIME）：跨库行为一致，
 * 而且 `0` 天然表示"未读/未领"，不需要额外的 null 语义。
 */
#[Table("mail")]
final class Mail
{
    #[Id(true)]
    public int $id = 0;

    /** 收件人 uid（分片键） */
    #[Col("uid")]
    public int $uid = 0;

    #[Col("title")]
    public string $title = "";

    #[Col("body")]
    public string $body = "";

    /** JSON: [{"id":1001,"n":2}]，空字符串表示没有附件 */
    #[Col("items")]
    public string $items = "";

    #[Col("created_at")]
    public int $createdAt = 0;

    /** 0 = 永不过期 */
    #[Col("expire_at")]
    public int $expireAt = 0;

    /** 0 = 未读 */
    #[Col("read_at")]
    public int $readAt = 0;

    /** 0 = 未领取附件 */
    #[Col("claimed_at")]
    public int $claimedAt = 0;

    public function hasItems(): bool
    {
        return $this->items !== "" && $this->items !== "[]";
    }

    public function isRead(): bool
    {
        return $this->readAt > 0;
    }

    public function isClaimed(): bool
    {
        return $this->claimedAt > 0;
    }

    public function isExpired(int $now): bool
    {
        return $this->expireAt > 0 && $this->expireAt <= $now;
    }
}
