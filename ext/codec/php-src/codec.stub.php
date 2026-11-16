<?php

// 原生编解码扩展的存根（实现见 ext/codec/cpp-src/codec.cc）
//
// 三个函数都无状态：帧长度由调用方自己持有，不在 C++ 侧留全局变量。
// 上一版用 c_consumed() 读全局，虽然单线程安全，但一旦有嵌套调用就会串数据，
// 这类隐患不值得省一次函数调用。

function c_encode(int $msgId, string $body, int $flags): string
{
}

/**
 * 返回整帧长度（含 8 字节头）；数据不足返回 0；非法帧头返回 -1。
 */
function c_frame_len(string $buf): int
{
}

/**
 * 取出 body。$len 必须是 c_frame_len 返回的正值。
 */
function c_decode_body(string $buf, int $len): string
{
}
