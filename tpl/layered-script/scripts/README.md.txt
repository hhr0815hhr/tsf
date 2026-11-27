# scripts/ — 规则层（L2）

这个目录里的 PHP **不参与编译**。它们在运行时由 `ext/interp` 加载，
改完 + 触发重载就生效，不用重启进程。

## 什么该放这里

| 特征 | 放这里 |
|:--|:--|
| 运营每周要调（掉率、活动倍率、任务条件） | ✓ |
| 每人每分钟一两次（领奖判定、成长曲线） | ✓ |
| 每帧都跑 / 每人每秒十几次 | ✗ 放 `src/`（AOT） |
| 动钱、动库存、落库的最后一步 | ✗ 放 `src/`，脚本只能"申请" |

三条都拿不准时，问顺序是：**会动钱吗 → 会改吗 → 跑得多密**。

## 三条硬规矩

1. **只 `return` 一个数组**（入口名 => 闭包），不要定义 `function` / `class`。
   **不是 PHP 不允许**——`function_exists` 包住就能重复 require——而是 **reload 时
   不清理符号表**：直接写会 `Cannot redeclare` 中止；包住之后不报错，但拿到的还是
   第一次的实现，热更等于没做。两条路都是死路，所以只 `return` 闭包。
   这个坑首次加载看不出来，要等第一次重载才炸。

2. **只能 `use TSF\Script\*`**。要发奖、要发消息、要看配置，都从 `Context` 取
   （宿主 `bind` 的能力）。直接 `use TSF\Gateway\Gateway` 就把安全边界破了。

3. **不自己取随机数、不自己读时间**。随机源与时钟由 AOT 通过视图方法提供，
   否则同一局无法回放、掉率和客诉对不上账。

## 改之前先跑测试

```bash
php85 tests/script/run.php
```

**不需要编译、不需要起服务**。原因有两条：`src/Domain/` 不依赖 TSF，原生 PHP
直接 require 就能当脚本的 stub 视图；脚本由 `interp.so` 加载，和线上是同一份运行时，
所以测过的就是线上跑的。缺扩展时退出码 77（回归里算 SKIP）。

脚本抛异常时，返回的结构现在带位置，直接看得到哪一行：

```php
[
    "ok" => false,
    "class" => "LogicException",
    "message" => "...",
    "file" => "/app/scripts/levelup.php",
    "line" => 21,
    "trace" => "#0 /app/scripts/levelup.php(18): {closure}()\n#1 ...",
]
```

没有这三样，"2000 行的脚本报一句 business failed"就只能人肉翻。

## 改完怎么生效

```php
// 业务 Service 里持有 Manager
$mgr->reload("levelup");     // 指定一个
$mgr->reloadAll();           // 全部
```

**不要**把 `watchAll()` 挂到定时器上当默认行为：那等于"改文件即刻上线"，
没有确认、没有审计，出事也查不到是谁改的。调试期要自动可以，但只在开发配置里开。

要灰度就先 `registerAlt()` 再 `setGray($name, $pct)`：
注册了不等于启用，比例默认 0。完整流程见框架仓库 `demo/rule-hot/`。

## 这个骨架里的例子

`levelup.php` 是"升级奖励"判定，返回 `"gold:1000;item:3001:1"` 这样的字符串。
`src/App/RuleService.php` 在启动时加载它并跑一次自检，日志里能看到：

```
rule self-check ok  sample=gold:1000;item:3001:1
```

自检失败不会让服务起不来（脚本坏了业务还得活着），但会在日志里报 error。
