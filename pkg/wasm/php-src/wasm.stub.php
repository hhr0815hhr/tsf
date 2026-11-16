<?php

// wasm 桥接的 PHP 侧存根：函数体在 cpp-src/wasm_bridge.cc。
// tpc 靠这个文件把 php_wasm_* 绑定成 PHP 函数；不写在这里，PHP 侧调用会报
// "function 'wasm_load' is undefined"（踩过）。

function wasm_version(): string
{
}

function wasm_load(string $name, string $path): int
{
}

function wasm_canary(int $slot, string $path): int
{
}

function wasm_ratio(int $slot, int $permille): int
{
}

function wasm_call2(int $slot, int $key, string $fn, int $a, int $b): int
{
}

function wasm_swap(int $slot, string $path): int
{
}

function wasm_stats(int $slot): string
{
}

function wasm_close(int $slot): void
{
}
