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
 *
 * ## 作废（2026-11-26 新增）
 *
 * 此前 token 一旦签发，在过期之前没有任何办法让它失效：玩家登出、账号被盗、密钥泄露，
 * 都只能等过期——而 `sign($uid, 0)` 签出来的还是永不过期的。
 *
 * 所以签发格式多了一段**签发时刻**：`uid.iat.exp.sig`。有了它才能说"这个玩家在某时刻之前
 * 签的 token 全部作废"（`revokeUid()`），或者"全服在某时刻之前签的全部作废"（`revokeAll()`）。
 * 玩家重新登录会拿到新的 iat，自然不受影响。
 *
 * | 操作 | 作用 | 典型场景 |
 * |:--|:--|:--|
 * | `revokeUid($uid)` | 该玩家当前所有 token 失效，重新登录即可 | 登出、改密码、封号、踢下线 |
 * | `revokeAll()` | 全部 token 失效 | secret 疑似泄露、紧急止血 |
 * | `addPreviousSecret()` | 旧密钥签的 token 在宽限期内继续有效 | 轮换 secret 而不踢掉全部玩家 |
 *
 * 没有签发时刻的旧格式（2 段 / 3 段）按"签发时刻 = 0"处理：一旦调用过 `revokeUid` / `revokeAll`，
 * 它们就一律失效。2 段格式（永不过期）可以用 `allowLegacy(false)` 直接关掉——**生产环境应该关**。
 *
 * ## 边界
 *
 * 这是**进程内**状态：作废记录只在调用的那个进程里生效。多 worker / 多节点要让全服生效，
 * 得由 GM 通道广播到每个进程（`Gm\Registry` 的命令跑在每个进程上时），或者把作废水位放到共享存储里。
 * 没做后者，因为那要核心依赖 Redis——可以做在 `pkg/redis` 里，目前没做。
 */
final class TokenAuth
{
    /** @var array<string, int> token => uid */
    private array $static = [];

    private string $secret = "";

    private bool $allowEmpty = false;

    /** 是否接受 2 段的永不过期格式。默认开（不打断已有部署）；生产应该关掉 */
    private bool $allowLegacy = true;

    /** 全局作废水位：签发时刻早于它的 token 一律无效。0 = 没作废过 */
    private int $revokedBefore = 0;

    /** @var array<int, int> uid => 该玩家在这个时刻之前签发的 token 已作废 */
    private array $revokedUidBefore = [];

    /** @var array<int, string> 轮换前的密钥：只验不签，给在途 token 一个宽限期 */
    private array $previousSecrets = [];

    /**
     * @param array<string, int> $staticTokens
     * @param bool $allowLegacy 是否接受 `uid.sig`（永不过期）的旧格式
     */
    public function __construct(
        array $staticTokens,
        string $secret = "",
        bool $allowEmpty = false,
        bool $allowLegacy = true
    ) {
        $this->static = $staticTokens;
        $this->secret = $secret;
        $this->allowEmpty = $allowEmpty;
        $this->allowLegacy = $allowLegacy;
    }

    /** 是否接受永不过期的 2 段格式。生产环境应当关掉 */
    public function allowLegacy(bool $on): void
    {
        $this->allowLegacy = $on;
    }

    /**
     * 轮换密钥时登记旧密钥：旧密钥签的 token 在过期之前继续有效。
     * 签发始终用当前密钥。等旧 token 全部过期之后就可以不再登记它。
     */
    public function addPreviousSecret(string $secret): void
    {
        if ($secret !== "" && !in_array($secret, $this->previousSecrets, true)) {
            $this->previousSecrets[] = $secret;
        }
    }

    /**
     * 作废某个玩家当前所有的 token：他重新登录拿到的新 token 不受影响。
     *
     * @param int $issuedBeforeTs 作废"这个时刻之前签发的"；不传 = 现在
     */
    public function revokeUid(int $uid, int $issuedBeforeTs = 0): void
    {
        if ($uid <= 0) {
            return;
        }
        $ts = $issuedBeforeTs > 0 ? $issuedBeforeTs : time();
        // 只升不降：连续作废两次，取较晚的那个水位
        if ($ts > ($this->revokedUidBefore[$uid] ?? 0)) {
            $this->revokedUidBefore[$uid] = $ts;
        }
    }

