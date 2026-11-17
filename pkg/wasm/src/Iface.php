<?php

namespace TSF\Wasm;

/**
 * wasm 模块必须匹配的宿主接口。
 *
 * ## 为什么必须一致
 *
 * typephp 运行时的 `tp_open(path)` 内部按固定 world 名找导出函数。
 * 模块的 `wasm-package` / `wasm-world` 和这里不一致时，加载返回 **-1**，
 * 而且**不会告诉你原因** —— 这个坑踩过一次（见 tests/wasm-link fixtures 的注释）。
 *
 * ## 为什么只有一组值
 *
 * 这个 world 名硬编码在 typephp 的 C++ host 里（`libphpx` / wasm host），
 * **不在本仓库**。所以想支持自定义接口（比如 `typephp:tsf-app@1.0.0`），
 * 得改 typephp 上游，不是改这里。
 *
 * 在那之前，所有模块都用这一组值。**脚手架 `tsf module add` 从这里读**，
 * 所以不要手改生成的 project.yml 里的这两个字段。
 *
 * ## 如果哪天上游改了
 *
 * 只改这个文件的常量即可 —— 脚手架和业务代码都引用它，不用逐个工程去搜。
 */
final class Iface
{
    /** 对应 project.yml 的 wasm-package */
    public const PACKAGE = "typephp:s1-probe@1.0.0";

    /** 对应 project.yml 的 wasm-world */
    public const WORLD = "s1-probe";
}
