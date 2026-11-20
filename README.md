# TSF — TypePHP Server Framework

> 用 TypePHP（AOT 编译）+ PHP 写游戏服务器，替代「C++ + Lua」。
>
> **性能**：单 worker 稳态 116 万 msg/s（echo）；8 worker 单机 3.4 万在线（10Hz 心跳）。
> **代价**：AOT 之后改不了运行中的代码，热更靠「配置热更 + 内嵌 PHP 脚本（`pkg/script`）+ 滚动重启」三条轨道。

```
客户端 ──TCP/WS──> Gateway ──> Service（业务）──> pkg/db、pkg/redis、pkg/mail ...
                     │                │
                分帧+鉴权+心跳    Scheduler 调度
```

---

## 能力一览

三层：框架本体（编进产物）、可选功能包（按需启用）、开发机上的工具链。

### 框架（`src/`）

| 模块 | 内容 |
|:--|:--|
| `Kernel` | Bootstrap、AppConfig、Logger、Env、FileLoader |
| `Transport` | TCP / WebSocket，分帧在传输层做 |
| `Protocol` | 分帧 + 载荷两层独立可换（`native`/`header` × `raw`/`json`/`msgpack`/`protobuf`/`registry`） |
| `Gateway` | 鉴权、限流、心跳、会话、断线保留、protobuf 编解码 |
| `Service` | 单进程调度：`spawnOf`、`deliver`、`self`、`interval`、`every`、信箱上限 |
| `Rpc` / `Discovery` / `Shard` / `Cluster` | 跨进程调用、服务发现、分片路由、跨服路由、熔断 |
| `Proto` | protobuf 运行时（Reader / Wire / Message） |
| `Config` | 配置表读取 + 热更监听（`Watcher`） |
| `Gm` / `Metrics` / `Breaker` | 管理命令（含异步任务）、指标埋点、熔断 |

### 功能包（`pkg/`）

| 包 | 内容 |
|:--|:--|
| `db` | `Repo`（声明式实体）+ `Db`（事务 / 分片）+ 三种驱动（sqlite / mysql / pgsql） |
| `redis` | 连接封装（`\Redis` 的薄包装，不做 ORM） |
| `schedule` | 定时任务（cron + 延迟 + 周期） |
| `mail` | 站内信：发信 / 列表 / 已读 / 领取 / 过期清理，领取幂等 |
| `room` / `match` / `fsm` / `aoi` | 玩法层：房间、匹配、状态机、视野（`Scene::around()` 按半径查询，`viewOf()` 只用于"我能看到谁"） |
| `entity` / `snapshot` | 实体管理与状态快照 |
| `frameloop` | 固定帧循环（10Hz / 20Hz 玩法） |
| `eventlog` | 事件日志（金币结算等） |
| `script` | 脚本热更（`ext/interp`，替 Lua） |
| `http` | 极简 HTTP 路由（登录服 / Web API） |

### 工具链（`bin/tsf`）

| 命令 | 作用 |
|:--|:--|
| `tsf new <name>` | 生成工程（`.` 表示在当前目录生成） |
| `tsf build [dir]` | 编译（生成 `project.yml` 并调 `tpc`） |
| `tsf serve [dir]` | 编译并启动 |
| `tsf watch [dir]` | 监听构建输入，变更自动重编重启 |
| `tsf proto build [dir]` | `.proto` → `gen/Proto/*.php` |
| `tsf config build/check [dir]` | Excel → `gen/Config/*.php` + `build/config/*.bin` |
| `tsf lint [dir]` | 静态检查（禁 `mixed`、命名空间一致、核心不依赖 pkg） |
| `tsf doctor` | 环境自检 |

---

## 安装

> **系统要求：Linux（WSL2 / 容器 / 真机）。Windows 跑不起来，macOS 未验证。**
>
> 不是配置问题，是三层硬约束：
> - 传输层是 `new \Swoole\Server(...)`，而 Swoole 的**原生 Windows 版明确不提供
>   `Swoole\Server`**（官方支持矩阵：它依赖 Unix Socket + 多进程 fork，Windows 只有
>   单进程协程 `Swoole\Coroutine\Http\Server`）；
> - AOT 产物要链接 `libphpx.so`（Linux ELF），Windows 加载不了；
> - 实现里有 `posix_getpid()`、`/proc/self/limits`、`ulimit -n` 这些 POSIX 假设。
>
> 在 Windows 上**编辑**代码没问题（IDE 照常用），构建和运行放 WSL2 里。

