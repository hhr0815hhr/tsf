<?php

namespace TSF\Handoff;

/**
 * 迁移协议需要的**唯一**外部能力：把一段字节投给某个地址。
 *
 * 刻意不直接依赖 `TSF\Cluster\Cluster`：迁移的可测性全押在这一层。
 * 测试里可以塞一个"必定失败"或"记录投递"的假实现，不必真的起两个进程；
 * 生产里用 `ClusterTransport` 包一下就行。
 *
 * 接口窄还有一个好处：以后换传输（比如走 HTTP 中转）只需要再实现一个类，
 * 迁移协议本身一行不用改。
 */
interface Transport
{
    /**
     * @param int    $dstAddr     目标完整地址（`Addr::pack(node, entry)`）
     * @param int    $srcLocalId  发送方在本节点的服务 id（本方法负责补节点号）
     * @param int    $msgId       协议号
     * @param string $payload     原始字节
     *
     * @return bool false 表示对端不可达（链路未建/未握手）
     */
    public function sendRaw(int $dstAddr, int $srcLocalId, int $msgId, string $payload): bool;
}
