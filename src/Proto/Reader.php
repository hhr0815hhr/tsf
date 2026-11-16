<?php

namespace TSF\Proto;

/**
 * protobuf wire format 的解码游标。
 *
 * 生成的 `Xxx::decode()` 长这样：
 *
 * ```php
 * $m = new LoginReq();
 * $r = new Reader($buf, "LoginReq");
 * while ($r->next()) {
 *     $f = $r->field();
 *     if ($f === 1) { $m->account = $r->string(); }
 *     elseif ($f === 2) { $m->level = $r->int32(); }
 *     else { $r->skip(); }        // 不认识的字段必须跳过
 * }
 * ```
 *
 * ## 三个必须做对的地方
 *
 * **1. 不认识的字段要 skip，不能报错。**
 * 前后端版本不一致是常态（客户端发版不可控）。服务端遇到新字段只能跳过——
 * 报错就等于"老服务端不能接新客户端"，那这个协议就没法演进了。
 *
 * **2. 已知字段要校验 wire type。**
 * 反过来，字段号认识但类型对不上（改过协议、或者对方编错了），继续读下去会
 * 把后面的字节全解歪，报出来是"某个字段值离谱"。这里当场抛错，带字段号。
 *
 * **3. repeated 标量可能是 packed，也可能是逐个的。**
 * proto3 默认 packed（一个 length-delimited 里塞 N 个值），但**解析端必须两种都收**：
 * 老实现、或者手写的客户端经常逐个发。所以 `packedVarints()` 之外，
 * 生成代码在 wire type 是 VARINT 时也要能 `$arr[] = $r->varint()` 追加。
 */
final class Reader
{
    private string $buf;

    private int $len;

    private int $pos = 0;

    private int $field = 0;

    private int $wt = 0;

    /** 出错时打出来，能直接定位是哪个消息 */
    private string $ctx;

