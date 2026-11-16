<?php

namespace TSF\Wasm;

/**
 * 名字 → 模块实例。热更时按名字找到当前实例再 swap，
 * 不用把 Module 一路传下去。
 */
final class Registry
{
    /** @var array<string, Module> */
    private static array $map = [];

    public static function attach(string $name, Module $m): void
    {
        self::$map[$name] = $m;
    }

    public static function detach(string $name): void
    {
        unset(self::$map[$name]);
    }

    public static function get(string $name): ?Module
    {
        return self::$map[$name] ?? null;
    }

    public static function names(): array
    {
        return array_keys(self::$map);
    }
}
