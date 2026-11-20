<?php

namespace TSF\Http;

/**
 * 按来源 IP 放行/拒绝。给 `/metrics` 这类"只该内网访问"的路由用。
 *
 * ## 为什么需要它，而不是"配个 nginx"
 *
 * `/metrics` 会暴露在线人数、结算金额、进程内存。文档里写一句"挂内网"不够——
 * 部署的时候没人会回头读文档，而默认暴露的代价是**一次配置疏忽就把运营数据公开**。
 * 安全默认必须是"拒绝"，放开要显式写。
 *
 * ## 支持什么
 *
 * - 精确 IPv4 / IPv6：`127.0.0.1`、`::1`
 * - IPv4 CIDR：`10.0.0.0/8`、`192.168.1.0/24`
 * - `*` 全放开（明确表示"我知道自己在干什么"）
 *
 * IPv6 只做精确匹配，不支持 CIDR —— 需要的话应该由前置代理做，
 * 在这里实现 IPv6 前缀比较的收益远小于出错风险。
 *
 * ## 不做反向代理头
 *
 * 不看 `X-Forwarded-For`：那玩意客户端可伪造，用它做访问控制等于没有控制。
 * 真要经代理，就在代理层限制，或者把代理地址加进白名单、由代理保证边界。
 */
final class IpFilter
{
    /** 只允许本机与私有网段（RFC1918 + 回环）。这是 `/metrics` 的默认值 */
    public const PRIVATE_ONLY = [
        "127.0.0.1",
        "::1",
        "10.0.0.0/8",
        "172.16.0.0/12",
        "192.168.0.0/16",
    ];

    /**
     * @param array<int, string> $allow 白名单；含 `*` 表示不限制
     */
    public static function allows(array $allow, string $ip): bool
    {
        if ($allow === []) {
            return false;
        }
        $addr = trim($ip);
        if ($addr === "") {
            return false;   // 拿不到来源就当公网：拒绝比放行安全
        }
        foreach ($allow as $rule) {
            $r = trim($rule);
            if ($r === "*") {
                return true;
            }
            if ($r === $addr) {
                return true;
            }
            if (strpos($r, "/") !== false && self::inCidr($addr, $r)) {
                return true;
            }
        }
        return false;
    }

    /** IPv4 CIDR 判断；非 IPv4 或掩码非法一律 false（宁可拒绝） */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $parts = explode("/", $cidr);
        if (count($parts) !== 2) {
            return false;
        }
        $net = $parts[0];
        $bits = $parts[1];
        if (preg_match('/^\d{1,2}$/', $bits) !== 1) {
            return false;
        }
        $len = (int) $bits;
        if ($len < 0 || $len > 32) {
            return false;
        }
        $ipLong = self::ipv4ToLong($ip);
        $netLong = self::ipv4ToLong($net);
        if ($ipLong < 0 || $netLong < 0) {
            return false;
        }
        if ($len === 0) {
            return true;
        }
        // 用移位而不是除法：/0 时 1 << 32 会溢出成 1，正是这里要避开的
        $mask = ((1 << $len) - 1) << (32 - $len);
        return ($ipLong & $mask) === ($netLong & $mask);
    }

    /** 点分十进制 → 无符号整数；非法返回 -1 */
    private static function ipv4ToLong(string $ip): int
    {
        $segs = explode(".", trim($ip));
        if (count($segs) !== 4) {
            return -1;
        }
        $out = 0;
        foreach ($segs as $s) {
            if (preg_match('/^\d{1,3}$/', $s) !== 1) {
                return -1;
            }
            $n = (int) $s;
            if ($n > 255) {
                return -1;
            }
            $out = ($out << 8) | $n;
        }
        return $out;
    }
}
