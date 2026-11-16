<?php

namespace TSF\Proto;

/**
 * 协议编解码错误。**一律带上下文**：出错的字段号和位置是排查的全部线索。
 */
final class Error extends \RuntimeException
{
}
