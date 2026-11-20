<?php

namespace TSF\Handoff;

use TSF\Cluster\Cluster;

/**
 * `Transport` 的生产实现：把发送委托给跨节点通道。
 *
 * 这里不做重试。重试的正确语义取决于"这条消息是否幂等"——迁移的 MIGRATE
 * 幂等（目标侧按版本去重），但 ACCEPT/REJECT 重发只会制造迟到的确认，
 * 而迟到确认比丢包更难处理（见 `Handoff::settle`）。所以交给 `begin()`
 * 的调用方决定要不要重来一次。
 */
final class ClusterTransport implements Transport
{
    private Cluster $cluster;

    public function __construct(Cluster $cluster)
    {
        $this->cluster = $cluster;
    }

    public function sendRaw(int $dstAddr, int $srcLocalId, int $msgId, string $payload): bool
    {
        return $this->cluster->send($dstAddr, $srcLocalId, $msgId, $payload);
    }
}
