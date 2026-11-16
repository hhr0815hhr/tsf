<?php

namespace TSF\Service;

/**
 * 消息信封。
 *
 * 字段少而具体：4 个 int/union 就够表达"谁发给谁、要做什么、带什么、回哪去"。
 * 不加时间戳——实测 `hrtime()` 要 236 ns，占单条消息预算的 12%。
 * 延迟统计由压测端自己记录（发送时存一份发送时刻表，接收时对照），
 * 框架热路径不为"可能没人看的指标"付费。
 *
 * 载荷用 `string|array|object` 而不是 `mixed`（全框架标准，见 README §5.8）。
 */
final class Message
{
    /** 发送方服务地址；0 表示系统（网络层/定时器）发起 */
    public int $from;

    /** 请求 id；0 表示单向消息。回应时原样带回 */
    public int $session;

    /** 应用层协议号 */
    public int $msgId;

    public string|array|object $data;

    public function __construct(int $from, int $msgId, string|array|object $data, int $session = 0)
    {
        $this->from = $from;
        $this->msgId = $msgId;
        $this->data = $data;
        $this->session = $session;
    }
}
