<?php

// wasm 桥接的 PHP 侧存根：函数体在 cpp-src/wasm_bridge.cc。
// tpc 靠这个文件把 php_wasm_* 绑定成 PHP 函数；不写在这里，PHP 侧调用会
// 直接报 "function 'wasm_load' is undefined"（这个坑踩过）。

function wasm_version(): string
{
}

function wasm_load(string $name, string $path): int
{
}

function wasm_call2(int $h, string $fn, int $a, int $b): int
{
}

function wasm_call1(int $h, string $fn, int $a): int
{
}

function wasm_close(int $h): void
{
}
