# ext/interp —— 让 AOT 产物执行外部 PHP 脚本

> **状态：可行性已验证 + 异常隔离已做**，编排（灰度 / 回滚 / 广播）在 `pkg/script`。
> 完整记录见 TypeServer 仓库的 `docs/research/hot-reload.md`。

**它随 composer 包分发**（`vendor/hhr0815hhr/tsf/ext/interp`，2026-11-27 起从 TypeServer 根仓库移入）：
`pkg/script` 的运行时就是它。源码在这里，`.so` 必须在目标机上现编——扩展 ABI 绑定具体的
PHP 构建（>= 8.5 + ZTS），预编译产物换台机器就加载不了。所以 `composer require` 之后要跑一次
`build.sh`，用法见下面「编译」。

## 它解决什么

让 `tsf build` 出来的 AOT 二进制**执行外部 PHP 脚本文件** ——
这是「PHP 脚本代替 Lua」的核心能力（AOT 管性能，脚本管可热更的逻辑）。

```
AOT 二进制（C++ 对象、帧循环）
  ├── libphp.so（运行时，sapi=embed）
  ├── interp.so（本扩展：能编译文件、能执行）
  └── /opt/scripts/*.php（改了就重新 require）
```

## 三个函数

| 函数 | 状态 | 说明 |
|:--|:--:|:--|
| `interp_require_file(string $path)` | ✅ | `zend_execute_scripts`，脚本里的函数会被正确注册 |
| `interp_call_obj(string $fn, object $obj)` | ✅ | 按函数名调，传对象（脚本用 `function` 定义时） |
| `interp_invoke($fn, object $obj)` | ✅ | 调运行期拿到的闭包。**异常隔离**：throw 返回 `{ok:false,class,message}`，Fatal 返回 `{ok:false,fatal}` |
| `interp_eval_string(string $code)` | ❌ | `zend_eval_stringl` 在 ZTS 下 `CG()` 上下文错乱，报 `expecting ";"` |
| `interp_probe()` | ✅ | 诊断：编译器指针是否就绪、sapi 名、EG 是否初始化 |

### `interp_invoke` 的错误结构

脚本抛异常时**不抛给宿主**，返回结构化数组：

| 字段 | 说明 |
|:--|:--|
| `ok` | `false` |
| `class` | 异常类名 |
| `message` | 消息 |
| `file` / `line` | 抛出点（2026-11-27 补） |
| `trace` | `getTraceAsString()` 的原文（2026-11-27 补） |
| `fatal` / `timeout` | 只有 bailout 分支才有 |

**`timeout` 怎么判**：设了执行预算、又发生了 bailout 时，比较进入前后的**进程 CPU 时间**
（`clock()`）——和 `zend_set_timeout` 内部的 `ITIMER_PROF` 是同一个口径，留 5% 容差
（实测两者差 3–5 毫秒）。2026-11-27 之前用 `time(NULL)` 相减：墙钟、整秒精度，
墙钟在那 1 秒里往回拨一下（WSL2、云主机会校正时钟），一次真超时就被判成"别的 fatal"。

为什么非要 file/line：只有 class+message 时，2000 行的脚本报一句
`business failed`，运维只能人肉翻。Lua 的 `error()` 默认带 `file:line`，
这是脚本能不能上生产的分界，不是"调试舒服"的问题。

两个实现坑（改这段 C 还会遇到）：

1. `zend_call_method` / `zend_clear_exception` 不在 `php.h` 里，要 include
   `Zend/zend_interfaces.h`、`Zend/zend_exceptions.h`。隐式声明会把 `zval*`
   截成 `int`，症状是"方法调了但什么都没拿到"（trace 一开始就是这样丢的）。
2. 取 trace 必须在 `EG(exception)` 清掉之后：`zend_call_function` 在已有异常时
   拒绝执行。顺序是 `GC_ADDREF` → `zend_clear_exception` → 读属性/取 trace
   → `OBJ_RELEASE`。

## 编译

