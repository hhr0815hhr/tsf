# 变更记录

> 面向 `composer require hhr0815hhr/tsf` 的用户。每个 tag 一节，只写**对你有影响**的：
> 新能力、行为变化、要跟着改的东西。实测数据、测试套件、CI 这些开发过程在
> TypeServer 仓库的 `CHANGELOG.md`（那边更长，也是事实来源）。
>
> 版本规则：tag 打在本仓库（`vX.Y.Z`），`composer.json` 的 `version` 与之一致。
> 预发布版带 `-rc.N`，`minimum-stability: stable` 下装不到，要显式 `@rc`。

---

## 1.0.0-rc.2（2026-11-27）

发布 rc.1 几分钟后发的修复版，只改一处：

### 修复

- **`tsf lint` 在单独安装的包里必然判红**。lint 里的"回归套件注册表"三条规则
  （目录有 `main.php` 就必须注册、注册的脚本必须存在、必须编译）只在 TypeServer
  仓库里有意义；包被单独装出来（`vendor/hhr0815hhr/tsf`）时上一级没有 `tests/`，
  每个套件都被报成"注册了不存在的脚本"。现在检测到上一级没有 `tests/` 就跳过这三条。
  rc.1 里 `vendor/bin/tsf lint` 不带参数会直接退出码 1——**这就是用 rc.2 的理由**。
  它是 tsf 仓库的 CI 第一次在 GitHub 上跑时暴露的：本地 tsf 是 TypeServer 的子模块，
  上一级有 `tests/`，永远复现不了。

框架代码（`src/`、`pkg/`、`ext/`）与 rc.1 完全相同。

---

## 1.0.0-rc.1（2026-11-27）

1.0 的第一个候选版。功能面和运行闭环都过了 1.0 门槛，但转正前还要做三件事：
浸泡（代码几乎全部是两天内写的，没有真实流量）、API 稳定性分级、CI 在 GitHub 上跑绿。
**不建议现在用于生产**；可以用来试新能力、提 API 方面的意见——现在改还来得及。

### 你需要做的

- **用到 `pkg/script` 的，装完包之后跑一次 `bash vendor/hhr0815hhr/tsf/ext/interp/build.sh`。**
  `ext/interp` 的源码现在随包分发（此前只在 TypeServer 仓库，包里没有，`pkg/script` 装了也用不了）。
  `.so` 必须在目标机上现编——扩展 ABI 绑定具体的 PHP 构建（>= 8.5 + ZTS）。

### 新增

- **分布式锁 `TSF\Redis\Lock`**：`acquire / release / extend / owner`。释放与续期用 Lua 先比较
  token 再操作，不会删掉别人的锁；不提供"等锁"，重试交给定时器。
  顺带修了 `Client` 的接口缺陷：`cmd()` 对返回 `bool` 的命令（如 `SET`）会吞成 `null`，新增 `cmdBool()`。
- **HTTP 出站客户端 `TSF\Http\Client`**：`get / post / request`，失败不抛异常
  （`status=0` + `error`）；**默认清空 `CURLOPT_PROXY`**，不受环境变量 `http_proxy` 影响；
  不跟随 302，不自动重试。
- **分层骨架带脚本层**：`tsf new --layout=layered --packages=script` 生成 `scripts/`、
  `App/RuleService.php`（加载 / 调用 / 重载）和 `Domain/PlayerView.php`，启动时自检一次。
- **`ext/interp` 进包**：见上。
- **`interp_invoke` 的异常返回带 `file` / `line` / `trace`**。

### 变更

- **`worker_num > 1` 时 `Scheduler::create()` 打一条 WARN**（只打一次）。多 worker 是 fork 复制，
  不是分片：每个 worker 各跑一份 Service，状态不共享。有状态服务的扩容方式是多节点，不是 `worker_num`。
- **本仓库有了自己的 CI**（lint / `php -l` / `composer validate` / 三种模板生成），约 3 分钟。
  全量回归在 TypeServer 仓库。
- **`tsf lint` 对"解释执行的套件"改为显式声明**（`verifySuites()` 条目里写 `"noBuild" => "原因"`）。
  只影响往 TypeServer 的回归里加套件的人。
- Docker 镜像的工具链改用独立 tag `toolchain-<typephp 版本>`，不再借用框架的版本号。

---

## 1.0.0-rc.1 之前（v0.13.0 — v0.17.0）

这五个版本都在 2026-11-26 打出，是 1.0 门槛（`ROADMAP.md` 的 P0）落地的一批。