> **需要 PHP 8.5 ZTS（线程安全版）。** 8.4 及以下会被 composer 拒绝；NTS 版能装上，
> 但会在**链接期**报一堆和版本无关的错误（phpx 依赖 ZTS 的线程模型）。

```bash
# 1) 框架
composer require hhr0815hhr/tsf

# 2) 工具链：typephp 编译器（约 42MB，解压 168MB）
curl -fsSL https://raw.githubusercontent.com/hhr0815hhr/TypeServer/main/scripts/install-toolchain.sh | bash
export TPC=~/.tsf/toolchain/typephp/bin/tpc.php
export TSF_PHP=php85

# 3) 自检
./vendor/bin/tsf doctor
```

### 或者用 Docker（省掉上面这一套）

环境依赖不轻（PHP 要 ZTS + embed、swoole 要开 thread、phpx 要现编），
仓库里带了 [`docker/Dockerfile`](docker/Dockerfile) 把这些全装好（313MB）：

```bash
docker build -f docker/Dockerfile -t tsf:latest .        # 约 25 分钟

docker run --rm -v "$PWD":/app -w /app tsf:latest composer require hhr0815hhr/tsf
docker run --rm -v "$PWD":/app -w /app tsf:latest tsf build .
docker run --rm -p 9001:9001 -v "$PWD":/app -w /app tsf:latest ./my-server
```

镜像**不预置框架代码**，工程靠 `-v` 挂进来（`vendor/` 也在工程里）。
编译与运行都实测过；详细用法、镜像内容与构建期的四个坑见
[`docker/README.md`](docker/README.md)。

**为什么是两步**：tsf 不是纯 PHP 框架 —— 它调用 typephp 编译器（`tpc`）把 PHP 编成 C++。
Composer 只能装 PHP 代码，装不了编译器本体和 C++ 后端（clang），所以工具链单独发。

`tsf doctor` 检查：PHP 版本 + ZTS、swoole、phpx 运行时、tpc、编译用 PHP、
php-config 版本一致性、C++ 编译器。缺什么直接给修复命令。

---

## 30 秒跑起来

```bash
tsf new my-server              # 1) 生成工程
cd my-server && tsf serve .    # 2) 编译并启动（默认 9001）
php85 client.php 9001          # 3) 另开终端验证
```

开发时 `tsf watch .`：改任何**构建输入**（`project.yml` 的 sources 里列的：`src/`、`gen/`、
包目录、框架源码）后自动重编重启，改一行到看到效果 **1.4~1.9 s**。

提交前 `tsf lint`：禁 `mixed`、命名空间与目录一致、核心不依赖可选包，违反返回非 0。

---

## 目录结构

```
tsf/
├── bin/tsf              工具链入口（PHP CLI）
├── src/                 框架核心（会被编进产物）
│   ├── Kernel/          Bootstrap / AppConfig / Logger / FileLoader
│   ├── Transport/       TCP / WebSocket
│   ├── Protocol/        分帧 + 载荷（可换）
│   ├── Gateway/         鉴权 / 限流 / 心跳 / 会话
│   ├── Service/         Scheduler / Message / Mailbox
│   ├── Rpc/ Discovery/ Shard/ Cluster/   分布式
│   ├── Proto/           protobuf 运行时
│   └── Config/ Gm/ Metrics/ Breaker/
├── pkg/                 功能包（可选，用 project.json 的 packages 启用）
├── tpl/                 脚手架模板
├── tools/               config（Excel 导出）/ proto（协议生成）
└── ext/codec/           原生帧编解码（C++，比 PHP 版快 11~14%）
```

**约定**：`src/` 编进可执行文件，`pkg/` 按需编入，`tools/` 只在开发机跑（不进产物）。

---

## 核心概念

### Service = Actor

整个并发模型只有三条规矩，都在 `Service` 基类的注释里：

1. **不共享内存。** 服务之间只能发消息，不能互相持有对象引用。
   直接调用会形成隐式依赖（A 调 B、B 回调 A 就死锁），也没法把一个服务挪到别的进程。
