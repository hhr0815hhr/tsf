<?php

namespace TSF\Wasm;

/**
 * 一个已加载的 wasm 模块。
 *
 * 只做三件事：加载、按名调用、关闭。**不做**参数编解码的猜测——
 * 第一版只支持 (i64, i64) -> i64，够验证链路；字符串/数组的参数映射
 * 要单独设计（谁分配线性内存、谁释放），不能在这里顺手加。
 */
final class Module
{
    private string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * 加载已预编译的 .cwasm。
     *
     * 路径必须在构建时用 `tsf` 项目里的 tp-precompile 产出——`wasmtime compile`
     * 出来的产物配置不一致，宿主会拒绝启动。
     */
    public static function load(string $name, string $path): ?self
    {
        $rc = \wasm_load($name, $path);
        if ($rc !== 0) {
            return null;
        }
        return new self($name);
    }

    /** 按名调用，两个整数参数。名字不存在返回负值（宿主运行时解析，无白名单）。 */
    public function call2(string $fn, int $a, int $b): int
    {
        return \wasm_call2(0, $fn, $a, $b);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** 桥接层版本，用来确认原生部分真的编进去了 */
    public static function bridgeVersion(): string
    {
        return \wasm_version();
    }

    public function close(): void
    {
        \wasm_close(0);
    }
}
