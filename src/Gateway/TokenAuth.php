<?php

namespace TSF\Gateway;

use TSF\Kernel\Logger;

/**
 * token → uid 判定，兼签发。
 *
 * 支持三种来源：
 *   1. 配置文件里的静态表（server.auth.tokens，形如 {"tok-1001": 1001}）
 *   2. HMAC 签名（无过期）：`uid . "." . hmac_sha256(uid, secret)`
 *   3. HMAC 签名（带过期）：`uid . "." . exp . "." . hmac_sha256("uid.exp", secret)`
 *
 * ## 谁签谁验
 *
 * 生产链路是「**登录服签发 → 网关验签**」：登录服 `sign($uid, 300)` 出票，
 * 网关用同一个 secret `resolve()` 验。两边是同一个类，所以格式不会写岔。
 *
 * ```php
 * // 登录服（普通 PHP 或 AOT 都行）
 * $auth = new TokenAuth([], $secret);
 * $token = $auth->sign($uid, 300);      // 5 分钟有效
 *
 * // 网关（配置 server.auth.secret）
 * $uid = $auth->resolve($token);        // 0 = 无效或过期
 * ```
 *
 * 无过期格式保留是为了兼容已有部署；**新签发一律带上 ttl**。
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

        $parts = explode(".", $token);
        $n = count($parts);

        if ($n === 2) {
            // 旧格式：uid.sig（无过期）。保留是为了不打断已有部署。
            $uidStr = $parts[0];
            $sig = $parts[1];
            if ($uidStr === "" || !is_numeric($uidStr)) {
                return 0;
            }
            $expect = hash_hmac("sha256", $uidStr, $this->secret);
            if (hash_equals($expect, $sig)) {
                return (int) $uidStr;
            }
            return 0;
        }

        if ($n === 3) {
            // 新格式：uid.exp.sig
            $uidStr = $parts[0];
            $expStr = $parts[1];
            $sig = $parts[2];
            if ($uidStr === "" || !is_numeric($uidStr) || !is_numeric($expStr)) {
                return 0;
            }
            // 签名覆盖 "uid.exp"，防止改 exp 延长有效期
            $expect = hash_hmac("sha256", $uidStr . "." . $expStr, $this->secret);
            if (!hash_equals($expect, $sig)) {
                return 0;
            }
            if ((int) $expStr < time()) {
                return 0;
            }
            return (int) $uidStr;
        }

        return 0;
    }

    /**
     * 签发 token。
     *
     * @param int $uid
     * @param int $ttl 有效秒数；<=0 表示不过期（兼容旧行为，不建议用于生产）
     */
    public function sign(int $uid, int $ttl = 0): string
    {
        if ($this->secret === "") {
            return "";
        }
        $uidStr = (string) $uid;
        if ($ttl <= 0) {
            return $uidStr . "." . hash_hmac("sha256", $uidStr, $this->secret);
        }
        $expStr = (string) (time() + $ttl);
        $payload = $uidStr . "." . $expStr;
        return $payload . "." . hash_hmac("sha256", $payload, $this->secret);
    }
}