2. **同一服务的处理串行。** `onMessage` 永远不会与自己的另一次执行并发，
   所以服务内部的状态不需要锁——这是它最大的价值。
3. **地址里带节点号**（高 8 位），`deliver()` 对本地/远程写法一致，
   将来拆到多进程不用改业务代码。

**没有 Actor 这个第二概念**：Actor 就是轻量 Service —— `spawn` 创建、`kill` 销毁、
消息派发。少一套概念就少一半误用。

```php
final class RoomService extends Service
{
    public function onMessage(Message $m): void
    {
        if ($m->msgId === Msg::JOIN) {
            Scheduler::instance()->deliver($m->from, Msg::JOIN_OK, ["room" => $this->addr()], $m->session);
        }
    }
}

$s = Scheduler::instance();
$s->register("room", fn () => new RoomService());
$addr = $s->spawn("room");
$s->deliver($addr, Msg::JOIN, "uid=1001");
$s->tick();      // 或由网络事件循环驱动
```

### 信箱是有界的

每个信箱默认上限 **262144 条**，超限时丢弃**新**消息并计入 `overflow`：

```php
$s->setMailboxLimit(65536);   // 按业务调整
// 超限时 deliver() 返回 false
```

为什么必须有上限：消费慢或消息成环（A 发 B、B 又发 A）时，队列会一路涨到 OOM，
而进程是**被合法代码杀死的**，日志里什么都没有。

抛旧还是抛新？选择**丢新的**：积压里的旧消息通常还有业务意义，
而新的这条往往来自已经失控的上游。

### 帧循环：可变 delta

```php
$this->timerId = $this->interval(50, self::MSG_TICK);   // 每 50ms 投一帧消息
```

每帧的 delta 用**真实经过时间**，不是固定 50：

```php
$delta = $now - $this->lastTickMs;
if ($delta > self::MAX_DELTA_MS) { $delta = self::MAX_DELTA_MS; }   // 上限 200
```

- 固定 50 会漂移：进程每帧多花 2ms，游戏内时间就比真实时间慢 4%
- 不设上限，卡顿后一次把实体推出去很远，会「瞬移穿墙」。宁可丢掉一段时间

**为什么不在 `onMessage` 里写 `while(true)` 跑帧**：那样会一直占着调度器，
别的房间和网关消息都排不上。用定时器投消息，每帧之间自然回到调度器。

### 定时器

```php
// 服务内部（$this 是 Service）
$this->timeout(500, Msg::READY_CHECK);              // 0.5s 后投一条
$this->interval(50, Msg::ROOM_TICK);                // 每 50ms 投一条
$this->every("0 5 * * *", Msg::DAILY_RESET);        // 每天 5:00
$this->cancelTimer($timerId);
```

`kill` 会连带撤销该服务名下所有定时器，不用手动清理。

### 跨节点通道（Cluster）

同机多进程用 unix socket，跨机用 TCP，**API 完全一样**：

```php
// 同机（默认）：裸路径或 unix:// 都行
$c = new Cluster(1, "/tmp/node1.sock", null, "shared-secret");
$c->listen();

// 跨机：tcp://host:port
$c = new Cluster(1, "tcp://0.0.0.0:9100", null, "shared-secret");
$c->listen();

// 连对端（地址写法与监听一致）
$c->connect(2, "tcp://10.0.0.12:9100");
```

每条连接先握手，校验三件事：**对方是谁**（hello 里的节点号）、**协议是否一致**
（codec 名）、**密钥对不对**（sha256-HMAC，密钥不走线上）。任一项不过就断开并记
`hs_fail`，日志里带原因（`bad-magic` / `bad-mac` / `codec-mismatch:json!=raw` /
`node-is-self` / `dup`）——这几个原因的处理方式完全不同，只记数不记原因等于没记。

**"连上了"不等于"能用了"**：握手没完成时 `hasPeer()` 为 false、`drain()` 返回空，
消息只会排在写缓冲里。所以"连接建立"和"可投递"是两个状态，别混。

三条边界：

1. **只校验不加密**。载荷仍是明文，能监听链路的人照样能看。要求更强得上 TLS。
2. `secret` 为空时只校验结构不校验 mac —— 只适合单机调试。
3. **节点目录**：单机用 `Discovery\FileRegistry`（读本地目录），跨机用
   `pkg/registry` 的 `RedisRegistry`（`TSF\Registry`）：