    /**
     * 作废**全部**签名 token（静态表里的不受影响）：secret 疑似泄露时用。
     *
     * 它只让 token 失效，不换密钥——攻击者拿着泄露的 secret 仍然可以签出新 token。
     * 所以调用之后还要换 secret；两者配合才是完整的止血。
     *
     * @param int $issuedBeforeTs 作废"这个时刻之前签发的"；不传 = 现在
     */
    public function revokeAll(int $issuedBeforeTs = 0): void
    {
        $ts = $issuedBeforeTs > 0 ? $issuedBeforeTs : time();
        if ($ts > $this->revokedBefore) {
            $this->revokedBefore = $ts;
        }
    }

    /** 清掉所有作废记录（测试 / 自省用） */
    public function clearRevocations(): void
    {
        $this->revokedBefore = 0;
        $this->revokedUidBefore = [];
    }

    /** 当前签发格式里一段签名体对应的 mac */
    private function mac(string $payload, string $secret): string
    {
        return hash_hmac("sha256", $payload, $secret);
    }

    /** 这段签名体是不是当前密钥或任一旧密钥签的 */
    private function signatureMatches(string $payload, string $sig): bool
    {
        if ($this->secret !== "" && hash_equals($this->mac($payload, $this->secret), $sig)) {
            return true;
        }
        foreach ($this->previousSecrets as $old) {
            if (hash_equals($this->mac($payload, $old), $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 返回 uid；0 表示无效。
     *
     * 认三种签名格式：
     *   `uid.iat.exp.sig`（当前）—— 过期、作废都按 iat 判
     *   `uid.exp.sig`（过渡）  —— 没有签发时刻，按 iat = 0 处理
     *   `uid.sig`（最老）      —— 永不过期，默认还认，`allowLegacy(false)` 之后不认
     */
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
        $uid = 0;
        $iat = 0;

        if ($n === 2) {
            // 最老的格式：uid.sig（无过期）
            if (!$this->allowLegacy) {
                return 0;
            }
            $uidStr = $parts[0];
            if ($uidStr === "" || !is_numeric($uidStr)) {
                return 0;
            }
            if (!$this->signatureMatches($uidStr, $parts[1])) {
                return 0;
            }
            $uid = (int) $uidStr;
        } elseif ($n === 3) {
            // 过渡格式：uid.exp.sig。签名覆盖 "uid.exp"，防止改 exp 延长有效期
            $uidStr = $parts[0];
            $expStr = $parts[1];
            if ($uidStr === "" || !is_numeric($uidStr) || !is_numeric($expStr)) {
                return 0;
            }
            if (!$this->signatureMatches($uidStr . "." . $expStr, $parts[2])) {
                return 0;
            }
            if ((int) $expStr < time()) {
                return 0;
            }
            $uid = (int) $uidStr;
        } elseif ($n === 4) {
            // 当前格式：uid.iat.exp.sig。签名覆盖 "uid.iat.exp"
            $uidStr = $parts[0];
            $iatStr = $parts[1];
            $expStr = $parts[2];
            if ($uidStr === "" || !is_numeric($uidStr) || !is_numeric($iatStr) || !is_numeric($expStr)) {
                return 0;
            }
            if (!$this->signatureMatches($uidStr . "." . $iatStr . "." . $expStr, $parts[3])) {
                return 0;
            }
            $now = time();
            if ((int) $expStr < $now) {
                return 0;
            }
            // 签发时刻不能在未来（留 5 分钟时钟偏差）：否则可以签一个"很久以后才生效"的 token，
            // 绕过之后所有的作废操作
            if ((int) $iatStr > $now + 300) {
                return 0;
            }
            $uid = (int) $uidStr;
            $iat = (int) $iatStr;
        } else {
            return 0;
        }

        // 作废检查：没有签发时刻的旧格式 iat = 0，所以一旦作废过就一律不认
        if ($this->revokedBefore > 0 && $iat < $this->revokedBefore) {
            return 0;
        }
        $rev = $this->revokedUidBefore[$uid] ?? 0;
        if ($rev > 0 && $iat < $rev) {
            return 0;
        }
        return $uid;
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
            // 永不过期。只为了兼容老调用方；新代码不要这么签（见类注释）
            return $uidStr . "." . $this->mac($uidStr, $this->secret);
        }
        $iatStr = (string) time();
        $expStr = (string) (time() + $ttl);
        $payload = $uidStr . "." . $iatStr . "." . $expStr;
        return $payload . "." . $this->mac($payload, $this->secret);
    }
}