| 版本 | 内容 |
|:--|:--|
| **v0.17.0** | `tsf new --layout=layered`：生成分层骨架（Protocol / Domain / App / Infra），不再是单个 `main.php` |
| **v0.16.0** | `Scheduler::setMemoryPressure(80, 92)`：OOM 是 fatal，什么现场都留不下，所以在到阈值**之前**落 dump |
| **v0.15.1** | **修复**：cron 定时器按单调时钟算日期，落点全错（"每天 3 点"落在别的时候，或立即触发）。此前 `timer-test` 一直是绿的 |
| **v0.15.0** | `Scheduler::warmCoroutines()` 预热协程栈池（5000 并发：耗时 −18%，RSS −31%） |
| **v0.14.1** | **修复**：批量销毁带定时器的服务后，时间轮里的死对象永不释放（不报错、`timers` 显示 0，只有内存在涨） |
| **v0.14.0** | 调度时间预算 `setSlice()` / `setTickBudget()`；单服务内存归因 `setMemGuard()`；脚本灰度 `registerAlt` / `setGray` / `callGray`；跨节点重载广播 `Script\Broadcast`；`tsf lint <工程>` 的规则 6/7 |
| **v0.13.0** | 1.0 的 P0 六项：服务 id 回绕、优雅停机（`WorkerStop`）、集群断链重连（`Cluster::watchPeer`）、日志轮转（`LogFile`、`Logger::to()`）、TLS / WSS、许可证（MIT）。P1：内置指标、连接准入、`Gateway::assemble()`、token 过期与作废、`Discovery\Membership`、GM 一人一个 token |

从 v0.12 升上来没有需要改的调用——这一批全是新增，没有删改公开方法。

---

## v0.12.0（2026-11-23）

### 新增

- `pkg/script` 能"做事"了：受控副作用能力（发奖 / 日志）、能力注册表（定时器 / 发消息 / 读配置）、
  脚本间调用（带深度上限）、脚本私有状态（热更不丢计数）、执行预算（死循环可被中断）、`reload` / `reloadAll`。
- 传输层批量写（pipeline 吞吐 +127%）与批窗口钩子；`tsf new` 交互选包；编译失败时提炼关键错误。

### 修复

- **`pkg/db` 嵌套事务**：内层失败被吞后会静默提交半截数据。
- **AOT 下 `exit(n)` 的退出码恒为 0**：改用信号，集中到 `exitWith()`。
- `migrateSnapshot` 改成整份原子提交，避免半迁移状态。

### 移除

- `Cluster\Link::socket()`（零调用且公开有害）；`SessionManager` 的死方法。
- `pkg/schedule/src/Cron.php`：核心已支持 5/6 字段，合并成一份。

---

## v0.11.0（2026-11-20）

### 新增

- 链路追踪（进程内）：`traceId` 随消息自动继承。
- Prometheus 文本导出 + `/metrics` 路由；RPC 降级自愈与来源限制。
- `pkg/handoff`：单键状态交接（交接协议 → 闸门 → 自动重平衡 → 归属租约 → abort → 状态版本核对）。
- `pkg/eventlog`：状态检查点，恢复不再随日志长度线性增长。
- `Cluster` 支持 TCP 传输（握手）；`pkg/registry` Redis 节点注册表。
- 崩溃取证（环形缓冲 + 现场落盘）；RPC 载荷版本协商；Docker 镜像。

### 修复

- 协程模式漏了"回队"，突发流量后剩余消息永远卡住；协程路径漏了看门狗。
- `pkg/eventlog` 截空日志后序号回退。
- `Cluster` msgId 越界由静默截断改为抛错。

## v0.10.0（2026-11-19）

- GM 异步任务表 `Gm\Task`。

## v0.9.1（2026-11-18）

- 修复：CLI 版本号从 `composer.json` 读，不再硬编码。

## v0.9.0（2026-11-18）

### 新增

- `Kernel\FileLoader`（opcache 失效收进一处，按内容指纹判变更）。
- 跨 worker 通道：`pipeSend` / `onPipeMessage` / `workerId`。
- AOI 按半径查询、`eachAround` 回调遍历。

### 修复

- AOI 九宫格静默漏判；`Script::changed()` 改用内容指纹。

---

v0.8 及更早的记录见 [TypeServer 的 CHANGELOG](https://github.com/hhr0815hhr/TypeServer/blob/main/CHANGELOG.md)。