```php
// composer 工程里启用 packages: ["registry"]，或 project.json 里加 "registry"
$reg = new TSF\Registry\RedisRegistry("127.0.0.1", 6379, "", 0, 3000, "tsf");
$reg->connect();
$reg->register(new NodeInfo(1, "tcp://10.0.0.11:9100", "raw", ["region" => "hz"]));
$reg->heartbeat(1);                      // 每秒一次，TTL 3s 到期即判定死亡
$alive = $reg->alive();                  // 跨机的节点目录
```

> 它**不用 php-redis 扩展**：扩展类在 AOT 产物里不可用（能编进去，运行时
> `class \Redis is undefined`）。所以包内自带一个薄 RESP 客户端，走
> `stream_socket_*`——和 Cluster 同一批原语，AOT 下已验证。
>
> 代价是**同步阻塞**：一次命令一个 RTT。跨机部署要开协程模式，否则一个慢 Redis
> 会挡住整个 worker。

### 状态交接（handoff）

`Shard\Ring` 只回答"这个 key 该归谁"，**不搬数据**。扩缩容、故障摘除、负载再平衡
真正缺的是后半步：归属变了，状态还在老节点上。`pkg/handoff` 补这一段：

```php
$h = new Handoff($nodeId, $entry, new ClusterTransport($cluster), 3000);

// 目标侧：装状态，返回 false 表示拒绝（版本冲突 / 容量满 / 校验不过）
$h->onInstall(function (string $key, string $state, int $ver): bool { /* ... */ return true; });
// 源侧：交接成功 → 可以删本地了
$h->onCommit(function (string $key, int $ver): void { /* ... */ });
// 源侧：被拒或超时 → **必须**恢复本地服务
$h->onRollback(function (string $key, int $ver, string $why): void { /* ... */ });

$h->begin($targetNode, $targetEntry, "room:1", $version, $stateBytes, nowMs());
$h->expire(nowMs());    // 超时扫描，由调用方周期性驱动（和 FrameLoop 一样）
```

三条不变量（测试就是照它们写的）：**失败必回滚**（交接失败不等于数据丢失）、
**同版本不重复安装**（重发只回 ACK）、**同 key 同时只有一次在飞**。

交接要花一个 RTT 到几秒，这期间打到这个 key 的请求必须有去处——`Gate` 负责挡住并排队：

```php
$g = new Gate();
$g->freeze("room:1", $targetNode, $targetEntry, 256);   // 先冻结，再取快照
$g->submit("room:1", $opBytes);    // 四态：pass / queued / overflow / forward

$h->onCommit(function (string $k, int $v) use ($g) {
    $q = $g->commit($k);           // 队列按原序补交给**新宿主**
});
$h->onRollback(function (string $k, int $v, string $why) use ($g) {
    $q = $g->rollback($k);         // 队列按原序在**本地**重放
});
```

> **顺序不能反：先 `freeze()` 再 `export()` 快照。** 反了就有窗口——快照之后、
> 冻结之前到达的请求既不在快照里也不在队列里，交接完成后凭空消失。
>
> 队列满返回 `overflow`，由调用方明确拒绝；不做静默丢弃，也不做"丢最老的"——
> 那等于悄悄吞掉一次操作，玩家只会觉得"点了没反应"。

> 协议号必须落在 `0..65535`：跨节点包的 msgId 只有 2 字节，超了会被静默截断
> ——发送端正常、接收端"没收到"、两端日志都干净。`Packet::encode()` 现在会直接抛错。

---

## 最容易卡住的三件事（先看这个）

这三条都是"看起来没问题、实际没生效"的类型，排查成本远高于修的成本。

### 1. 编译失败了，但看不到真正的错误

`tsf build` 默认并行编译（4 job），并行池会把 g++ 的 stderr 吞掉，只剩：

```
PHP Fatal error: Uncaught Exception: Compilation failed for: .../main.cc
```

真正的 `error: 'server' is not captured` 看不到。

**现在的行为**：检测到 g++ 阶段失败会自动用 `-j 1` 重试一次并打印完整错误
（已成功的文件命中增量缓存，只重编出错的）。手动方式：

```bash
php85 ~/.tsf/toolchain/typephp/bin/tpc.php project.yml -j 1 -O2
```

