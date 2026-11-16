<?php

namespace TSF\Gm;

/**
 * GM 指令的执行结果。
 *
 * 用类而不是 `array<string, mixed>`：lint 禁 mixed 类型，而且异构数组在 AOT 下
 * 反复下标访问会触发类型校验异常（`Kernel\Config` 记过这个坑）。
 *
 * `json` 是**已经序列化好的**片段（不带外层括号），直接拼进响应：
 * `{"ok":true,"data":<json>}`。这样 handler 想返回什么结构都行，
 * 而框架不需要理解它。
 */
final class Result
{
    public bool $ok = true;

    public string $error = "";

    /** 成功时的 data 部分（JSON 片段，默认空对象） */
    public string $json = "{}";

    public static function ok(string $json = "{}"): Result
    {
        $r = new Result();
        $r->ok = true;
        $r->json = $json === "" ? "{}" : $json;
        return $r;
    }

    public static function fail(string $error): Result
    {
        $r = new Result();
        $r->ok = false;
        $r->error = $error;
        return $r;
    }

    public function body(): string
    {
        if ($this->ok) {
            return "{\"ok\":true,\"data\":" . $this->json . "}";
        }
        $msg = json_encode($this->error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return "{\"ok\":false,\"error\":" . ($msg === false ? "\"error\"" : $msg) . "}";
    }
}
