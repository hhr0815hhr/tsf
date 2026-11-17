<?php

namespace TSF\Kernel;

/**
 * 启动配置的收口点。
 *
 * 为什么要这个类：模板生成的 main() 里原本散着十几次 `Config::getXxx()`，
 * 读的人得从头到尾扫一遍才知道"这个服务到底依赖哪些配置"。而且每一项都是
 * 字符串键 + 默认值，写错键名不会报错（拿到默认值，静默跑起来）。
 *
 * 收进一个类之后：
 *   - 所有配置项在一处可见、有类型、有默认值
 *   - 拼错了属性名编译期就报（比拼错字符串键强）
 *   - 想给测试注入一套配置，直接 new 一个改字段，不用去动 ini
 */
final class AppConfig
{
    public string $host = "0.0.0.0";

    public int $port = 9001;

    public string $transport = "tcp";

    public int $workerNum = 1;

    public int $maxConn = 10000;

    public bool $nodelay = true;

    /** false 只用于压测对照（跳过会话/限流/心跳），生产必须是 true */
    public bool $gateway = true;

    public int $heartbeatSec = 30;

    public int $ratePerSec = 50;

    public int $rateBurst = 100;

    public bool $requireAuth = true;

    /** 连续多少次发送失败就断开（慢客户端不拖累 worker） */
    public int $sendFailLimit = 50;

    /** native（C++，推荐）| header（纯 PHP，排障用） */
    public string $frameName = "native";

    /** raw（透传）| json | msgpack | protobuf | registry */
    public string $payloadName = "raw";

    /** 鉴权 token 表（token => uid） */
    public array $authTokens = [];

    public string $authSecret = "";

    private function __construct()
    {
    }

    /** 从 Config 读一次，之后都走这个对象的字段 */
    public static function load(): self
    {
        $c = new self();
        $c->host = Config::getString("server.host", "0.0.0.0");
        $c->port = Config::getInt("server.port", 9001);
        $c->transport = Config::getString("server.transport", "tcp");
        $c->workerNum = Config::getInt("server.worker_num", 1);
        $c->maxConn = Config::getInt("server.max_conn", 10000);
        $c->nodelay = Config::getBool("server.nodelay", true);
        $c->gateway = Config::getBool("server.gateway", true);
        $c->heartbeatSec = Config::getInt("server.heartbeat_sec", 30);
        $c->ratePerSec = Config::getInt("server.rate_per_sec", 50);
        $c->rateBurst = Config::getInt("server.rate_burst", 100);
        $c->requireAuth = Config::getBool("server.auth.require", true);
        $c->sendFailLimit = Config::getInt("server.send_fail_limit", 50);
        $c->frameName = Config::getString("protocol.frame", "native");
        $c->payloadName = Config::getString("protocol.payload", "raw");
        $c->authTokens = Config::getMap("server.auth.tokens");
        $c->authSecret = Config::getString("server.auth.secret", "");
        return $c;
    }

    /** 给传输层工厂的选项；单独一个方法是因为它的形状由工厂决定，不是配置本身的形状 */
    public function serverOpts(): array
    {
        return [
            "worker_num" => $this->workerNum,
            "max_conn" => $this->maxConn,
            "nodelay" => $this->nodelay,
        ];
    }
}
