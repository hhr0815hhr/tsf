<?php

namespace TSF\Discovery;

/**
 * 一个节点的注册信息。**纯数据，可序列化**。
 *
 * `codec` 字段是这次特意加的：两端 RPC 协议不一致时，报错是
 * `bad-response`（"我解不出来"），而不是"你我协议不同"——因为解不出来的那一端
 * 没法知道对方用的是什么。把协议名写进注册信息，就能在**建连之前**发现不匹配，
 * 报出来的错误才是有用的。
 *
 * 但要注意它是"声明"不是"保证"：注册表里写 `json`，实际跑的是 `raw`，
 * 注册表管不了。想强保证得在握手时做真正的能力协商（T5-04 跨服路由时再说）。
 */
final class NodeInfo
{
    public int $nodeId;

    /** 接入地址（UDS 路径 / 将来的 host:port） */
    public string $endpoint;

    /** RPC 协议名（`json` / `raw` / 自定义） */
    public string $codec;

    /** 上线时刻（墙钟毫秒） */
    public int $joinedAt;

    /** 最后一次心跳（墙钟毫秒）。判定存活就看它 */
    public int $lastSeen;

    /** @var array<string, string> 自定义标签：region / role / 机房 等 */
    public array $tags;

    /**
     * 跨服入口服务的**本地 id**（不是完整 addr）。
     *
     * 跨服调用需要知道"投给目标节点上的哪个服务"。让每个节点声明一个入口，
     * 比"约定 id=1"可靠得多——后者依赖 spawn 顺序，加一个服务就崩。
     *
     * 完整地址 = `Addr::pack($nodeId, $entry)`。
     */
    public int $entry;

    public function __construct(
        int $nodeId,
        string $endpoint,
        string $codec = "json",
        array $tags = [],
        int $entry = 1
    ) {
        $this->nodeId = $nodeId;
        $this->endpoint = $endpoint;
        $this->codec = $codec;
        $this->tags = $tags;
        $this->entry = $entry > 0 ? $entry : 1;
        $now = (int) (microtime(true) * 1000);
        $this->joinedAt = $now;
        $this->lastSeen = $now;
    }

    /** 多久没心跳了（毫秒） */
    public function ageMs(int $nowMs): int
    {
        return $nowMs - $this->lastSeen;
    }

    public function isAlive(int $nowMs, int $ttlMs): bool
    {
        return $this->ageMs($nowMs) < $ttlMs;
    }

    public function tag(string $key, string $default = ""): string
    {
        return $this->tags[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            "id" => $this->nodeId,
            "ep" => $this->endpoint,
            "codec" => $this->codec,
            "entry" => $this->entry,
            "joined" => $this->joinedAt,
            "seen" => $this->lastSeen,
            "tags" => $this->tags,
        ];
    }

    /** 解析失败返回 null（坏文件不该让整张注册表读不出来） */
    public static function fromArray(array $d): ?NodeInfo
    {
        $id = isset($d["id"]) ? (int) $d["id"] : 0;
        if ($id <= 0) {
            return null;
        }
        $ep = isset($d["ep"]) ? (string) $d["ep"] : "";
        if ($ep === "") {
            return null;
        }
        $n = new NodeInfo(
            $id,
            $ep,
            isset($d["codec"]) ? (string) $d["codec"] : "json",
            [],
            isset($d["entry"]) ? (int) $d["entry"] : 1
        );
        if (isset($d["joined"]) && is_int($d["joined"])) {
            $n->joinedAt = $d["joined"];
        }
        if (isset($d["seen"]) && is_int($d["seen"])) {
            $n->lastSeen = $d["seen"];
        }
        if (isset($d["tags"]) && is_array($d["tags"])) {
            $tags = [];
            foreach ($d["tags"] as $k => $v) {
                $tags[(string) $k] = (string) $v;
            }
            $n->tags = $tags;
        }
        return $n;
    }
}
