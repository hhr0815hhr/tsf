<?php

namespace TSF\Gateway;

use TSF\Kernel\Logger;

/**
 * 简单的 token → uid 判定。
 *
 * 现在支持两种来源：
 *   1. 配置文件里的静态表（server.auth.tokens，形如 {"tok-1001": 1001}）
 *   2. HMAC 签名：token = uid . "." . hmac_sha256(uid, secret)
 *
 * 生产环境应该换成"登录服签发 + 网关验签"的短时票据；这里的实现是为了
 * 把登录链路打通，验证"验签 → 绑定 uid → 顶号"这条流程。
 */
final class TokenAuth
{
    /** @var array<string, int> token => uid */
    private array $static = [];

    private string $secret = "";

    private bool $allowEmpty = false;

    /**
     * @param array<string, int> $staticTokens
     */
    public function __construct(array $staticTokens, string $secret = "", bool $allowEmpty = false)
    {
        $this->static = $staticTokens;
        $this->secret = $secret;
        $this->allowEmpty = $allowEmpty;
    }

    /** 返回 uid；0 表示无效 */
    public function resolve(string $token): int
    {
        if ($token === "") {
            return 0;
        }
        if (isset($this->static[$token])) {
            return $this->static[$token];
        }
        if ($this->secret === "") {
            return 0;
        }
        // 形如 "1001.<hex>"
        $dot = strpos($token, ".");
        if ($dot === false) {
            return 0;
        }
        $uidStr = substr($token, 0, $dot);
        $sig = substr($token, $dot + 1);
        if ($uidStr === "" || !is_numeric($uidStr)) {
            return 0;
        }
        $uid = (int) $uidStr;
        $expect = hash_hmac("sha256", $uidStr, $this->secret);
        if (hash_equals($expect, $sig)) {
            return $uid;
        }
        return 0;
    }

    /** 供测试/工具生成签名 token */
    public function sign(int $uid): string
    {
        if ($this->secret === "") {
            return "";
        }
        return (string) $uid . "." . hash_hmac("sha256", (string) $uid, $this->secret);
    }
}
