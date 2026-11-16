<?php

namespace TSF\Snapshot;

/**
 * 一份快照：**就是一个带元信息的 JSON 体，不理解里面装的是什么**。
 *
 * ```php
 * $snap = new Snapshot("room", 1001);
 * $snap->put("room", $room->toArray());
 * $snap->put("ents", $ents->toArray());
 * $json = Codec::encode($snap);
 * ```
 *
 * ## 为什么它不认识 Room
 *
 * 房间要快照，匹配池的排队状态、玩家存档、交易单也需要。如果这个包
 * `use TSF\Room\Room`，那它就变成"房间专用"，别的地方要么复制一份、
 * 要么硬塞进房间的形状。
 *
 * 所以边界是：**谁的数据谁负责序列化**（`Room::toArray()` 在 room 包里），
 * 这个包只提供"一个 key + 一份数据 + 一个版本号"的容器和它的编解码。
 *
 * ## body 是两级结构而不是一层
 *
 * `body` 的每个值都是一块独立的 `array`，而不是把所有字段平铺进来：
 *
 * ```php
 * $snap->put("room", ["state" => 2, "frame" => 88]);
 * $snap->put("game", ["hp" => [1001 => 40]]);   // 玩法自己的
 * ```
 *
 * 平铺的话，框架字段和玩法字段共用一层命名空间，早晚撞名；而且恢复时
 * 分不清"这块该谁读"。分块之后，`$snap->get("game")` 一看就知道是玩法数据。
 */
final class Snapshot
{
    /** 快照格式版本。结构变了就 +1，旧快照会被拒绝而不是被误读 */
    public int $version;

    /** 种类：room / match / order … 决定 body 里有哪些块 */
    public string $kind;

    /** 业务键（房间号、票号、订单号） */
    public int $key;

    /** 采集时间（**墙钟毫秒**）。用 hrtime 的话跨进程/重启就没法比了 */
    public int $atMs;

    /** @var array<string, array> 块名 => 数据块 */
    public array $body = [];

    public function __construct(string $kind, int $key, int $version = Codec::VERSION)
    {
        $this->kind = $kind;
        $this->key = $key;
        $this->version = $version;
        $this->atMs = (int) (microtime(true) * 1000);
    }

    /** 放一块数据。同名覆盖——定期整存时可以放心地反复 put */
    public function put(string $name, array $data): void
    {
        $this->body[$name] = $data;
    }

    /**
     * 取一块数据。没有就返回空数组。
     *
     * 不返回 null：调用方几乎总是要遍历/取字段，空数组能省掉一层判断，
     * 而"块不存在"和"块是空"在恢复语义上没有区别。
     */
    public function get(string $name): array
    {
        $v = $this->body[$name] ?? null;
        if (is_array($v)) {
            return $v;
        }
        return [];
    }

    public function has(string $name): bool
    {
        return isset($this->body[$name]);
    }

    /** 所有块名（排障/校验用） */
    public function blocks(): array
    {
        $out = [];
        foreach ($this->body as $name => $v) {
            $out[] = (string) $name;
        }
        return $out;
    }

    /** 采集距今多久（毫秒）。恢复前判断快照新不新鲜 */
    public function ageMs(): int
    {
        $now = (int) (microtime(true) * 1000);
        return $now - $this->atMs;
    }
}