    public function __construct(string $buf, string $ctx = "proto")
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
        $this->ctx = $ctx;
    }

    /** 前进到下一个字段。返回 false 表示读完。 */
    public function next(): bool
    {
        if ($this->pos >= $this->len) {
            return false;
        }
        $t = $this->readVarint();
        $this->field = $t >> 3;
        $this->wt = $t & 7;
        if ($this->field <= 0) {
            throw new Error($this->ctx . ": 字段号非法（" . $this->field . "），数据可能不是 protobuf");
        }
        return true;
    }

    public function field(): int
    {
        return $this->field;
    }

    public function wireType(): int
    {
        return $this->wt;
    }

    public function eof(): bool
    {
        return $this->pos >= $this->len;
    }

    public function varint(): int
    {
        $this->expect(Wire::WT_VARINT);
        return $this->readVarint();
    }

    /** int32：proto3 里负数按 64 位传，读出来要截回 32 位 */
    public function int32(): int
    {
        $v = $this->varint();
        if ($v >= 2147483648) {
            $v -= 4294967296;
        }
        if ($v < -2147483648) {
            $v += 4294967296;
        }
        return $v;
    }

    public function sint(): int
    {
        return Wire::unzigzag($this->varint());
    }

    public function boolean(): bool
    {
        return $this->varint() !== 0;
    }

    public function fixed32(): int
    {
        $this->expect(Wire::WT_32BIT);
        $v = $this->pos;
        $this->need(4);
        $this->pos += 4;
        $r = unpack("V", substr($this->buf, $v, 4));
        return is_array($r) && isset($r[1]) ? (int) $r[1] : 0;
    }

    public function fixed64(): int
    {
        $this->expect(Wire::WT_64BIT);
        $v = $this->pos;
        $this->need(8);
        $this->pos += 8;
        $r = unpack("P", substr($this->buf, $v, 8));
        return is_array($r) && isset($r[1]) ? (int) $r[1] : 0;
    }

    public function float(): float
    {
        $this->expect(Wire::WT_32BIT);
        $v = $this->pos;
        $this->need(4);
        $this->pos += 4;
        $r = unpack("g", substr($this->buf, $v, 4));
        return is_array($r) && isset($r[1]) ? (float) $r[1] : 0.0;
    }

    public function double(): float
    {
        $this->expect(Wire::WT_64BIT);
        $v = $this->pos;
        $this->need(8);
        $this->pos += 8;
        $r = unpack("e", substr($this->buf, $v, 8));
        return is_array($r) && isset($r[1]) ? (float) $r[1] : 0.0;
    }

    public function string(): string
    {
        $this->expect(Wire::WT_LEN);
        $n = $this->readVarint();
        $this->need($n);
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    /** bytes 在 PHP 里就是 string，单独给个名字是为了生成代码好读 */
    public function bytes(): string
    {
        return $this->string();
    }

    /** packed 标量：一个 length-delimited 里连着一串值 */
    public function packedVarints(): array
    {
        $this->expect(Wire::WT_LEN);
        $n = $this->readVarint();
        $this->need($n);
        $end = $this->pos + $n;
        $out = [];
        while ($this->pos < $end) {
            $out[] = $this->readVarint();
        }
        return $out;
    }

    public function packedSints(): array
    {
        $raw = $this->packedVarints();
        $out = [];
        foreach ($raw as $v) {
            $out[] = Wire::unzigzag($v);
        }
        return $out;
    }

    public function packedFloats(): array
    {
        $this->expect(Wire::WT_LEN);
        $n = $this->readVarint();
        $this->need($n);
        if ($n % 4 !== 0) {
            throw new Error($this->ctx . ": packed float 长度 " . $n . " 不是 4 的倍数");
        }
        $out = [];
        $end = $this->pos + $n;
        while ($this->pos < $end) {
            $r = unpack("g", substr($this->buf, $this->pos, 4));
            $out[] = is_array($r) && isset($r[1]) ? (float) $r[1] : 0.0;
            $this->pos += 4;
        }
        return $out;
    }

    public function packedDoubles(): array
    {
        $this->expect(Wire::WT_LEN);
        $n = $this->readVarint();
        $this->need($n);
        if ($n % 8 !== 0) {
            throw new Error($this->ctx . ": packed double 长度 " . $n . " 不是 8 的倍数");
        }
        $out = [];
        $end = $this->pos + $n;
        while ($this->pos < $end) {
            $r = unpack("e", substr($this->buf, $this->pos, 8));
            $out[] = is_array($r) && isset($r[1]) ? (float) $r[1] : 0.0;
            $this->pos += 8;
        }
        return $out;
    }

    /** 跳过不认识的字段 */
    public function skip(): void
    {
        if ($this->wt === Wire::WT_VARINT) {
            $this->readVarint();
            return;
        }
        if ($this->wt === Wire::WT_64BIT) {
            $this->need(8);
            $this->pos += 8;
            return;
        }
        if ($this->wt === Wire::WT_LEN) {
            $n = $this->readVarint();
            $this->need($n);
            $this->pos += $n;
            return;
        }
        if ($this->wt === Wire::WT_32BIT) {
            $this->need(4);
            $this->pos += 4;
            return;
        }
        // wire type 3/4 是 proto2 的 group，proto3 不用；6/7 非法
        throw new Error($this->ctx . ": 字段 " . $this->field . " 的 wire type " . $this->wt . " 无法跳过");
    }

    private function readVarint(): int
    {
        $v = 0;
        $shift = 0;
        $n = 0;
        while (true) {
            if ($this->pos >= $this->len) {
                throw new Error($this->ctx . ": 数据在 varint 中间截断（字段 " . $this->field . "）");
            }
            $b = ord($this->buf[$this->pos]);
            $this->pos++;
            $v |= ($b & 0x7F) << $shift;
            if (($b & 0x80) === 0) {
                return $v;
            }
            $shift += 7;
            $n++;
            if ($n > 9) {
                throw new Error($this->ctx . ": varint 超过 10 字节（字段 " . $this->field . "）");
            }
        }
    }

    private function expect(int $wt): void
    {
        if ($this->wt !== $wt) {
            $name = ["varint", "64bit", "len", "?", "?", "32bit"];
            throw new Error(
                $this->ctx . ": 字段 " . $this->field . " 的 wire type 不对，期望 "
                . ($name[$wt] ?? (string) $wt) . "，实际 " . ($name[$this->wt] ?? (string) $this->wt)
            );
        }
    }

    private function need(int $n): void
    {
        if ($n < 0) {
            throw new Error($this->ctx . ": 长度为负（字段 " . $this->field . "）");
        }
        if ($this->pos + $n > $this->len) {
            throw new Error(
                $this->ctx . ": 字段 " . $this->field . " 声明 " . $n . " 字节，但只剩 "
                . ($this->len - $this->pos) . " 字节"
            );
        }
    }
}