只有 **g++ 阶段**的失败才会被吞。PHP→C++ 转换阶段的失败（如
`Fatal error: The variable $a is undefined`）本来就打得很清楚。

### 2. 改了配置文件，进程里不生效

AOT 二进制里的 `include` 会命中 opcache，**不会**因为文件 mtime 变化自动失效，
即使 `opcache.validate_timestamps=On` 也一样。

症状极具误导性：文件监听正常、重载计数在涨、mtime 也变了，但读到的永远是旧值，
而且没有任何报错。重启进程就好了（冷启动时 opcache 是空的），所以容易误判成"缓存没清干净"。

**对策**：不要自己写 `include`，用 `TSF\Kernel\FileLoader`：

```php
$conf = \TSF\Kernel\FileLoader::array(__DIR__ . "/config/skill.php");   // 配置表
$hash = \TSF\Kernel\FileLoader::hash(__DIR__ . "/config/skill.php");    // 内容指纹
$fn   = \TSF\Kernel\FileLoader::closure(__DIR__ . "/hot/activity.php"); // 热更脚本
```

返回 `null` / 空串表示不可用，调用方应当**保留旧数据**而不是清空。

**判"变没变"看内容，不看 mtime**：`filemtime` 只到秒，改一个等长值（`12` → `45`）
连文件大小都不变。这个坑框架里踩过三次（服务发现判活、配置表、`Script::changed()`）。

### 3. 想改 `pkg/` 里的源码，改了却被覆盖

`vendor/hhr0815hhr/tsf` 是 composer 装的，直接改会被下一次 `composer update` 覆盖。
要接成本地仓库：

```json
{
    "repositories": [
        { "type": "path", "url": "/path/to/tsf", "options": { "symlink": true } }
    ]
}
```

```bash
composer update hhr0815hhr/tsf
ls -la vendor/hhr0815hhr/tsf    # 应该看到 -> /path/to/tsf 的软链
```

如果 `composer update` 说 "Nothing to modify"，说明 lock 里记录的还是旧来源，
需要先删掉软链再 `composer install`。

---

## 去哪里看更多