```bash
bash vendor/hhr0815hhr/tsf/ext/interp/build.sh      # composer 装的包
# 或在 TypeServer 仓库里：bash tsf/ext/interp/build.sh
# 产物 modules/interp.so；已编过且源码没变时直接退出
```

`build.sh` 默认用 `php85` / `phpize85` / `php-config85`；PHP 8.5 就是默认 `php` 的机器加
`PHP_BIN=php PHPIZE=phpize PHP_CONFIG=php-config`。它会先确认 PHP >= 8.5 且是 ZTS，否则直接失败并说出原因。

手工等价于：

```bash
cd vendor/hhr0815hhr/tsf/ext/interp
phpize85
./configure --with-php-config=/usr/local/php-8.5-zts/bin/php-config
make
# 产物 modules/interp.so
```

**改了 `interp.c` 要重编**（`make` 即可，ABI 没变）：所有依赖 interp 的套件测的都是那份 `.so`，
不重编就是在测旧代码——和 `run.sh` 跑旧产物是同一类问题。

## 在 AOT 产物里加载

扩展不进系统目录也能用：

```bash
mkdir -p /tmp/myconf
echo "extension=$PWD/modules/interp.so" > /tmp/myconf/interp.ini

PHP_INI_SCAN_DIR='/tmp/myconf:/usr/local/php-8.5-zts/etc/conf.d' ./你的工程
```

**注意**：`/usr/local/php-8.5-zts/etc/conf.d` 必须保留，否则 swoole 等扩展不会加载。

## 验证过的

```
$ PHP_INI_SCAN_DIR=... ./extprobe
interp loaded: yes
script exists: yes

--- require ---
hello from dynamic file      ← 脚本真的执行了
return: true
```

## 验证过的完整链路

```
$ PHP_INI_SCAN_DIR=... ./extprobe
interp: yes

=== 1. 加载脚本 ===
require: true
onQuest exists: yes

=== 2. 传对象给脚本 ===
before  gold=500  name=alice
return  700
after   gold=700  name=alice          ← 脚本改了宿主对象

结论：对象共享，脚本改的就是 AOT 的同一个对象
```

**热更**：把脚本的 `+100` 改成 `+200`，同一个二进制重跑，`after` 从 600 变 700。

## 异常隔离（0.5.0）

脚本抛异常或触发 Fatal，进程都不会死：

```
$ 脚本 throw new RuntimeException("...")
return: array ('ok' => false, 'class' => 'RuntimeException', 'message' => '...')
退出码: 0

$ 脚本 trigger_error('...', E_USER_ERROR)
PHP Fatal error: ...
return: array ('ok' => false, 'fatal' => 'script caused a bailout (fatal error / timeout / oom)')
退出码: 0
```

实现是 `interp_invoke` 里用 `zend_try { ... } zend_catch { ... } zend_end_try();`
包住 `call_user_function`，区分两种错误：

| 类型 | 机制 | 可恢复 | 返回 |
|:--|:--|:--:|:--|
| `throw` | `EG(exception)` | ✅ | `{ok:false, class, message}` |
| Fatal / 超时 / OOM | `zend_bailout` → `zend_catch` | ❌ | `{ok:false, fatal}` |

**bailout 之后进程状态不可信**，返回 `fatal` 是让上层决定（重启/摘流量/告警），
不代表还能继续安全运行。

## 已知限制

1. **脚本里不能用 `$GLOBALS`** —— AOT 编译阶段就拒绝（`'GLOBALS' was not declared`）
2. **脚本和宿主同权限** —— 脚本能改任何对象，写错就崩。这是共享内存的固有代价
3. **Fatal 之后状态不可信** —— `throw` 被隔离（见上），但 bailout 会跳过所有 `finally` 和析构；
   返回 `fatal` 之后该不该重启由上层决定（`Scheduler::supervise`）

## 别做的事

- **不要用 `zend_eval_stringl`**（实验 2 已证明在 ZTS + embed 下不工作）
- **不要在脚本里用 `$GLOBALS` / 动态变量 / 运行时反射**（AOT 都不支持）