这个 README 只讲"用框架必须知道的"。更细的内容在
[TypeServer 仓库](https://github.com/hhr0815hhr/TypeServer)：

### 教程（13 篇，按顺序读）

| 篇目 | 内容 |
|:--|:--|
| [00 quickstart](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/00-quickstart.md) | 从零到跑通 |
| [01 ingress](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/01-ingress.md) | 接入层：传输 / 协议 / 鉴权 / 限流 |
| [02 service](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/02-service.md) | Service 调度模型 |
| [03 gameplay](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/03-gameplay.md) | 房间 / 帧循环 / 状态机 / AOI |
| [04 data](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/04-data.md) | db / redis / mail / schedule |
| [05 distributed](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/05-distributed.md) | RPC / 发现 / 分片 / 跨服 / 熔断 |
| [06 ops](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/06-ops.md) | 运维与工具链 |
| [07 walkthrough](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/07-walkthrough.md) | 端到端实战 |
| [08 aot-types](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/08-aot-types.md) | AOT 类型契约（本文 §AOT 的展开） |
| [09 assembly](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/09-assembly.md) | 工程装配 |
| [10 login-service](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/10-login-service.md) | 登录服 |
| [11 http-api](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/11-http-api.md) | HTTP 接口 |
| [12 hot-reload](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/guide/12-hot-reload.md) | 脚本热更 |

### 可跑的 demo

| demo | 说明 |
|:--|:--|
| [demo-echo](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/demo-echo) | 最小 echo 服务（登录 → 发消息 → 回显） |
| [battle](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/battle) | 战斗最小闭环：AOI + 帧循环 + 配置热更 + GM（1000 人压测报告见仓库根 `VERIFY-BATTLE.md`） |
| [duel](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/duel) | 1v1 对战：房间 + 帧循环 + AOI |
| [doudizhu](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/doudizhu) | 斗地主：回合制 + 状态机 |
| [idle](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/idle) | 挂机玩法：定时结算 |
| [mmo-mini](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/mmo-mini) | 小 MMO：AOI + 视野广播 |
| [sharded-world](https://github.com/hhr0815hhr/TypeServer/tree/main/demo/sharded-world) | 分片地图 + 跨 worker 转发 |

### 项目 skill（给 AI 协作者）

仓库里有 [`.workbuddy/skills/tsf-dev/`](https://github.com/hhr0815hhr/TypeServer/tree/main/.workbuddy/skills/tsf-dev)，
是把上面这些内容按「什么时候该看什么」重新组织的操作手册。用 Claude Code / WorkBuddy
这类工具做这个项目时挂上它，能省掉大量重新摸索——尤其是错误对照表和几条热更/ GM 的规矩。

| 文件 | 内容 |
|:--|:--|
| `SKILL.md` | 导航、开工检查（`tsf doctor`）、三个典型场景的处理流程 |
| `references/setup.md` | 新环境的 7 个卡点（composer → 工具链 → doctor） |
| `references/error-codes.md` | 编译 / 链接 / 运行期错误对照表（最常用） |
| `references/add-feature.md` | 加接口 / 玩法 / pkg / 定时任务的三步流程 |
| `references/perf-baseline.md` | 性能基线 + "假性能问题"清单 + 怎么测才可信 |
| `references/config-reload.md` | 配置热更：默认手动、crc32 判变更、两条路的边界 |
| `references/gm.md` | GM 通道：同步/异步怎么选、`Task` 任务表、actor 边界 |
| `references/hot-reload.md` | 脚本热更（`pkg/script` + `ext/interp`） |

### 其他

- **[热更完整研究记录](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/research/hot-reload.md)**
  —— `ext/interp` 怎么做出来的、边界在哪
- **[框架开发全过程存档](https://github.com/hhr0815hhr/TypeServer/blob/main/docs/archive/tsf-readme-full.md)**
  —— 本文精简前的完整版：各组的实测数据、测量方法、为什么否掉某些方案
  （ECS、Open Match、快照增量、RPC 重试……）
- **[性能基线](https://github.com/hhr0815hhr/TypeServer/tree/main/bench)** —— `bench/` 下每个工程都能单独跑

---

## 设计取舍：几件不做的事

这些都是**实测否掉**或**语义上不该做**的，列出来避免重复踩：

| 不做 | 为什么 |
|:--|:--|
| 引入 ECS | 实测比现有 Entity 方案慢 |
| AOI 整帧 1000 人 p99 ≤ 50 µs | 物理下限约 200 µs（遍历 + 拷贝的内存带宽） |
| 快照热增量 | 1 万实体的全量仍在预算内，增量带来的复杂度不值得 |
| RPC 自动重试 | 幂等性只有业务知道，框架替它做决定会出事 |
| 熔断后重路由 | 玩家数据在故障节点上，转过去只会把"明确失败"变成"数据是错的" |
| ORM / 容器 / 反射装配 | AOT 编不过；依赖显式传入 |
| 默认开自动热更 | 生效时机不由任何人决定，还可能读到半成品 |
| 用 `viewOf()` 做命中判定 | 九宫格固定 3×3，技能半径大于格宽时**静默漏判 92.6%**（实测），命中判定要用 `Scene::around()` |
| 用 `Swoole\Thread` 做进程内并行 | 与「服务之间不共享内存、服务内部无锁」直接冲突。并行已由 `worker_num` 多进程满足，不叠加收益 |

### 一个容易被误读的点：ZTS ≠ 要用线程

框架要求 PHP 8.5 **ZTS**，但这不意味着它用 `Swoole\Thread`——两者不是一回事。

ZTS 是 **phpx（AOT 运行时）的要求**：它用线程承载 worker，每个线程一个 PHP 请求上下文
（`base.cc` 里 `Every worker enters request_init()` + `call_once()` 串行化进程级初始化，
`runtime_request_startup()` 里用 `tsrm_is_main_thread()` 区分主线程）。NTS 没有 TSRM，
多线程同时执行 PHP 会崩，所以链接期就过不去。

而 `Swoole\Thread` 是 **PHP 层的线程 API**（`Thread\Map` / `Queue` 那些共享内存容器），
它解决的是「PHP 代码并行且共享内存」。这个能力 tsf 有意不用：

- actor 的核心价值是**服务内部状态不需要锁**，共享内存会把这个前提推翻
- 「并行」已经由 `worker_num` 的多进程给了；线程只是另一种并行方式，不会叠加收益
- 真要跨 worker 共享大表，`Swoole\Table`（共享内存）比线程合适——它不改并发模型

框架的选择是**每进程一份配置**：几十 MB，换来无锁读取和「改配置只需重启」的简单性。

**共同点**：多数"优化"是先量了才发现不需要（数字比直觉可靠），少数是语义上不该做。
**结论可能过时，方法不会** —— 硬件变了、数据量变了，重新量一遍再决定。

---

## 版本

当前 **v0.10.0**。发版记录与 changelog 见
[Releases](https://github.com/hhr0815hhr/tsf/releases)。

API 在 1.0 之前仍可能调整；`pkg/` 下的包是可选依赖，核心不引用它们。

## 附录：AOT 编码红线（违反必踩，动手前过一遍）

AOT 编译不是解释执行，**PHP 的宽松语义在这里会失效**。以下 13 条全是实测过的，
违反的后果从编译失败到"静默不干活"都有。

### 1. 不用 `mixed` 类型声明

不是性能问题（实测 `mixed` 与 `string` 无差异），是**类型安全**：

- `mixed` 等于放弃编译期检查：`$gw->send($fd, $id, $x)` 传错东西没人拦
- 消息体只可能是三种形态，写 `string|array|object` 就把约定变成了签名
- 配置值来自 JSON，就该是 `string|int|float|bool|array`

`tsf lint` 会拦，可以直接挂 CI。

### 2. 回调的形参个数必须精确匹配

```php
// 错：注册成功、返回 timerId，但一次都不触发，没有任何报错
\Swoole\Timer::tick(1000, function (): void { ... });

// 对：
\Swoole\Timer::tick(1000, function (int $timerId): void { ... });
```

根因：AOT 对闭包实参个数是**严格**的（PHP 忽略多余实参，AOT 抛 `ArgumentCountError`），
而 Swoole 固定按自己的参数个数调回调，异常被事件循环吞掉就成了"注册成功、一次不触发"。

**凡注册给框架或 Swoole 的回调，形参必须精确匹配。**

### 3. 不要直接 `switch` 对象属性

```php
switch ($m->msgId) { ... }   // 编译期报 cannot convert 'php::Variant' to 'php::Int'
```

AOT 生成 `php::Int tmp = m.attr(...)`，而属性访问返回 `php::Variant`，没有隐式转换。

```php
$msgId = $m->msgId;          // 先落成局部变量
switch ($msgId) { ... }
```

**规律**：任何要求具体类型的表达式，都不要直接喂属性访问 ——
`switch` / `intdiv` / 数学运算 / 字符串函数都可能踩。

同一规律的运行期版本：`filemtime($this->path)` 这类"内置函数直接吃属性"，编译期不报错，
跑到那一行才报 `Argument #1 ($filename) not passed`。看起来像"参数没传"，
其实是编译成了不传参的调用。

### 4. 闭包必须显式 `use`，嵌套的更要检查

PHP 闭包不会自动捕获外层变量。解释执行时漏 `use` 只是一条 `Undefined variable` 的 warning，
**AOT 直接当编译错误**：

```
main.cc:192:52: error: 'server' is not captured
```

最容易漏的是嵌套闭包——内层也要 `use`。

### 5. 闭包的引用参数不会写回

```php
$dec = function (string $buf, int &$consumed): ?array { $consumed = 24; return [...]; };
$frame = $dec($buf, $consumed);   // $consumed 仍然是 0
```

会打一条 Warning（`must be passed by reference, value given`），但程序继续跑、结果错。
日志级别一过滤就和静默没差别。

后果很隐蔽：循环里 `substr($buf, 0)` → 同一帧被反复解析上千次 →
**延迟从 0.2 ms 涨到 111 ms，但吞吐看起来还行**（重复劳动也算进度）。

**框架的做法**：需要"输出参数"的地方一律改成返回值携带。解码器返回
`[msgId, flags, body, consumed]`，而不是用引用参数回传消费长度。

### 6. 不用运行时反射做装配

AOT 需要编译期确定的类型图。所以本框架**不做容器**：依赖一律从构造函数显式传入。

### 7. 不要依赖"改 PHP 文件即生效"

`embedded-files` 的运行时 `require` 从**构建期生成的 opcode 表**加载，改磁盘文件不生效；
`eval()` 与动态 `include` 对 AOT 不可用。

**热更只有三条路**：配置数据（运行期读文件）、内嵌 PHP 脚本（`pkg/script`）、滚动重启。
配置热更必须用 `TSF\Kernel\FileLoader`（内部做 `opcache_invalidate`），
见下面「最容易卡住的三件事」第 2 条。

### 8. 全局作用域不能有可执行语句

单个文件的入口约定是「定义 `main()`，编译器自动调用」，**不要自己在末尾写 `main();`**：

```
Fatal error: All execution code must be within a function, found stray code in main.php:143
```

AOT 的全局作用域只接受声明（class / function / use / const），任何控制流、赋值、
函数调用都不行。所以「把第三方库塞进 `sources`」的第一个拦路虎往往不是语法特性，
而是这些库入口文件里那些习惯性的全局代码（`vendor/autoload.php` 顶层有 `if` 就报）。

### 9. 基准必须走 `tsf build`，手写 `project.yml` 会漏 `-O2`

这是最严重的一次测量事故：手写 `project.yml` 只写了 `sources:`，忘了 `cxx-flags: -O2`，
**所有基准都跑在 -O0 上**，数据全线偏低约一倍：

| 指标 | `-O0`（错） | `-O2`（对） |
|:--|:--:|:--:|
| 单服务吞吐 | 510k msg/s | **1,144k** |
| 单条消息成本 | 1,959 ns | **874 ns** |
| 200 实体单帧 | 176 µs | **67 µs** |
| AOI 格内移动 | 940 ns | **322 ns** |

更糟的是"是否达标"的判断也错了——吞吐本来早就过了 50 万，却花半轮去优化一件已达标的事。

### 10. `max_conn` 会被 `ulimit -n` 静默截断

Swoole 实际上限是 `min(max_conn, SwooleG.max_sockets)`，而 `max_sockets` 来自进程的 fd 软限制。
超过时只打一行 warning 就继续跑：

```
WARNING Server::set_max_connection(): max_connection is exceed the maximum value, it's reset to 10240
```

**配了 2 万，实际只能接 1 万**，服务不报错。压测脚本与生产部署都要设：

```ini
LimitNOFILE=65535          # systemd
```
```yaml
ulimits: { nofile: { soft: 65535, hard: 65535 } }   # docker-compose
```

经验值：`nofile ≥ max_conn + 连接数 × 0.1 + 128`。
框架的 `Server::ensureFdLimit()` 会在 `start()` 时读 `/proc/self/limits` 并给出实际可用连接数。

### 11. 连接数到 8,000 就 OOM，但 RSS 只有 80 MB

现象极具误导性：报 `Allowed memory size exhausted`，但 RSS 只有 80 MB，
而且 7,000 连接正常、8,100 必崩——悬崖式，不像泄漏。

根因是两件事叠加：

1. Swoole reactor 线程为每个连接预留缓冲，**计入 PHP 的 `memory_limit`**，
   但预留页不触碰 → 记账约 64 KB/连接，RSS 只有约 5 KB/连接
2. `php85` 是 **ZTS 版本**，`memory_limit` 是 **per-thread** 的。
   单 reactor 时所有连接记在一个线程账上 → `512MB ÷ 64KB = 8192`

**指纹**：崩溃点与某个配置量成整数关系时，先怀疑记账口径。

框架的 `Server::ensureMemoryLimit()` 会按 `max_conn` 自动抬高（96 KB/连接 + 256 MB 基础）。
抬高后 2 万连接在 2G 上限下跑通，RSS 峰值 144 MB——这个需求是**虚拟的**。

### 12. 静态调用的返回值不要链式下钻

```php
// 内层返回 mixed、外层要求标量时容易踩类型校验
Logger::levelFromName(Config::getString("log.level"));
```

稳妥写法是先赋给中间变量再做转换。同理，嵌套的 `array<string, mixed>` 结构尽量在边界处
转成强类型对象，不要在多处反复下钻。

### 13. 测试必须能独立于时序通过

原来的拆包测试用"读一块 ≤64KB、解出第一帧就返回"，两帧一起到达时会丢第二帧。
它对慢实现能过，换成原生版立刻失败。

**判据**：如果某个测试只在慢实现上通过，它就是在依赖时序，不是真通过。

---
