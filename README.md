# TSF — TypePHP Server Framework

> 用 TypePHP（AOT）+ PHP 构建高性能游戏服务器，取代「C++ + Lua」。
> 当前版本：**v0.6.0**（M0–M6 已交付；M7 热更未做）
> 上一版是 2026-11-12 的 v0.1.0-m0（只有骨架）。变更记录见 `../CHANGELOG.md`。

---

## 一、30 秒跑起来

```bash
# 1) 生成工程
cd demo && tsf new my-server

# 2) 编译并启动（会自动生成 project.yml 并调用 tpc）
cd my-server && tsf serve .

# 3) 另开终端验证 echo
php85 client.php 9001
# resp msgId=1002 body=hello-tsf-... 
# OK: echo matched
```

开发时用 `tsf watch .`：改任何**构建输入**（`project.yml` 的 sources 里列的，
含 `src/`、`gen/`、包目录、框架源码）后自动重编重启。改一行到看到效果 **1.4~1.9 s**（§24）。

提交前跑一遍 `tsf lint`：检查"无 `mixed` 类型声明、命名空间与目录一致、`ext/` 无框架类"，
违反则返回非 0（可挂 CI）。标准见 §5.8。

---

## 二、当前能力（M0 ~ M6）

| 能力 | 状态 | 说明 |
|:--|:--|:--|
| 依赖注入 | ✅ | **没有全局容器**：一律构造参数显式注入（见 §5.2）。曾经有个 `Container`，零调用者，已删 |
| 分层配置 | ✅ | `tsf.json` → `tsf.{env}.json` → `tsf.local.json`，点号键访问 |
| 配置运行期生效 | ✅ | 改端口/日志级别**不需要重编**，重启进程生效（配置在 `boot()` 时读入，不做文件热重载） |
| 结构化日志 | ✅ | JSON Lines，error 额外写 stderr |
| 启动序列 | ✅ | 配置 → 日志 → 建目录 → 业务 |
| TCP 服务 | ✅ | 长度前缀帧（8 字节头），自动拆包/粘包 |
| 帧异常处理 | ✅ | 非法帧头断开连接，服务继续可用 |
| **编解码双实现** | ✅ | `protocol.frame: header/native`，运行期切换，实测 +13.3%（多次测量落在 +8%~+13%） |
| **类型标准** | ✅ | 全框架无 `mixed` 类型声明，`tsf lint` 门禁 |
| **会话管理** | ✅ | fd ↔ uid 双向索引，顶号（同 uid 二次登录踢旧连接） |
| **鉴权** | ✅ | 静态 token 表 + HMAC 签名两种，`auth.require` 可关（仅压测） |
| **心跳** | ✅ | PING/PONG + 服务端空闲扫描，实测阈值 3s → 4.6s 断开 |
| **限流** | ✅ | 每连接令牌桶，**每帧 74 ns**（-O2），超出回 `TOO_FAST`（每秒最多提示一次） |
| **背压** | ✅ | 发送失败累计 N 次断开慢客户端（实测阈值 20 生效） |
| **WebSocket** | ✅ | `server.transport: ws`，payload 复用同一套二进制帧，7/7 用例通过 |
| **连接规模** | ✅ | **8,500 连接零丢失**（68,005/68,005，CPU 35.8% 单核）；2 万连接需 2G `memory_limit`（自动抬高，见 §5.11） |
| **Service 调度** | ✅ | 消息模型 + 按批让出 + 信箱上限；稳态 **116 万条/s**，p99 20 µs（见 §9） |
| **定时器** | ✅ | 时间轮 + 远端堆；10 万定时器误差 **全部 0 ms**，cron 分钟粒度 |
| **跨进程消息** | ✅ | UDS + 攒批 + 入站认领；2000 次往返 **p99 285 µs**（10 µs tick） |
| **RPC** | ✅ | 请求-响应 + 超时；跨进程往返 **p99 377 µs**（单次，见 §13）；协议可插拔（JSON/Raw/自定义）；不做自动重试 |
| **服务发现** | ✅ | 注册 + 心跳 + 变更通知；`kill -9` 后 **3016 ms** 收敛（TTL 3000，见 §14）；codec 不一致能在建连前发现 |
| **分片路由** | ✅ | 一致性哈希 + 虚拟节点；扩容迁移 **24.8%**（理论 25%）、零跨老节点搬迁；查找 **705~1672 ns**（见 §15） |
| **跨服路由** | ✅ | 按 uid 透明调用；本地 **58 µs** / 远端 **217 µs**（p50），200 个请求零失败、零节点不符（见 §16） |
| **熔断降级** | ✅ | 三态 + 单探针 + 冷却翻倍；半死节点 **250 ms** 摘除（验收 5 s），摘除后 **30.6 µs** 返回；业务错误不误熔断（见 §17） |
| **集群压测** | ✅ | 3 节点跨服混合 **16.9k req/s**、p99 **1.75 ms**；冻住一个节点后健康节点 p99 **4.19 ms**、零失败；容量拐点 ~20k req/s（见 §18） |
| **单目标限流** | ✅ | 每目标在飞上限；冻节点过渡期吞吐 **763 → 20 992 req/s（27 倍）**，基线与稳态无变化（见 §18.8） |
| **配置表** | ✅ | Excel 表头即 schema；外键/区间/主键校验失败即中断且不写产物；**末尾追加列不用重编**；1 万行加载 **63 ms**（见 §19） |
| **配置热更** | ✅ | 盯 crc 清单（不看 mtime，秒级精度会漏）；小表 **34 ms** 生效、坏数据被拒仍服务旧值；万行表 71~106 ms（见 §20） |
| **协议生成** | ✅ | proto3 子集 → 消息类 + 编解码，**字节与标准 protobuf 一致**（前端可直接用 protobuf.js）；59 项断言（见 §21） |
| **指标** | ✅ | 指数分桶直方图（误差恒定 ≤19%）；慢调用自动打点且**限流**；`inc` **81 ns** / `observe` **390 ns**（见 §22） |
| **GM 通道** | ✅ | 独立端口 + 迷你 HTTP；空 token 拒启动 / 只绑本机 / 头传 token / 爆破限流；审计由框架统一记（见 §23） |
| **慢消息治理** | ✅ | 检测 + 告警 + 可选销毁；**不能中断**（实测 `cancel` 无效），开启代价 −13.8% |
| **状态机** | 📦 包 | `pkg/fsm`：转移表 + 钩子；`fire()` **207 ns**，零依赖，可挂任意对象 |
| **Room 与成员** | 📦 包 | `pkg/room`：状态机 + 成员表（身份/席位，**零坐标**）；观战不占席位 |
| **坐标与视野同步** | 📦 包 | `pkg/entity` 的 `EntitySet`：只在位置真变时回调；200 实体 **258 ns/个/帧** |
| **AOI 九宫格** | 📦 包 | `pkg/aoi`：无状态视野；格内移动 **322 ns**、跨格 21.5 µs |
| **匹配器** | 📦 包 | `pkg/match`：排序 + 容差放宽；1000 人单轮 **2.4 ms**，配对率由池子密度决定（见 §10.7） |
| **数据访问** | 📦 包 | `pkg/db`：Attribute 映射 + 懒连接 + 分库 + 版本化迁移；按主键读 **~4 µs**，全 AOT（见 §12） |
| **房间快照** | 📦 包 | `pkg/snapshot`：带版本 JSON + 原子写；200 实体恢复 **0.4 ms**、60 房 **1.7 ms**，`kill -9` 后房间继续跑（见 §12.7） |
| **事件日志** | 📦 包 | `pkg/eventlog`：追加写 + 两档持久性；**1,000 笔 kill -9 零丢失**，追加 2.6 µs/条（§12.9）。已接进 `demo/duel` 做金币结算：**5 轮随机 kill -9，余额与日志始终一致、无重复（§25）** |
| CLI | ✅ | `new` / `build` / `serve` / `watch` / `migrate` / `migrate-status` / `list` / `add` / `remove` / `lint` / `version` |

**尚未实现**（见 `../FRAMEWORK-TASK.md`）：只有 wasm 三级热更（M7）。
其余曾列在这里的「RPC 框架、集群成员管理、配置表导出、监控指标、迁移工具、
房间快照、事件日志」都已交付，见上表对应行——这段曾经漏改，一直写到 2026-11 才修。

### 2.1 网关处理顺序

```
收帧
 └─ 1. 会话记账（touchFrame：活跃时间 + 帧计数）
 └─ 2. 限流检查（take：读-判断-写；补充由每秒 tick 批量做）
 └─ 3. 系统消息：PING → PONG ／ LOGIN → 鉴权 → 绑定 uid →（顶号踢旧）
 └─ 4. 业务消息：要求已登录 → 按 msgId 派发给处理器
```

顺序是设计出来的：**限流在鉴权之前**——未登录连接同样消耗 CPU，先挡洪泛；
但登录帧本身也受限流，否则一个连接可以暴力撞 token。

### 2.2 配置项

| 键 | 默认 | 说明 |
|:--|:--:|:--|
| `protocol.frame` | `native` | `native`（C++）/ `header`（纯 PHP） |
| `protocol.payload` | `raw` | `raw` / `json` / `msgpack` / `protobuf` / `registry` |
| `server.transport` | `tcp` | `tcp` / `ws` |
| `server.gateway` | `true` | `false` 仅用于压测对照（无会话/限流/心跳） |
| `server.heartbeat_sec` | 30 | 空闲多久断开；实际断开 = 阈值 + 1~2s（秒级扫描） |
| `server.rate_per_sec` | 50 | 每秒补充令牌；`0` 表示不限流（走快路径） |
| `server.rate_burst` | 100 | 桶容量（允许的瞬时突发） |
| `server.send_fail_limit` | 50 | 连续发送失败多少次断开该连接（背压） |
| `server.auth.require` | `true` | `false` 仅用于压测/内网 |
| `server.auth.secret` | `dev-secret-change-me` | HMAC 密钥；配合 `uid.hmac` 形式的 token |
| `server.auth.tokens.*` | — | 静态 token → uid 映射 |

> 早期版本用过 `server.codec` / `server.protocol`，已删除（不再是"兼容保留"）。
> 配置键就是上面这些，多一个都会让人猜"到底哪个生效"。

---

## 三、目录结构

```
tsf/
├── bin/tsf                          CLI（解释执行，开发期工具）
├── ext/
│   └── codec/                       原生原语：只有 3 个 c_* 函数，无协议语义
│       ├── cpp-src/codec.cc
│       └── php-src/codec.stub.php
└── src/
    ├── Kernel/                      内核：与业务无关的基础设施
    │   ├── Bootstrap.php            启动序列
    │   ├── Config.php               分层配置（扁平化）
    │   └── Logger.php               结构化日志
    ├── Protocol/                    协议：把字节流变成消息
    │   ├── Msg.php                  系统协议号
    │   ├── ProtocolStack.php         两层组合入口（fromConfig）
    │   ├── DecodedMessage.php       组合层的解码结果
    │   ├── Contract/
    │   │   ├── FrameCodec.php       interface：分帧
    │   │   ├── PayloadCodec.php     interface：消息体
    │   │   └── DecodedFrame.php     分帧结果（对象，不是混合数组）
    │   ├── Frame/
    │   │   ├── HeaderFrame.php      8 字节定长头（**唯一权威实现**）
    │   │   └── NativeHeaderFrame.php 同一格式，逐字节操作走 C++
    │   └── Payload/
    │       ├── RawPayload.php       原样透传（默认，零成本）
    │       ├── JsonPayload.php
    │       ├── MsgpackPayload.php
    │       ├── RegistryPayload.php  按 msgId 注册闭包
    │       └── ProtobufPayload.php
    ├── Transport/                   传输：只管收发字节和连接生命周期
    │   ├── Server.php               抽象基类：拆包 + 派发
    │   ├── ServerFactory.php        tcp / ws 装配（AOT 类型稳定）
    │   ├── Tcp/TcpServer.php
    │   └── Ws/WsServer.php
    ├── Gateway/                     连接层业务：会话 / 鉴权 / 限流 / 背压
    │   ├── Gateway.php
    │   ├── Session.php
    │   ├── SessionManager.php
    │   ├── TokenAuth.php
    │   └── RateLimiter.php
    ├── Service/                     服务调度：消息 / 信箱 / 调度器
    │   ├── Message.php              消息信封
    │   ├── Mailbox.php              单消费者 FIFO（含积压上限）
    │   ├── Service.php              服务基类（含定时器便捷入口）
    │   └── Scheduler.php            注册表 + 就绪队列 + tick
    ├── Timer/                       定时器：时间轮 + 远端堆
    │   ├── Timer.php                定时器条目
    │   ├── TimerHeap.php            远端小顶堆
    │   ├── Timers.php               时间轮（4096 槽）+ 补位
    │   └── Cron.php                 5 字段 cron 解析
    ├── Cluster/                     跨进程：地址 / 线格式 / 链路
    │   ├── Addr.php                 地址编码（node + id）
    │   ├── Packet.php               跨节点线格式
    │   ├── Link.php                 一条非阻塞 UDS 链路
    │   └── Cluster.php              监听 / 连接 / 攒批 / 认领
    ├── Rpc/                         请求-响应（调用端 + 方法表 + 可插拔协议）
    │   ├── Rpc.php                  两个协议号：请求 / 响应
    │   ├── Request.php              方法名 + 载荷
    │   ├── Response.php             成功与否 + 内容
    │   ├── Client.php               调用端（自己是个 Service，为了收回包）
    │   ├── Router.php               服务端方法表（任何 Service 都能挂）
    │   ├── Contract/RpcCodec.php    载荷协议接口
    │   └── Codec/                   JsonCodec（默认）/ RawCodec（不解析 body）
    ├── Discovery/                   服务发现（谁在、还活着吗、什么协议）
    │   ├── NodeInfo.php             节点身份 + codec + 最后心跳
    │   ├── LocalNode.php            本节点：自动注册 / 心跳 / 注销
    │   ├── Contract/Registry.php    注册表抽象（文件 / Redis 可换）
    │   └── FileRegistry.php         文件实现：身份与心跳分两个文件
    ├── Shard/                       分片路由（key 该落在哪个节点）
    │   ├── Ring.php                 一致性哈希环（虚拟节点 + 平行数组存储）
    │   └── Router.php               按 uid 找节点并调用（本机/远端同一条路径）
    ├── Breaker/                     熔断（"还在但不干活"的节点）
    │   └── CircuitBreaker.php       三态 + 单探针 + 冷却翻倍
    ├── Config/                      配置表（运行期）
    │   ├── Reader.php               二进制读取器：行长 + 字段清单比对
    │   └── Watcher.php              热更：盯 manifest 的 crc，变了按表重载
    ├── Proto/                       协议编解码（运行期）
    │   ├── Wire.php                 varint / zigzag / fixed / float 编码
    │   ├── Reader.php               wire format 解码游标（含 skip 未知字段）
    │   └── Message.php              生成的消息类的基类
    ├── Metrics/                     指标（计数 / 瞬时值 / 耗时分布）
    │   ├── Metrics.php              埋点入口 + 慢调用打点（限流）
    │   ├── Histogram.php            指数分桶，误差恒定 ≤19%
    │   └── Reporter.php             定期把表格打进日志
    ├── Gm/                          GM / 后台管理通道（独立端口）
    │   ├── Server.php               迷你 HTTP：只绑本机、必填 token、爆破限流
    │   ├── Registry.php             指令注册 + 派发 + 审计日志
    │   └── Result.php               强类型的执行结果
    └── （到此为止：以上是"所有游戏都需要"的核心）

tools/config/                       配置表工具链（**只在开发机跑，不进 AOT 产物**）
├── Xlsx.php                        最小 xlsx 读取（只解 sharedStrings + sheet1）
├── Field.php                       类型解析 / 校验 / 编码
├── Bin.php                         chr/ord 二进制编码（不用 pack）
└── Exporter.php                    Excel → gen/Config/*.php + build/config/*.bin

tools/proto/                        协议工具链（同上，只在开发机跑）
├── Parser.php                      proto3 子集解析（含 `// @id` 注释指令）
└── Generator.php                   → gen/Proto/*.php（消息类 + 编解码）

pkg/                                可选功能包：不启用就不参与编译
├── fsm/                             状态机（房间/连接/战斗单位/订单都能用）
│   ├── pkg.json
│   └── src/Machine.php              转移表 + 进入/退出钩子，零依赖
├── entity/                          坐标（不需要房间的玩法也能单独用）
│   ├── pkg.json
│   └── src/
│       ├── Entity.php               整数坐标 + 速度，按 delta 推进
│       └── EntitySet.php            集合：推进 + 「位置变了」回调（可接 AOI）
├── frameloop/                       帧循环（要 50ms 帧同步的玩法才用）
│   ├── pkg.json
│   └── src/FrameLoop.php            定时投帧 + 可变 delta + 单帧耗时
├── room/                            房间 = 状态机 + 成员表（**不含坐标**）
│   ├── pkg.json                     requirements: fsm, frameloop
│   └── src/
│       ├── Member.php               成员：uid + 身份 + 席位（纯数据）
│       ├── MemberTable.php          成员表：席位分配、观战不占座
│       ├── Room.php                 组合 Machine 与 MemberTable，只加帧计数
│       ├── RoomService.php          把 Room 包成 Service（autoFrame 可关）
│       ├── RoomManager.php          roomId → 承载（独占/共享），创建与回收
│       └── RoomHost.php             共享宿主：多个小房间共用一个 Service
├── aoi/                             九宫格视野（只需要"附近的人"的玩法才用）
│   ├── pkg.json
│   └── src/
│       ├── Grid.php                 格子索引（O(1) 进出）
│       └── Scene.php                进出视野事件（不存视野表）
└── match/                           匹配器（对战/棋牌用；MMO 用房间列表自选）
    ├── pkg.json
    └── src/
        ├── MatchQueue.php           排序 + 容差随时间放宽（纯算法，可单测）
        └── MatchService.php         挂到 Service 上的胶水层
└── db/                              数据访问（需要持久化才用；见 §12）
    ├── pkg.json
    └── src/
        ├── Table.php                #[Table("表名")]
        ├── Id.php                   #[Id] / #[Id(true)] 自增
        ├── Col.php                  #[Col] / #[Col("列名")]
        ├── Meta.php                 一次反射，缓存列清单与 ReflectionProperty
        ├── Db.php                   连接（懒建/重连/事务/语句复用）
        ├── Source.php               抽象：按分片键取库
        ├── Shards.php               分库：DSN 模板 + 取模路由
        ├── Migrator.php             版本化迁移（SQL 文件 + 方言变体 + checksum 校验）
        └── Repo.php                 增删改查 + hydrate
└── snapshot/                        快照（要「崩了能恢复」才用；见 §12.7）
    ├── pkg.json
    └── src/
        ├── Snapshot.php             带版本的 JSON 容器（**不认识任何业务对象**）
        ├── Codec.php                编解码 + 版本校验 + 状态哈希
        ├── Store.php                存储抽象
        ├── MemoryStore.php          内存（单测 / 进程内热备）
        ├── FileStore.php            文件（原子写，抗 kill -9）
        └── Restorer.php             批量恢复（分批记账 + 坏数据清理 + 超时标记）
└── eventlog/                        事件日志（交易/充值这类「丢了是事故」的写入）
    ├── pkg.json
    └── src/EventLog.php            追加写 + fflush/fsync 两档 + 半行修复 + 截断
```

**核心与包的分界不是"重要程度"，而是"是否所有游戏都要"**：

| 归属 | 判据 | 当前内容 |
|:--|:--|:--|
| 核心 `src/` | 任何服务端都要：进程、协议、连接、调度、跨进程 | Kernel / Protocol / Transport / Gateway / Service / Timer / Cluster |
| 包 `pkg/` | 只有部分品类要 | entity（可移动实体）、frameloop（帧循环）、room（房间）、aoi（视野）、match（匹配）、db（数据访问） |

棋牌不需要帧循环和视野，SLG 需要九宫格但不需要 50ms 帧同步，MMO 两个都要——
所以这些不能焊在框架里。**包的源码由 `tsf build` 按工程声明选择性编入**，
不启用就完全不存在于产物中（不是"编进去再关掉"）。

`db` 也放在包里，虽然"要不要存库"看起来是必需项——但纯内存的对战服、
单机模拟、压测端都不需要它，而 PDO 依赖会把二进制体积和部署约束都带进来。

**为什么拆到这么细**：每个包都要能回答"什么场景下我会单独用它"。

| 包 | 单独使用的场景 |
|:--|:--|
| `entity` | 只要"有坐标的东西"（单机模拟、视野测试），不要房间 |
| `frameloop` | 要帧循环，但房间/场景逻辑自己写 |
| `room` | 要房间状态机和成员管理，用事件驱动而不是帧驱动（`autoFrame=false`） |
| `aoi` | 要"附近的人"，但没有房间概念（比如大地图分块） |

`room` 依赖 `entity` + `frameloop`，但 `Room` 本身不碰定时器——
帧驱动在 `RoomService` 里，可以关掉。这样"只要房间"的玩法装 `room` 也不会被强行塞一个帧循环。

依赖方向**只能向下**，不允许反向或跨层回调：

```
Gateway  →  Transport  →  Protocol  →  Kernel
Service  ──────────────────────────→  Kernel
Service  →  Timer  （Timer 不依赖任何层，可独立测试）
Service  →  Cluster  （Cluster 只依赖 Protocol 的 PayloadCodec）
pkg/room →  Service  （包只能依赖核心，核心不认识任何包）
pkg/room →  pkg/fsm, pkg/frameloop （包之间靠 pkg.json 的 requires 声明）
pkg/fsm  →  （零依赖，谁都能用：房间、连接、订单、战斗单位）
pkg/entity →  （零依赖，接不接 AOI 由玩法用闭包决定）
```

**包之间只在「确实离不开」时才连边**：`room` 需要 `fsm` 才有状态、需要 `frameloop`
才能开箱即用地投帧，所以是硬依赖；而 `entity` 明明可以顺带把 AOI 同步做了，
却只用闭包回调——因为 AoI 是可选能力，硬依赖等于让所有带坐标的玩法都背上九宫格。

**可选包怎么用**：

```bash
tsf list                 # 看有哪些包、当前工程启用了哪些
tsf add aoi              # 启用（连带启用 requires 声明的依赖）
tsf remove aoi           # 移除
tsf build .              # 重新装配：project.yml 里会多出 pkg/aoi/src
```

工程根目录的 `project.json` 就是构建清单（和运行期配置 `config/tsf.json` 分开）：

```json
{ "name": "my-server", "packages": ["room", "aoi"] }
```

`tsf build` 按它把 `pkg/<name>/src` 追加进 `project.yml` 的 sources。
**没声明的包不会被编译**——不是运行时开关，是根本不进产物。

包清单 `pkg/<name>/pkg.json`：

```json
{
  "name": "aoi",
  "ns": "TSF\\Aoi",
  "desc": "九宫格视野（无状态，格子坐标的纯函数）",
  "requires": [],
  "hint": "只对需要\"视野/附近的人\"的玩法有意义；棋牌、回合制可完全不要"
}
```

`ns` 让 `tsf lint` 知道这个包该用什么根命名空间（包的目录名和命名空间大小写可以不同）。

`Service` 刻意**不依赖** `Transport`：调度器只认地址和消息，不关心消息是从网络来的、
从定时器来的还是从别的服务来的。网络层要投递给服务，是在 `onFrame` 里调
`Scheduler::deliver()`，依赖方向仍然是 Gateway → Service → Kernel。

`Addr` 放在 `Cluster` 而不是 `Service`，也是为了让依赖单向：两者都要用地址，
放 `Service` 会让 `Cluster` 反向依赖 `Service`（分层倒挂）。

四条规则（改目录前先对照一下，避免又乱回去）：

1. **一个概念只有一个实现位置。** 帧格式的字节布局、上限、合法性校验只在 `HeaderFrame` 定义；`NativeHeaderFrame` 是它的 C++ 孪生，必须字节级一致。
2. **`ext/` 只放原生原语。** 里面不出现任何 `TSF\*` 命名空间的类——扩展负责"快"，不负责"懂协议"。
3. **命名空间 = 目录路径。** `TSF\Protocol\Frame\HeaderFrame` 必须落在 `src/Protocol/Frame/HeaderFrame.php`，没有例外。
4. **新增一层要能回答"它依赖谁、谁依赖它"。** 回答不出来就说明它该拆成两层或并进现有层。

业务工程由 `tsf new` 生成：

```
my-server/
├── main.php                入口（main() 函数）
├── client.php              最小测试客户端（登录 + echo）
├── config/tsf.json         配置
├── src/                    业务代码（可选）
├── var/log/                日志
└── project.yml             由 tsf 生成，不要手改
```

---

## 四、协议：分帧 + 消息体（两层独立可换）

协议被拆成两个正交的层，**换协议不改代码，只改配置**：

| 层 | 职责 | 实现 | 配置 |
|:--|:--|:--|:--|
| **分帧** | 把 TCP 字节流切成消息 | `Frame\HeaderFrame`（PHP）/ `Frame\NativeHeaderFrame`（C++） | `protocol.frame` |
| **消息体** | body 字节 ↔ 业务结构 | `Payload\{Raw,Json,Msgpack,Protobuf,Registry}Payload` | `protocol.payload` |

```
tsf/src/Protocol/
├── Contract/
│   ├── FrameCodec.php     interface：name / encode / decode
│   └── PayloadCodec.php   interface：name / pack / unpack
├── Frame/
│   ├── HeaderFrame.php       8 字节定长头（纯 PHP，含客户端静态便捷方法）
│   └── NativeHeaderFrame.php 同一格式，走 ext/codec（C++）
├── Payload/
│   ├── RawPayload.php        原样透传（默认，零成本）
│   ├── JsonPayload.php
│   ├── MsgpackPayload.php    （需扩展，当前环境未装）
│   ├── RegistryPayload.php   按 msgId 注册闭包（protobuf / 自定义二进制都走它）
│   └── ProtobufPayload.php   同上的 protobuf 命名版
├── Msg.php                   系统协议号
└── ProtocolStack.php         组合入口：encode / decode / fromConfig
```

### 4.1 帧格式（默认）

```
| len(4B, 含头) | msgId(2B) | flags(1B) | reserved(1B) | body |
```

- `len` 是整帧长度（含 8 字节头），单帧上限 1 MB；
- 回调 `onFrame(fd, msgId, body, flags)` **只会收到完整帧**，业务不用处理半包。

### 4.2 系统消息 vs 业务消息

| 类型 | 是否走 payload 编解码 | 例子 |
|:--|:--:|:--|
| 系统消息 | ❌ 永远原样字符串 | 登录、心跳、踢下线、错误码 |
| 业务消息 | ✅ 按 `protocol.payload` 处理 | 战斗、房间、聊天 |

**为什么这么分**：登录/心跳这些握手消息必须与 payload 选择无关，否则换个 payload
就得同步改客户端握手，毫无必要。所以对外只有一个业务发送入口，系统消息走内部路径：

| 入口 | 参数 | 用途 |
|:--|:--|:--|
| `Gateway::send(fd, msgId, data)` | `string\|array\|object` | 业务消息：`raw` 时直接发字节串，其他 payload 按配置编码 |
| `Gateway::sendSystem()`（内部） | `string` | 框架自己的消息（PONG / KICKED / ERR），永不走 payload |

handler 的载荷参数按 payload 选具体类型——不是性能需要，是让类型把约定说清楚：

```php
// payload=raw：拿到字节串
$gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
    $gw->send($fd, Msg::ECHO_RESP, $body);
});

// payload=json/protobuf：拿到数组或消息对象
$gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string|array|object $data) use ($gw): void {
    $gw->send($fd, Msg::ECHO_RESP, $data);
});
```

> 早期版本把这两个入口分成 `send(string)` / `sendData(mixed)`，理由是"mixed 装箱慢"。
> 那个理由后来被证伪（见 §5.7），于是合并回一个 `send()`，用联合类型表达载荷范围。

### 4.3 各实现的选择建议

| 场景 | 建议 | 理由（实测，见 `bench/proto`） |
|:--|:--|:--|
| 高频战斗同步 | protobuf / 自定义二进制 | JSON 每帧往返多 1.9~5.7 µs（小消息~20字段） |
| 后台 / GM / 调试 | json | 可读性优先，量小 |
| 压测 / 自定义协议 | raw | 零开销（pack 169 ns，等于一次方法调用） |
| 分帧 | native | 比 PHP 版快 2.4 倍（encode 280 vs 740 ns） |

一帧完整往返（编码+解码）的微基准：

| 协议 | 小消息(39B) | 大消息(184B/20字段) |
|:--|:--:|:--:|
| raw | 2217 ns | 2217 ns |
| json | 4127 ns | 7912 ns |

> 微基准对端到端会低估（codec 那次实测低估 2.6 倍），所以上表用于判断量级；
> 真实占比要按"每帧总成本 28 µs"折算：JSON 小消息约 +7%，20 字段约 +20%。

### 4.4 接入 protobuf

`RegistryPayload` 用闭包注册，兼容 google/protobuf 生成类（它们不归我们管，加不了接口）：

```php
$p = new ProtobufPayload();
$p->register(Msg::BATTLE_CMD,
    fn ($m) => $m->serializeToString(),
    fn (string $b) => (new BattleCmd())->mergeFromString($b));
```

业务处理器拿到的就是消息对象：

```php
$gw->on(Msg::BATTLE_CMD, function (int $fd, int $uid, mixed $cmd) use ($gw): void {
    // $cmd 是 BattleCmd 实例
});
```

---

## 五、AOT 编码约束（必须遵守，都是踩过的坑）

### 5.1 不要对 `mixed` 的嵌套数组反复下标遍历

**现象**：读配置时抛 `parameter 1 must be array, got string`，但配置值确实存在。

**原因**：从配置文件解析出的嵌套数组元素类型是 `mixed`，在类方法里连续做 `$cur = $cur[$k]` 这种下钻，AOT 会在某一步插入错误的类型校验。

**对策（本框架采用的）**：配置在加载时**扁平化成 `"a.b.c" => 标量`** 的单层表，运行时只做一次 `array_key_exists`。既避开问题，也更快。

**推广到业务代码**：嵌套的 `array<string, mixed>` 结构尽量在边界处转成强类型对象，不要在多处反复下钻。

### 5.2 不要用运行时反射做装配

AOT 需要编译期确定的类型图，运行期的自动装配在这里走不通。所以本框架**不做容器**：
依赖一律从构造函数显式传入（`DuelManager` 拿 `Repo`/`Store` 就是这么来的）。

早先写过一个 `Kernel\Container`（`set`/`factory` 静态注册），但**一处调用都没有**，
`tsf new` 的模板也不用它，2026-11-15 的审计把它删了。留着比删掉更糟：
静态容器本身就是"隐式共享"，和「服务之间只发消息、不共享对象引用」的原则直接冲突。

### 5.3 不要依赖"改 PHP 文件即生效"

`embedded-files` 的运行时 `require` 是从**构建期生成的 opcode 表**加载，改磁盘文件不生效；`eval()` 与动态 `include` 对 AOT/Native Class 不可用。

**热更只能走三条路**：配置数据（运行期读文件）、wasm 模块（E1 已验证）、滚动重启。详见 `../FRAMEWORK-TASK.md` §1.4。

### 5.4 静态调用的返回值直接用，不要链式下钻

`Logger::levelFromName(Config::getString(...))` 这种嵌套本身没问题，但如果内层返回 `mixed`，外层又要求标量，容易踩类型校验。稳妥写法是先赋给中间变量，再做转换。

### 5.5 闭包的引用参数必须显式 `toRef()`（最容易静默出错）

AOT 下闭包/动态调用的参数签名在编译期不可知，**引用参数不会自动写回**：

```php
$dec = function (string $buf, int &$consumed): ?array { $consumed = 24; return [...]; };
$frame = $dec($buf, $consumed);   // $consumed 仍然是 0，不报错
```

后果：循环里 `substr($buf, 0)` → 同一帧被反复解析上千次 → **延迟从 0.2 ms 涨到 111 ms，但吞吐看起来还行**（重复劳动也算进度）。这类 bug 不报错、只变慢，极难定位。

**框架的做法**：需要"输出参数"的地方一律改成**返回值携带**。例如解码器返回 `[msgId, flags, body, consumed]`，而不是用引用参数回传消费长度。

### 5.6 测试必须能独立于时序通过

原来的拆包测试用"读一块 ≤64KB、解出第一帧就返回"，两帧一起到达时会丢第二帧。它对 PHP 版服务端能过（慢，回包分两次到），换成原生版立刻失败。

**判据**：如果某个测试只在慢实现上通过，它就是在依赖时序，不是真通过。读网络回包要按帧长度精确读。

### 5.7 更正：`mixed` 本身不慢（我一度写错了）

上一版这里写着"`mixed` 参数每帧装箱约 30 µs，把吞吐从 33k 压到 16k"。**这个结论是错的**，
留在这里当作反面教材。

错在哪：那次是**顺序采样**（先测 mixed 版，再测 string 版），而前一段正好撞上机器抖动
（同一轮的变异系数 106%）。把噪声当成了代码差异。

严格做法是编两个只差一个类型声明的二进制，交替压测取比值：

```
第1轮  mixed=37,648  string=36,917  比值=1.020
第2轮  mixed=35,105  string=35,030  比值=1.002
第3轮  mixed=34,817  string=35,985  比值=0.968
第4轮  mixed=37,513  string=36,419  比值=1.030
第5轮  mixed=34,683  string=34,698  比值=1.000

中位比值 1.002x（区间 0.968 ~ 1.030）→ mixed 与 string 无实质差异
```

微基准也一致：动态闭包调用下 `mixed` 参数 496 ns、`string` 参数 493 ns。
联合类型 `string|array` 反而略慢（524 ns）。

**方法论教训**：这跟 §5.6「只在慢实现上通过的测试」是同一类错误——
**不要拿两次先后测量的结果做比较**。要比较就交替采样，让机器漂移在比值里约掉。

### 5.8 标准：源码里不出现 `mixed`

虽然 `mixed` 不慢，本框架仍然**全面禁用 `mixed` 类型声明**。原因是类型安全，不是性能：

- `mixed` 等于放弃编译期检查：`$gw->send($fd, $id, $x)` 传错东西没人拦；
- 消息体只可能是三种形态（二进制串 / 结构化数组 / 消息对象），写成
  `string|array|object` 就把约定变成了签名；
- 服务对象就该是 `object`；配置值是 JSON 来的，就该是
  `string|int|float|bool|array`。放宽到 `mixed` 只会掩盖"这里到底放什么"。

**当前落点**：

| 位置 | 类型 |
|:--|:--|
| `PayloadCodec::pack()` / `unpack()` | `string\|array\|object` |
| `Gateway::send()` | `string\|array\|object` |
| `ProtocolStack::encode()` | `string\|array\|object` |
| `FrameCodec::decode()` | `?DecodedFrame`（对象，不是混合数组） |
| `Config` / `Logger` / `Server` 的数组注解 | `array<string, string\|int\|float\|bool\|array>` |

**这条标准是可执行的**，不是口头约定：

```bash
tsf lint     # 检查：无 mixed 类型声明 + 命名空间与目录一致 + ext/ 无 TSF 命名空间
```

违反时返回非 0，可以直接挂到 CI 上。

顺带一个实测收益：把解码结果从 `array{int,int,string,int}` 换成一个 4 属性的小对象
（`DecodedFrame`），单次解码快约 150 ns，而且 `$f[2]` 到底是 body 还是 flags 这种
问题再也不存在了（见 `bench/probe-types`）。

### 5.9 不要直接 `switch` 对象属性

**现象**：编译期报

```
RoomService.cc:134: error: cannot convert 'php::Variant' to 'php::Int' {aka 'long int'} in initialization
```

**原因**：`switch ($m->msgId)` 会让 AOT 生成 `php::Int tmp = m.attr(...)`，
而属性访问返回的是 `php::Variant`，这一句没有隐式转换就编译失败。

**对策**：先取到局部变量再 switch：

```php
$msgId = $m->msgId;   // 先落成局部变量
switch ($msgId) { ... }
```

同类的还有：属性直接参与需要具体类型的运算（`intdiv($m->msgId, 2)` 之类）。
规律是"**任何要求具体类型的表达式，都不要直接喂属性访问**"。

### 5.10 基准工程必须走 `tsf build`，手写 project.yml 会漏掉 `-O2`

**这是本项目最严重的一次测量事故**，写在这里以免重犯。

手写 `project.yml` 时我只写了 `sources:`，忘了 `cxx-flags: -O2`。结果是**所有基准都跑在 -O0 上**，
数据全线偏低约一倍：

| 指标 | -O0（错） | -O2（对） |
|:--|:--:|:--:|
| 单服务吞吐 | 510k msg/s | **1,144k msg/s** |
| 单条消息成本 | 1,959 ns | **874 ns** |
| 10 万定时器注册 | 4,300 ns/个 | **1,339 ns/个** |
| 200 实体单帧 | 176 µs | **67 µs** |
| AOI 格内移动 | 940 ns | **322 ns** |

`-O2` 对方法体内部的算术和属性访问优化非常明显，而 AOT 生成的 C++ 里这些正是主体。
更糟的是这让"是否达标"的判断也错了——吞吐本来早就过了 50 万，我却花了半轮去优化
一件已经达标的事。

**对策**：所有工程都用 `project.json` + `tsf build`（它会写全 `cxx-std` / `cxx-flags`），
不要手写 `project.yml`。`tsf build` 每次都重新生成，避免不同工程的编译参数漂移。

### 5.11 连接数到 8,000 就 OOM，但 RSS 只有 80 MB

**这是排查最久的一个问题**，现象极具误导性：

```
PHP Fatal error: Allowed memory size of 536870912 bytes exhausted
(tried to allocate 2097152 bytes)
```

看到这个都会先怀疑内存泄漏。但同一时刻进程 RSS 只有 **80 MB**，而且
7,000 连接完全正常、8,100 连接必崩——悬崖式，不像泄漏（泄漏是线性的）。

**排查顺序，每一步都排除一个假设**：

| 假设 | 实验 | 结果 |
|:--|:--|:--|
| TSF 的会话/缓冲层泄漏 | 写一个不依赖 TSF 的裸 Swoole echo 服务 | **同样崩** → 与框架无关 |
| `max_conn` 预分配 | `max_conn` 从 1k 扫到 40k，测 emalloc | 完全不变 → 排除 |
| 输出缓冲 | `buffer_output_size` 64KB → 8KB | 仍崩 → 排除 |
| 输入缓冲 | `input_buffer_size` / `buffer_input_size` → 8KB | 仍崩 → 排除 |
| 真实内存不足 | `memory_limit=-1` | 8,500 连接全建立、85,003 条收发零错误、**RSS 82 MB** → 排除 |

**真正的原因是两件事叠加**：

1. Swoole 的 reactor 线程为每个连接预留一块缓冲，**计入 PHP 的 memory_limit**，
   但预留的页不触碰 → 记账约 64 KB/连接，RSS 只有约 5 KB/连接；
2. `php85` 是 **ZTS 版本**，`zend_mm_heap` 与 `memory_limit` 都是 **per-thread** 的。
   单 reactor 时所有连接记在一个线程的账上 → `512MB ÷ 64KB = 8192`，
   扣掉基础开销后实测 7,000 过、8,100 崩，与这个算式吻合。

多 reactor 能分摊，但**连接分配不均**（实测 2 万连接时某个 worker 拿到 11,000 个），
所以单靠加 reactor 不可靠。

**指纹**：崩溃点与某个配置量成整数关系时，先怀疑记账口径。
`8192 = 512MB / 64KB` 就是这个问题的指纹。

**对策**：`Server::ensureMemoryLimit()` 在 `start()` 里按 `max_conn` 自动抬高：

```php
$need = $maxConn * 98304 + 268435456;   // 96 KB/连接 + 256 MB 基础
```

实测日志：

```json
{"lvl":"warn","msg":"memory_limit 不足，已按 max_conn 自动抬高",
 "ctx":{"from":"512M","to":"2132M","max_conn":20000}}
```

抬高之后 **2 万连接在 2G 上限下跑通：119,999 / 119,999，零错误**，RSS 峰值 144 MB。
也就是说这个需求是**虚拟的**，并不真占物理内存——但 `memory_limit` 按它记账。

### 5.12 `max_conn` 会被 `ulimit -n` 静默截断

Swoole 的实际上限是 `min(max_conn, SwooleG.max_sockets)`，而 `max_sockets` 来自进程的
**fd 软限制**。超过时只打一行 warning 就继续跑：

```
WARNING Server::set_max_connection(): max_connection is exceed the maximum value, it's reset to 10240
```

本机 `ulimit -n` 默认就是 **10240**，而配置写的是 `max_conn: 20000`——
**配了 2 万，实际只能接 1 万**，且服务不报错，只在 stderr 留一行警告。

压测脚本因此显式设了 `ulimit -n 65535`。生产部署同样必须：

```ini
# systemd
LimitNOFILE=65535
```

```yaml
# docker-compose
ulimits:
  nofile:
    soft: 65535
    hard: 65535
```

经验值：`nofile ≥ max_conn + 连接数 × 0.1 + 128`（留给监听、日志、上游连接）。

**框架已内置检查**：`Server::ensureFdLimit()` 在 `start()` 里读 `/proc/self/limits`，
不足时输出（不阻断启动——PHP 没有 setrlimit，无法自愈）：

```json
{"lvl":"warn","msg":"fd 上限不足，实际连接数会低于 max_conn",
 "ctx":{"soft_limit":10000,"max_conn":20000,"effective":9872,
        "fix":"ulimit -n 20128；systemd: LimitNOFILE=20128；docker: ulimits.nofile.soft=20128"}}
```

`effective` 直接给出「实际能接多少」，不用自己算。`bench/fd-test` 覆盖解析逻辑，
并与 shell 的 `ulimit -n` 做交叉验证（10 项断言）。

### 5.13 全局作用域不能有可执行语句

单个文件的入口约定是「定义 `main()`，编译器自动调用」，**不要自己在文件末尾写
`main();`**：

```
Fatal error: All execution code must be within a function, found stray code in main.php:143
```

同样的规则也适用于 `sources` 里的每一个文件——这直接决定了 `vendor` 能不能整体编进
AOT：`vendor/autoload.php` 顶层有 `if` 判断，一编译就报

```
Fatal error: Unsupported statement: Stmt_If in vendor/autoload.php:5
```

AOT 的全局作用域只接受声明（class / function / use / const），任何控制流、赋值、
函数调用都不行。所以「把第三方库塞进 `sources`」这件事，第一个拦路虎往往不是语法
特性，而是这些库的入口文件里那些习惯性的全局代码。

---

## 六、M0 验证记录（2026-11-12）

| 项 | 结果 |
|:--|:--|
| `tsf new` 生成工程 | ✅ config / main.php / client.php / project.yml |
| `tsf build` | ✅ 12 个文件编译，产物 **402 KB**，约 40 秒 |
| 启动日志 | ✅ boot → app ready → server listen（JSON 结构化） |
| echo 往返 | ✅ `resp msgId=1002` + `OK: echo matched` |
| 分段发送（帧切两半） | ✅ 正确重组 |
| 粘包（两帧一次写） | ✅ 拆成两条消息 |
| 非法帧头 | ✅ 断开连接，进程不崩 |
| 异常后仍可用 | ✅ 新连接正常 echo |
| 配置运行期生效 | ✅ 改端口 9001 → 9010，**不重编**直接重启生效 |

---

## 七、M1 验证记录（2026-11-12）

功能测试 `demo/m1-test.php`，共 21 项断言：

| 组 | 覆盖 | 结果 |
|:--|:--|:--:|
| 鉴权 | 未登录拒绝、错误 token、正确 token、uid 绑定、业务放行、PING/PONG | 6/6 |
| 顶号 | 同 token 二次登录 → 旧连接收 KICKED 并断开，新连接可用 | 5/5 |
| 心跳 | 空闲连接被断开（阈值 3s → 实测 4.6s）、保活连接存活（收到 35 次 PONG） | 5/5 |
| 限流 | 突发 200 帧：放行 99、其余丢弃，提示不刷屏 | 5/5 |
| WebSocket | 握手、未登录拒绝、登录、回显、心跳、一消息多帧拆分、非法帧断开 | 7/7 |
| 背压 | 客户端只发不读，服务端堆满缓冲后断开（阈值 20 生效） | ✅ |

**WebSocket 的定位**：只当传输层，payload 仍是 TSF 的 8 字节头 + body。这样 Gateway、业务处理器、压测端全部复用，
协议不用写两份；浏览器端用 `ArrayBuffer` 收发即可。若将来要支持"浏览器直发 JSON"，应加一个可选的 JSON
编解码层，而不是在传输层做分支——否则会分裂出两套派发逻辑。

性能（`bench/harness`，交替采样 5 轮配对比较，-O2）：

| 项 | 结果 |
|:--|:--|
| 裸转发吞吐 | 42,127~45,655 msg/s（≈4,200~4,500 连接 @10Hz） |
| 网关层 vs 裸转发 | 34,504~35,900 msg/s，**−19.0%**（离散度 2.7%） |
| 帧编解码 native vs php | **+13.9%**（离散度 1.1%） |
| 每帧记账微基准 | **256 ns**（分项：touchFrame 168、take 74），占 25 µs/帧 的 1% |
| 每秒补充令牌（10k 连接） | 1.12 ms/s → 0.11% CPU |
| 空闲扫描（10k 连接） | 1.15 ms/s → 0.12% CPU |

> ⚠️ 早期这张表写过「网关层吞吐 +0.7%，这层基本不花钱」——那是 **-O0** 且混入了
> 代码重构前后对比的数据，**结论是错的**。多次配对测量一致显示网关层要付约 19% 的吞吐
> （会话查找 + 令牌桶 + 心跳记账 + 鉴权）。这不是 bug，是这层功能本身的成本；
> 需要更高的单机承载时，应该考虑把鉴权/限流前移到独立的接入层，而不是假装它免费。

### 7.1 AOT 下的"小操作"到底多贵

这轮测出一组基础成本，直接影响了热路径的写法：

| 操作 | 成本 |
|:--|:--:|
| `isset($arr[$k])` | 205 ns |
| 数组读 `$arr[$k]` | 153 ns |
| 数组写 `$arr[$k] = v` | 174 ns |
| `time()` | 129 ns |
| `microtime(true)` | 233 ns |
| 对象属性读/写 | ~150 ns |

**含义**：一帧里做 10 次数组查找就是 1.5 µs。所以热路径的设计目标不是"少写几行"，
而是**少做几次哈希查找**。第一版限流每次调用现算补充（2 读 2 写 + microtime），
单次 949 ns；改成"每秒批量补充 + 每帧只递减"后降到 **74 ns**（-O2；这些早期数字是 -O0 测的，
但结论方向不变：把补充搬到后台、每帧只做递减）。

> 上表的绝对值也是 -O0 时代测的，-O2 下普遍快 30~60%（属性读 93 ns、属性写 104 ns、time() 69 ns）。

### 7.2 这轮踩的三个坑

1. **闭包参数个数必须与实际调用一致**。Swoole 的 `workerStart` 会传 workerId、
   `Timer::tick` 会传 timerId，闭包声明成 0 参会直接抛 `ArgumentCountError`，
   进程起不来。踩了两次。
2. **`fread` 返回空串 ≠ 连接断开**。PHP 读超时也返回空串，于是"1 秒没数据"被误判成
   "连接已断"，导致以为心跳 1.2s 就断开。正确做法是非阻塞读 + `feof()`。
3. **限流会让压测结果失真**。加了 50/s 限流后，压测吞吐从 35k 掉到 808 msg/s——
   不是性能退化，是所有请求都被限流丢了。压测场景必须显式 `rate_per_sec=0`。

### 7.3 两个工程上的注意事项

1. **`tsf build` 不会重新生成 `main.php`**。模板（`tsf new` 里那段）改了之后，
   已有工程必须重新 `tsf new` 或手工同步 main.php，否则新加的装配代码不会生效。
   这个坑在加背压时踩到了：配置写了 `send_fail_limit=20`，实际仍走默认 50。
2. **AOT 不允许把子类赋给已推断为另一子类的变量**。`$server = new TcpServer(...)` 之后
   再在另一分支赋 `new WsServer(...)` 会报 `Cannot re-assign typed object`。
   解法是工厂方法带统一返回类型——现在是 `Transport\ServerFactory::make()`（框架内），
   不再是模板里的 `make_server()`。

---

## 八、结构重构记录（2026-11-12）

M1 收口后发现两个结构问题，这轮专门清掉。

### 8.1 三份分帧实现，其中两份是纯重复

| 位置 | 形态 | 处置 |
|:--|:--|:--|
| `Network\Codec` | 静态方法 | 与 `Protocol\HeaderFrame` **同一套逻辑**，删除 |
| `ext/codec/php-src/NativeCodec` | 返回闭包 | 与 `Protocol\NativeFrame` **逐字节相同**，且已无人引用，删除 |
| `Protocol\{HeaderFrame,NativeFrame}` | 接口实现 | 保留，`NativeFrame` 改名 `NativeHeaderFrame` |

顺带发现 `Server` 里有一条**双路径**：注入了 decoder 用闭包，没注入就 fallback 到 `Codec::tryDecode`。
两条路径意味着"测试跑的是 A、线上跑的是 B"，这是最危险的一类重复。

**根因是闭包注入**。当初这么写是为了"没编译扩展的工程也能过编译"——但探测发现
**AOT 不会因为类里引用了未定义函数而编译失败**（只有真正调用时才炸），
所以这层间接从来就不必要。现在 `Transport\Server` 直接持有 `FrameCodec`：

```php
public function __construct(string $host, int $port, FrameCodec $frame, array $options = [])
```

删掉的东西：`options["encoder"/"decoder"]`、`ProtocolStack::encoder()/decoder()`、
`ServerBase` 这个类名（改叫 `Server`）、以及那条 fallback 分支。

### 8.2 目录按"层"重排，而不是按"技术名词"

原先是 `Framework` / `Network` / `Protocol` 三分，其中 `Network` 一个目录塞了 4 类东西
（传输、分帧、会话、网关），`Framework` 成了"剩下的都放这"。M2 加 Service/Actor 只会更乱。

现在是 `Kernel` / `Protocol` / `Transport` / `Gateway` 四层，依赖单向向下（见 §3）。
`ext/` 里不再有 `TSF\*` 命名空间的类——扩展只提供 `c_*` 原语，适配类在 `src/Protocol/Frame/`。

### 8.3 重构后的实测

| 项 | 结果 |
|:--|:--|
| 功能回归 | TCP 21/21、WS 7/7、协议 raw 4/4 + json 5/5 |
| 网关层成本（配对比较 5 轮） | **−8% ~ −18%**（随机器状态浮动，绝对值无退化：bare 从 32k 升到 39k） |
| codec 收益（配对比较 5 轮） | **+13.3%**（离散度 6.2%；另一次测量 +7.8%） |
| 接口分派 vs 闭包调用 | 262 ns vs 356 ns，**接口更快** |
| `mixed` vs `string` 参数 | 1.002x，**无差异**（见 §5.7，旧结论已作废） |
| 解码结果：对象 vs 4 元素数组 | 对象快约 150 ns/次 |

说明两点，避免读者拿错数：

1. codec 的比值在 +8%~+13% 之间浮动，不是精确值。单次测量不足以定论，
   要看同一轮内的配对比值和离散度（`bench/harness` 会自动给）。
2. 网关层那个比值是"固定 PHP 开销 / syscall 成本"，机器越快这个比值越大，
   所以它天生比 codec 那个数更不稳。要判断有没有退化，看 **bare 的绝对吞吐**，
   而不是看这个比值。

---

## 九、M2：Service 调度（单进程）

### 9.1 模型

```
业务代码 ──deliver──> 目标信箱 ──tick──> Service::onMessage()
```

四条设计决定：

1. **进程内也用消息，不共享内存。** 服务之间只能发消息，不能互相持有对象引用。
   直接调用会形成隐式依赖图（A 调 B、B 回调 A 就死锁），也没法把一个服务挪到别的进程。
2. **同一服务的处理串行。** `onMessage` 永远不会与自己的另一次执行并发，
   所以服务内部状态不需要锁——这是它最大的价值。
3. **地址里带节点号**（高 8 位），`deliver()` 对本地/远程写法一致，
   将来拆到多进程不用改业务代码。
4. **没有 Actor 这个第二概念。** Actor 就是轻量 Service：`spawn` 创建、`kill` 销毁、
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

### 9.2 调度：按批 + 让出

一轮里每个服务最多处理 `budget`（默认 200）条，然后排到就绪队列**队尾**，
让别的服务先跑。这是「吞吐 vs 延迟公平」的旋钮：

- budget 大 → 单服务吞吐高，但其他服务要等更久；
- budget 小 → 更公平，调度开销占比上升。

实测（10 个服务里 1 个积压 1000 条、另一个只有 1 条）：idle 的那条在第 **208** 条时被处理，
没有等 busy 排空——说明让出机制有效。

`tick($maxMsgs)` 的 `$maxMsgs` 限制的是**本轮总量**（可能按 budget 粒度超出），
用来在网络事件循环里限制单次回调的耗时。

### 9.3 实测（`bench/service`，5 轮取中位）

| 场景 | 吞吐 | 单条 | 结论 |
|:--|:--:|:--:|:--|
| 浅队列（每批 64 条） | **1,161,289 msg/s** | 861 ns | 最优 |
| 浅队列（每批 256 条） | 1,143,942 msg/s | 874 ns | 持平 |
| 深队列（10 万条一次性投完） | 1,013,185 msg/s | 987 ns | 突发承受场景 |

| 指标 | 实测 | 目标 |
|:--|:--:|:--:|
| 消息延迟 p99（浅队列稳态） | **19.8~21.1 µs** | ≤100 µs ✅ |
| 消息延迟 p50 | 5.9 µs | — |
| 1 万服务 spawn | 0.7 µs/个 | — |
| 1 万服务各派发一条 | **1.04 µs/服务** | ≤20 µs ✅ |

**吞吐为什么按批测**：调度器设计上就是「每个事件循环迭代 drain 一次」，
一批越小，`tick` 的固定开销（就绪队列、循环进出）摊得越薄。深队列 1.01M 是突发场景，
不是稳态——真实服务端不会一次积压 10 万条再处理。

> ⚠️ 这批数字是 **-O2** 下的。早期版本因为手写的 project.yml 漏了 `cxx-flags: -O2`，
> 报的是 510k（-O0），差了整整一倍——详见 §5.10。

**为什么到 870 ns 就下不去了**：一条消息要过 `deliver` → `push` → `pop` → `onMessage`
四次方法调用，AOT 下每次约 150~240 ns，光调用开销就接近 0.8 µs。
再往下压只能内联信箱操作（把 `Mailbox` 拆掉、让 Scheduler 直接操作数组），
能省约 15%，但会把数据结构封装换成裸数组下标——当前不值得。

信箱用「数组 + 头指针」而不是 `SplQueue`，也是量出来的（`bench/probe-queue`）：

| 实现 | 单条成本 | 备注 |
|:--|:--:|:--|
| 数组 + 头指针 | **54 ns** | 采用 |
| `SplQueue` | 178 ns | 链式节点分配 |
| `array_shift` | 12,299 ns @1万；351,666 ns @10万 | O(n) 搬移，绝对不能用 |

### 9.4 一个被测试逼出来的设计缺口：信箱没有上限

写"自投递不得重入"的用例时，服务给自己转发消息形成了回环，**几秒内把内存吃满 OOM**，
而且日志里什么都没有——进程是被合法代码杀死的。

现在每个信箱有积压上限（默认 262144 条），超限时丢弃**新**消息并计入 `overflow`：

```php
$s->setMailboxLimit(65536);   // 按业务调整
// 超限时 deliver() 返回 false，stats()["overflow"] 递增
```

取舍：一次整数比较约 30 ns（占单条预算 1.5%），换掉一个"能杀死进程"的隐患，值得。
抛旧留新还是抛新留旧？选择丢**新**的：积压里的旧消息通常还有业务意义，
而新的这条往往来自已经失控的上游。

### 9.5 与网络层的接法

跑在 Swoole 服务器里时，**不要**开 `run()`（会和事件循环抢控制权）。
正确接法是网络回调处理完一批帧之后 drain 一次：

```php
$server->onFrame(function ($fd, $msgId, $body, $flags) use ($sched): void {
    // ... 把帧投递给对应服务 ...
    $sched->tick(2000);   // 限制单次回调耗时的上限
});
```

`run()` 只用于"纯服务进程"（没有网络事件驱动），内部按 2000 条一轮 + 空转 200 µs 休眠。

### 9.6 定时器：时间轮 + 到期投消息

```php
// 服务内部（$this 是 Service）
$this->timeout(500, Msg::READY_CHECK);              // 0.5s 后投一条
$this->interval(50, Msg::ROOM_TICK);                // 每 50ms 投一条（50ms 帧循环）
$this->every("0 5 * * *", Msg::DAILY_RESET);        // 每天 5:00
$this->cancelTimer($timerId);
```

**定时器到期只投消息，绝不直接调回调。** 直接调就等于在服务处理消息的间隙里插进来一段代码，
绕过了"同一服务串行执行"的保证——那样服务内部状态又需要锁了，整个模型就白设计了。
所以定时器触发走的是和普通消息完全相同的路径，在 `onMessage` 里统一处理。

**结构选择是量出来的。** 最初用二叉最小堆，10 万定时器（-O2）实测：

| 结构 | 入槽/插入 | 出槽/弹出 | 10 万次排空 |
|:--|:--:|:--:|:--:|
| 二叉最小堆 | 865 ns | **8,665 ns** | 约 0.87 s ❌ |
| 时间轮（4096 槽 × 1ms） | 879 ns | **253 ns** | 约 25 ms ✅ |

堆的问题在于每次下沉要比较 ~17 层，每层约 10 次数组/属性访问；
10 万次弹出累计 0.87 秒，远超 20 ms 的精度要求——**这个结构在 AOT 里不适合 10 万量级**，
跟算法教科书上的复杂度结论不冲突，但常数项差了 30 倍（8,665 / 253）。

现在：近端用 4096 个毫秒槽的时间轮（覆盖 4.096 s，入槽出槽都是常数），
更远的（cron、每日重置）放远端小顶堆，进入窗口再补位——远端数量通常只有几十个，堆完全够用。

**实测（`bench/timer`，10 万个定时器铺在 2 秒内）**：

| 指标 | 实测 | 目标 |
|:--|:--:|:--:|
| 注册 | 133.9 ms（1.34 µs/个） | — |
| 误差 p50 / p99 / p999 / max | **全部 0 ms** | ≤20 ms ✅ |
| 全部触发 | 100,000 / 100,000 | — |

精度由**驱动方的 tick 频率**决定：时间轮不会主动唤醒你，`run()` 里空转时睡 200 µs，
所以稳态误差在亚毫秒级。要更准就把 tick 调密，代价是空转 CPU。

**cron 是分钟粒度的**，支持 `*`、`n`、`a-b`、`a-b/s`、`*/s`、逗号列表；日和星期同时限定时按标准
cron 取「或」。不支持秒级和 `L/W/#` 扩展——游戏服里 99% 的需求是"每天 5:00 重置"和
"每周一开活动"，多出来的语法只会带来没测过的边角。时区用进程本地时区，不处理夏令时。

**定时器怎么测**：`due()` 接受显式时间戳，所以能用**虚拟时钟**把时钟推快，
不用真等 100 秒就能验证"远端补位""大跳变""周期重排"。这比 `sleep` 测试可靠得多——
`bench/timer-test` 里 38 项断言中有一半跑在虚拟时钟上，执行不到 1 秒。

### 9.7 跨进程消息（UDS）

**同一行代码，两种部署**：

```php
// 目标在本机，就进本地信箱；在别的节点，就走 UDS
Scheduler::instance()->deliver($addr, Msg::HIT, $damage);
```

地址里带节点号（§9.1），所以调用方不需要知道对方在哪。当前实现是**全互联**
（每个节点直连每个对端），节点数在个位数以内够用；上百节点要换成星型 + 路由，那是 M5 的事。

```
src/Cluster/
├── Addr.php     地址编解码（node 8b + id 24b）
├── Packet.php   线格式：len(4) dst(4) src(4) session(4) msgId(2) payload
├── Link.php     一条非阻塞 UDS 链路（读写缓冲）
└── Cluster.php  监听 / 连接 / 攒批发送 / 收包认领
```

**三个设计决定**：

1. **谁连谁不重要。** 入站连接在收到第一个包后才"认领"成某个节点的链路（包里的 `src`
   带节点号）。所以只有一端主动 `connect` 也能双向通信——对方回包走的就是这条连接，
   不需要反向再连一次。
2. **攒批写。** `send()` 只入缓冲，`flush()` 在每个 tick 末尾统一写一次。一轮发 100 条
   只付一次 syscall。代价是最多一个 tick 的延迟。
3. **载荷走 PayloadCodec**，默认 `RawPayload`（只接受字符串）。要跨进程传结构化数据，
   两端必须配同一个非 raw 的 codec。

**实测（`bench/cluster-test`，两个进程，2000 次串行往返）**：

| 驱动 tick 间隔 | p50 | p99 | 结论 |
|:--|:--:|:--:|:--|
| 10 µs | 137 µs | **285 µs** | 达标（≤500 µs） |
| 50 µs（实际睡到 ~100 µs） | 210~250 µs | 507~1375 µs | 受驱动间隔和机器负载影响 |

**延迟 ≈ 2 ×（tick 间隔 + 处理耗时）**，因为一次往返要经过两端各自的 poll。
所以"跨进程 p99 ≤500 µs"这个指标真正约束的是**驱动方的 tick 间隔**，不是框架本身——
把 tick 缩到 10 µs 后 p99 稳定在 285 µs 左右，框架自身贡献只有几微秒。
生产环境由网络事件循环驱动（不是固定 sleep），间隔比这还小。

**踩的坑**：判断"本地还是远程"不能写成 `node != 0`。本机节点号是 1 时，
发给自己的地址也带节点位 1，会被当成远程包又发回集群——现象是"包收到了、
日志显示信箱存在，但服务一条都没处理"。改成和本机节点号比对。

但后来又发现反过来的问题：`Service::bindAddr()` 注入的是**裸 id**（节点位为 0），
所以一接上集群（`localNode` 变成 1），**所有本地投递都被判成跨节点**。
最终的正确判据是「节点位为 0 **或** 等于本机节点号 → 本地」，详见 §16.2。

**已知限制**（M5 再补）：断开后不自动重连，`send()` 直接返回 false；没有心跳保活；
没有跨节点广播。单机部署可以完全不用这套东西——`setCluster()` 不调用时，
远程地址一律投递失败（返回 false，不静默丢）。

### 9.8 慢消息治理：只能检测，不能中断

先说结论：**在当前的 AOT + Swoole 组合下，无法掐断一个正在跑的 CPU 循环。**

探测实验（`bench/probe-preempt`）：

| 项 | 结果 |
|:--|:--|
| `Coroutine\run` / `create` / `cancel` / `isCanceled` | 都存在 |
| `set(enable_preemptive_scheduler)` | 接受 |
| A 忙等 106 ms 期间 B 是否被调度 | **是，7 次**（约 10 ms 一片）→ 抢占有效 |
| `Coroutine::cancel(忙碌协程)` | 返回 **false**，忙等照常跑完 |
| `Coroutine::isCanceled` | false |

也就是说：**抢占只保证别的协程能跑，不能杀当前协程**。`cancel()` 对纯 CPU 循环无效，
因为它只能在让出点生效。

顺带排除了另一条路：把 `onMessage` 放进协程里跑，用看门狗协程在 50 ms 时"中断"。
这样做的代价是处理函数一旦在内部 `yield`（比如等待 I/O），其他消息就能插进来，
**服务的串行保证直接失效**——这是整个模型的地基，不能为了一个监控功能拆掉。

所以实现成**事后检测 + 可选惩罚**：

```php
$s->setWatchdog(50, "log");    // 超过 50ms 记一条 warn
$s->setWatchdog(50, "kill");   // 或者直接销毁这个服务
```

| 行为 | 说明 |
|:--|:--|
| 记录 | `stats()["slow"]` 计数，`stats()["max_msg_us"]` 记最慢一条 |
| 告警 | `Logger::warn("slow message", [svc, msgId, us, limit_ms])` |
| `log` | 服务保留（默认），只是留证据 |
| `kill` | 销毁服务，后续消息不再处理，`deliver()` 返回 false |

**代价**（`bench/guard-test` 实测）：开启后 +259 ns/条，吞吐 −11.5%。所以默认**关闭**，
只在排障/压测时打开；关闭时代价是一次整数比较。

**它的定位**：不是"保护 worker"的手段，而是**定位工具**——线上出现卡顿时能立刻知道
是哪个服务的哪条消息慢了多久。真正的保护要靠 M3 的帧预算（房间 tick 有固定时间片）
和"不要把长任务写成一个大循环"的编码规范。

---

## 十、M3 玩法层：Room 与帧循环

### 10.1 一个房间 = 一个 Service

房间状态只由自己的消息驱动，天然串行、不需要锁——这是 M2 消息模型最直接的红利。

```
pkg/room/src/Room.php            状态机 + 成员表（零坐标、零依赖，可单测）
pkg/room/src/RoomService.php     把 Room 包成 Service：帧驱动 + 消息分发
pkg/room/src/RoomManager.php     roomId ↔ 服务地址，创建/回收
pkg/room/src/RoomHost.php        共享宿主：多个小房间共用一个 Service

pkg/entity/src/Entity.php        实体（整数坐标 + 速度，按 delta 推进）
pkg/entity/src/EntitySet.php     实体集合：推进 + 「位置变了」通知（可选接 AOI）
pkg/frameloop/src/FrameLoop.php  帧循环（定时投帧 + 可变 delta + 单帧耗时）
```

三层各自独立可测、可替换：

- `Room` 只管状态和**谁在里面**，不碰定时器，**也不知道坐标这回事**；
- `EntitySet` 只管坐标怎么动、动完通知谁；
- `FrameLoop` 只管什么时候该跑一帧、跑了多久。

`RoomService` 是把 `Room` + `FrameLoop` 粘起来的开箱即用版本。`Room` 刻意不依赖
`Service`/`Scheduler`：状态机是最容易写错的部分，单独可测比绑在服务里好得多。

### 10.1.1 坐标为什么不在房间里

第一版把 `Entity`（带 x/y/vx/vy）直接放在 `Room` 里，`room/pkg.json` 也就
`requires: ["entity"]`。看起来省事，接棋牌时才发现问题：

**斗地主、麻将、回合制根本没有坐标，却被迫持有一个带坐标的对象。**

判据还是那条：状态机和成员表是「任何玩法都有」，坐标不是。于是拆开：

```php
// 棋牌：只要房间
$room = new Room(1, 4);
$room->add($uid);

// MMO/MOBA：房间 + 坐标（可选加视野）
$room = new Room(1, 50);
$room->add($uid);
$ents = new EntitySet(function (int $uid, int $x, int $y) use ($scene): void {
    $scene->place($uid, $x, $y);   // 不存在就插、存在就挪格
});
$ents->spawn($uid, 2000, 5000);
```

拆完之后 `room` 的依赖只剩 `frameloop`（`RoomService` 用），`entity` 和 `aoi`
变成纯可选。拆的代价很直接：`demo/duel` 立刻编译报 `class EntitySet is undefined`——
它用坐标却一直靠 `room` 把 `entity` 带进来，现在得自己声明。**这正是拆对了的证据**：
依赖从此显式。

### 10.2 状态机：独立成包，不属于房间

```
WAITING ──start──> PLAYING ──finish──> SETTLED
   │                  │                   │
   └────────────── close ────────────────┘
                      ↓
                    CLOSED
```

**状态机是 `pkg/fsm` 的 `Machine`，房间只是它的一个使用者。**

这个判断来自一句话：**状态机可以绑定到任意实体上**。会用到它的至少还有：

| 载体 | 状态机管什么 |
|:--|:--|
| 玩家连接 | 登录中 → 在线 → 断线重连中 → 离线 |
| 战斗单位 | 待机 → 追击 → 攻击 → 死亡 |
| 匹配票 | 排队 → 匹配中 → 已分配 |
| 订单/交易 | 待支付 → 已支付 → 发货 → 退款 |

如果状态机是 `Room` 的内部字段，上面每一个都要重写一遍转移逻辑。

```php
$fsm = new Machine(Room::WAITING);
$fsm->allow(Room::WAITING, "start", Room::PLAYING)
    ->allow(Room::PLAYING, "finish", Room::SETTLED)
    ->allow(Room::WAITING, "close", Room::CLOSED)
    ->allow(Room::PLAYING, "close", Room::CLOSED)
    ->allow(Room::SETTLED, "close", Room::CLOSED);
```

转移规则写成**表**而不是散落在方法里：加一个状态时改一处，而不是在一堆 `if` 里找。

实测：`fire()` **207 ns**，被拒的非法转移 61 ns，`can()` 60 ns，建一台 3 条边的状态机 **806 ns**
（1000 个房间 0.8 ms，可忽略）。`bench/fsm-test` 27 项。

非法转移**返回 false 而不是抛异常**：房间是高频对象，客户端乱序包会常态性触发，
抛异常等于让一条乱序包打死一个服务。

状态用 `int` 常量而不是 `enum`：AOT 对 enum 的支持面没验证过，`const` 在所有模式下都稳。

### 10.2.1 成员：身份是数据，不是继承层次

```php
final class Member
{
    public int $uid;
    public int $role;    // PLAYER / NPC / SPECTATOR
    public int $seat;    // -1 = 不占席位
    public string $name;
}
```

直觉写法是 `class Player extends Member`、`class Npc extends Member`。实测（`bench/probe-actor`，
1000 个成员遍历一次）：

| 写法 | 成本 |
|:--|--:|
| 抽象基类 + 虚方法派发 | 181 ns/个 |
| 抽象基类 + `instanceof` 取子类字段 | 221 ns/个 |
| **单类 + `role` 字段** | **112 ns/个** |
| 造一个子类实例 | 273 ns |
| 造一个单类实例 | **46 ns** |

多态在这个运行时里能用（虚派发、`instanceof`、反射父类、跳过构造函数全部验证通过），
但每一项都贵 1.6~6 倍。更关键的是**维度会爆炸**：玩家/NPC × 占座/观战 ×
有状态机/无状态机，四个维度十几个子类，其中大部分组合根本不存在。

而房间里对成员做的事其实只有「判权」：谁能操作、谁占席位、谁只是看着。
这些是**读一个字段**就能答的问题，不需要虚派发。真正需要不同行为的是 NPC 的 AI、
玩家的输入处理——那些是 Agent/System 的事，不该塞进成员表。

**身份是数据，行为在别处。** 需要给某类身份挂额外数据时用旁表
（`array<int, YourData>` 按 uid 索引），别往 `Member` 上加字段——否则所有身份
都背上不属于自己的字段。

**容量只约束占席位的人**：观战不占席位、人数不限。「一桌 4 人 + 200 个观战」是棋牌的
常态，把观战算进容量要么开不了观战、要么把容量配得虚高。

**席位号一局内稳定**：客户端按席位号画座位，中途换号会让人物跳位。
所以分配最小空位、离开后空出来给别人，而不是"按加入顺序重新编号"。

### 10.3 帧循环：可变 delta

```php
$this->lastTickMs = Scheduler::nowMs();
$this->timerId = $this->interval(50, self::MSG_TICK);   // 每 50ms 投一帧消息
```

每帧的 delta 用**真实经过时间**，不是固定 50：

```php
$delta = $now - $this->lastTickMs;   // 卡了 150ms 就是 150
if ($delta > self::MAX_DELTA_MS) { $delta = self::MAX_DELTA_MS; }   // 上限 200
```

- 用固定 50 会有漂移：进程每帧多花 2ms，游戏内时间就比真实时间慢 4%；
- 不设上限，卡顿后一次把实体推出去很远，会「瞬移穿墙」。宁可丢掉一段时间。

**为什么不在 onMessage 里写 `while(true)` 跑帧**：那样会一直占着调度器，别的房间和
网关消息都排不上。用定时器投消息，每帧之间自然回到调度器，公平性由 budget 保证。

### 10.4 回收：两条路必须收敛到同一状态

1. 房间自己关（人走光 / 结算完没人 / 收到 CLOSE）→ `onClosed()` 摘表 → `kill` 自己
2. 管理器主动关 → **先摘表再 kill**（否则 kill 触发 `onStop` 会二次回调）

定时器不用手动撤：`kill` 会连带撤销该服务名下所有定时器（M2 做的事）。
所以「房间回收」不需要额外维护定时器清理逻辑——这是把定时器挂在服务上换来的好处。

### 10.5 实测

| 项 | 结果 |
|:--|:--:|
| 200 成员单帧（房间只记时间） | **< 5 µs** |
| 200 实体推进（`EntitySet`） | **51.6 µs/帧**（258 ns/实体） |
| 200 实体推进 + AOI 同步 | **227 µs/帧**（换格才重算视野，多数帧不换） |
| 批量创建/销毁 100 个房间 | 服务数、定时器数均回到基线（0） |
| 可变 delta 准确性 | 60ms 帧 → 位移 7（120 单位/秒，期望 7.2） |
| 状态机 | 重复加入/满员/非法转移全部被拒 |

`bench/room-test` 44 项 + `bench/entity-test` 38 项全过。

**为什么拆开之后数据反而更清楚了**：以前「200 实体单帧 67 µs」是个混合数字，
分不清多少花在房间、多少花在坐标。现在两者各自可测，也就能各自优化——
房间那条已经接近零成本，剩下的全是坐标推进与视野重算。

**现状与后续**：现在是一房一 Service。几千个房间就是几千个 Service + 几千个 50ms 定时器
（每秒 20 万次定时器触发），仍在 M2 的容量内但要留意。T3-03 的「分级策略」会允许
小房间合并进一个大 Service 共用一次 tick——那是性能优化，接口不变。

### 10.6 AOI 九宫格

```php
$scene = new Scene(1000, 10, 10);   // 格子边长 1000，10×10 格
$scene->add($uid, $x, $y);
$scene->move($uid, $x, $y);         // 返回 1 = 换格了，视野已重算
$scene->viewOf($uid);               // 我能看到谁
$scene->sees($a, $b);               // 两人能否互相看到
```

**第一版写错了，值得记下来。** 我最初给每个实体维护一份「我能看到谁」的视野表：
1000 人、平均 78 个邻居 → 存了 **77,940 条有向边**，每次换格还要往 78 个人的表里各写一次。
内存和维护成本都按「人数 × 邻居数」涨，1 万人场景直接不可用。

但网格视野本来就是**格子坐标的纯函数**：

```
A 能看到 B  ⟺  两个格子是同一个或相邻（切比雪夫距离 ≤ 1）
```

所以根本不用存。改成按需扫 9 格之后，1000 人的视野存储从 **77,940 条边降到 0**，
换格时也不再需要往 78 个人的表里各写一次。`sees()` 从「查表」变成「比两个格子坐标」，反而更快。

（第一版的时间开销当时是在 -O0 下测的，和现在 -O2 的数字不可比，所以这里不列对比——
只列结构性的变化：存储归零、换格写放大消失。）

**实测（`bench/aoi perf`，1000 实体、1000 单位格子、10×10 格、平均 10 人/格，-O2）**：

| 指标 | 实测 |
|:--|:--:|
| 格内移动（热路径） | **322 ns/次** |
| 跨格移动（含视野重算） | **21.5 µs/次** |
| 邻居查询（平均 78 个邻居） | 7.8 µs/次 |
| 朴素全量比较（扫 1000 人） | 127.7 µs/次 |
| 整帧 1000 人全部移动 | p50 **1.12 ms** / p99 **2.37 ms** |

**关于「1000 人场景更新 p99 ≤50 µs」这条验收线，需要改口径**：

- 按**单次 AOI 更新**算：格内 0.32 µs、跨格 21.5 µs，**都远低于 50 µs** ✅
- 按**整帧 1000 人全量**算：2.4 ms，**不可能达标** ❌

原因不是算法，是物理下限：整帧要给 1000 个实体各做一次「算格子 + 比较」，
而 AOT 下一次数组访问就要约 150 ns，1000 × 至少 200 ns = **200 µs 起步**，
再加约 6% 的换格（每次 21 µs）。50 µs 在这个运行时里对 1000 次操作不成立。

**九宫格真正的价值是复杂度而不是常数**：朴素两两比较是 O(总人数)，
10 万人在线时每次视野查询要 12 ms；九宫格是 O(邻格人数)，与总人数无关。
上表的「整帧 1.12 ms vs 朴素 127.7 µs/次查询」已经差了 **~100 倍**，而且差距随人数扩大。

---

### 10.7 匹配器：配对率取决于密度，不是算法

`pkg/match`（可选包）。棋牌/对战需要；MMO 用房间列表自选、SLG 不需要。

**为什么排序而不是两两比较**：1000 人两两是 50 万次距离计算；排序后是
O(n log n) 次比较加一遍线性扫描。

```
按评分升序排 → 从前往后，每人找后面第一个分差在容差内的 → 配掉双方
容差 = base + 已等待秒数 × expand
```

容差是**每人独立**的（等得久的更宽松），但内层有提前 break：分差超过
「等得最久的人能达到的容差」就停下——列表升序，后面只会更远。
这样既公平，又不会退化成 O(n²)。

**实测（`bench/match-test`，虚拟时钟）**：

| 场景 | 结果 |
|:--|:--|
| 1000 人、评分 0~2000、容差 50+50/s | **第 1 秒即 100%**，单轮 **2.4 ms** |
| 1000 人、评分 0~20000、容差 10+10/s | 3 秒 **86%**（770→830→862） |

**第二行是重点：86% 不是算法退化，是密度不够。**

能否配上取决于「容差窗口里有几个人」：

```
密度 ρ = 池子人数 / 评分跨度
窗口内候选数 ≈ 2 × 容差 × ρ
要配上需要  容差 ≳ 1 / (2ρ)
```

- 主场景：ρ = 1000/2000 = 0.5 → 容差 **1** 就够，给 50 绰绰有余
- 宽分布：ρ = 0.05 → 容差 10 勉强，放宽到 40 才配到 86%

所以「3 秒配对率 ≥90%」这条指标**必须绑定期望的玩家密度**，否则没有意义。
调参用：`base + s × expand ≳ 1/(2ρ)`，s 是允许的最长等待秒数。

**规模**：千级池子直接用（2.4 ms/轮，100 ms 扫一次 = 2.4% CPU）。
万级会到 30 ms+/轮，那时要改分桶（按评分区间分桶 + 桶内 FIFO），
复杂度从 O(n log n) 降到 O(n)——接口不变，`MatchQueue` 是内部实现。

**配对之后不建房**。匹配器只回调 `onMatched(uidA, uidB)`，
因为"配上了干什么"因玩法而异（1v1 直接开房、5v5 要攒满、棋牌还要发桌号），
硬编码进去就等于把它和房间绑死：

```php
$m = new MatchService(baseTolerance: 50, expandPerSec: 50, intervalMs: 100);
$m->onMatched(function (int $a, int $b): void {
    $room = $rooms->create(2);   // 这里的决定权在你
});
```

---

### 10.8 为什么不用 Open Match

经常被问：匹配为什么不用 [Open Match](https://github.com/googleforgames/open-match)？
它成熟，OpenKruiseGame 也内置了 `kruise-game-open-match-director`。

**先纠正一个误解：Open Match 不提供匹配算法。**

OM2 官方文档第一句：

> Open Match 2 is a **data layer** that helps your matchmaker run matching logic at
> scale. In technical terms, it is a player data cache with a data-retrieving gRPC proxy.

README 更直接：

> While Open Match provides the core framework, **you are responsible for building the
> matchmaker itself** ... Provide the custom matchmaking logic (as a Matchmaking
> Function, or MMF).

所以换过去，`MatchQueue` 那套排序 + 容差一行都省不掉——只是从「内存数组」变成
「从 QueryService 拉 ticket 再算」。**换位置，不换实现。**

**两者不在一个层面**：

| | `pkg/match` | Open Match |
|:--|:--|:--|
| 是什么 | 匹配**算法** | 匹配**基础设施**：ticket 存储 / Pool 过滤 / MMF 调度 / Assignment 下发 |
| 给算法吗 | 是 | **不给**，MMF 要自己写 |
| 依赖 | 无 | K8s + Redis + gRPC |
| 解决的问题 | 谁和谁配 | 怎么把结果落到某台游戏服 + matchmaker 怎么横向扩 |

**现在不引入的三条理由**：

1. **它最强的地方我们用不上**。OM 的核心价值是统一**多个 matchmaker 进程**的 ticket 池，
   再分配到 K8s 上的房间服。我们单机单进程，一个 `MatchService` 的队列就是全部状态；
   建房走 `Scheduler::deliver` 在自己进程内完成。为 1000 人的 1v1 引入 K8s + Redis，收益为负。
2. **引入成本是架构级的**。TSF 是 AOT 单二进制、零外部依赖；接 OM 等于整体换成 K8s 全家桶。
3. **维护状态要打折**。OM v1 最后编号发布是 **v1.8.1（2023-12）**，新开发转向
   open-match2，而 OM2 至今仍是 **public preview**（仓库 README 自己写的）。

**但它确实有我们没有的能力**（诚实记录，将来要重新评估）：

| 能力 | Open Match | `pkg/match` |
|:--|:--|:--|
| 组队匹配（多人一个 ticket） | ✅ 原生 | ❌ 只有单人 uid |
| backfill（给已开局补人） | ✅ 原生 | ❌ |
| 跨区延迟作为匹配维度 | ✅ ticket 可带属性 | ❌ 单维 score |
| 多目标优化（MMR + 延迟 + 队伍） | ✅ 自己写 MMF | ❌ 单维容差 |
| 分布式池子 | ✅ Redis | ❌ 单进程内存 |

**真正该引入的信号**：多区/跨区匹配、要组队和 backfill、matchmaker 需要独立扩缩容。
单机 1000 人 1v1 不在其中。

**上 OKG 未必要 OM**。`kruise-game-open-match-director` 只做第二件事：

> 主要帮助实现上述匹配过程的**第二阶段**——为对局找到游戏服并返回地址。

第一阶段（谁和谁配）它不碰。而 OKG 官方也支持自研匹配接入：

> 如果存在自研的匹配框架/系统，也可以通过简单的二次开发接入 OKG

两种方式：主动查 GameServer 的 `NetworkStatus`，或房间服自注册上报。所以真要上
K8s 游戏服编排，拿本包加这一步就能接，省掉 OM 那层 Redis + MMF 调度。

**接口已留余地**：`MatchService` 的唯一出口是 `onMatched(uidA, uidB)`，
换成「本地建房 / 调 K8s 分配 / 交给外部 Director」都只改这个回调，队列和算法不动。
这也是「配对后不建房」的第二个理由——不把架构决策写死进算法。

---

### 10.9 房间分派：独占还是共享

`RoomManager` 有两种承载方式，**由构造参数决定，业务代码不变**：

| 模式 | 结构 | 适用 |
|:--|:--|:--|
| `MODE_DEDICATED` | 一房一 Service、一个 50 ms 定时器 | MMO 副本、MOBA：实体多、帧重，慢帧不该影响别人 |
| `MODE_SHARED` | 多房共用一个 `RoomHost`，一个定时器、一帧 delta | 棋牌、回合制：人少、无实时位置、帧逻辑轻 |

```php
$rooms = new RoomManager(RoomManager::MODE_SHARED, 50, 200);  // 模式 / 帧间隔 / 每宿主上限
$rid = $rooms->create(4);                        // 返回**房间号**
$rooms->send($rid, RoomManager::OP_JOIN, "101"); // 投递只看 roomId
```

**实测（`bench/room-test`）**：

| 房间数 | 独占 | 共享 |
|:--|:--|:--|
| 20 | 20 Service / 20 定时器 | **1 Service / 1 定时器** |
| 1000 | 1000 Service / 1000 定时器，建模 **4.5 ms** | **5 Service / 5 定时器，建模 2.1 ms** |

定时器降 200 倍，建模快 2.1 倍。

**为什么 `create()` 返回房间号而不是服务地址**：共享模式下多个房间共用一个地址，
地址没法标识房间。早期版本返回地址，加共享模式时改成了 roomId 寻址——这也让
「换承载方式」对调用方完全透明：`send($roomId, $op)` 内部自己决定怎么投。

**代价必须说清楚**：

- **没有隔离**。一个房间的慢帧会拖累同一宿主的所有房间。所以共享模式只适合
  「帧逻辑轻」的房间；有物理、AOI、大量实体的用独占。
- 容量上限由 `maxRoomsPerHost` 控制，满了自动开新宿主（实测 12 间 / 每宿主 5 间 → 3 个宿主）。

**空宿主回收是手动的**（`closeIdleHosts()`）。自动回收会和「刚投了 ADD 还没被处理」
产生竞态，可能把新房间投给已死的宿主；而空宿主成本只是一个每秒 20 次的定时器
（每次只数一下房间数），交给巡检更安全。

---

### 10.10 端到端示例：1v1 对战

`demo/duel`：登录 → 匹配 → 对战 → 结算，全部走真实 TCP。

```
demo/duel/
├── main.php            装配：Gateway + MatchService + DuelManager
├── src/
│   ├── Msg.php         协议常量（5xxx 段）
│   ├── DuelRoom.php    战斗规则（血量/攻击/胜负），不依赖 Service
│   ├── DuelService.php 帧驱动 + 消息分发 + 推送
│   └── DuelManager.php uid → 房间转发表
├── client.php          单玩家客户端（aggressive / passive 两种策略）
└── run.sh              起服务 + 两个客户端，跑完整一局
```

**协议**（双向分开编号，抓包一眼能看出方向）：

| 号 | 方向 | 含义 |
|:--|:--|:--|
| 5001 | C→S | 入匹配 |
| 5002 | C→S | 取消匹配 |
| 5100 | S→C | 匹配成功（房间号、对手、起始侧） |
| 5201 | C→S | 移动方向 −1 / 0 / 1 |
| 5202 | C→S | 攻击 |
| 5300 | S→C | 状态快照（5 Hz） |
| 5301 | S→C | 结算 |

**三个设计决定**：

1. **客户端只表达意图，位置由服务端算**。移动发的是方向 ±1 而不是坐标——否则改个包
   就能瞬移。攻击同理：只说「我要打」，命中与否由服务端的距离和冷却判定。
2. **路由用 uid，不用房间号**。`DuelManager` 维护 uid → 房间地址。如果让客户端带房间号，
   等于把路由权交出去——改个包就能打进别人的房间。
3. **判定 20 Hz，推送 5 Hz**。判定频率决定手感，推送频率决定带宽，两者不该绑死。

**断线处理**：`Gateway::onDisconnect($cb)` 是这一轮新增的业务钩子。没有它，
掉线玩家的匹配票会一直挂在队列里，对战房间会干等 60 秒超时。示例里做两件事：
退出匹配队列、对战判负。

```php
$gw->onDisconnect(function (int $fd, int $uid) use ($matchAddr, $duels): void {
    Scheduler::instance()->deliver($matchAddr, MatchService::MSG_LEAVE, (string) $uid);
    $duels->forfeit($uid);
});
```

钩子在 `SessionManager::close()` **之前**取 uid——close 会把会话记录删掉，
晚一步就拿不到是谁掉线了。实测杀掉一方后另一方**立刻**收到结算（`run-forfeit.sh`）。

**一个必须知道的框架约定：`Scheduler` 没有自己的循环。**

Swoole 的事件循环是进程里唯一的循环。Service 的信箱要被处理，必须有人周期性地调用
`Scheduler::tick()`。`TcpServer` / `WsServer` 已在 `workerStart` 里装了 1 ms 的
`Swoole\Timer` 来推它：

```php
\Swoole\Timer::tick(1, function (int $timerId): void {
    Scheduler::instance()->tick(2000);
});
```

缺了这一步的现象是：**消息发出去了、Service 也创建了，但业务毫无反应**——
信箱永远没人读。这个坑是写 T3-06 时才暴露的（M2 的测试全是纯 Scheduler，
不需要和网络层共存）。

代价：Service 消息多 1 ms 延迟，空转约 0.5% 单核。实测 demo-echo 8500 连接的 CPU
从 37.5% 变 38.1%，在噪声范围内。

**实测**（`demo/duel/run.sh`）：

```
[1001] login ok, mode=aggressive
[1001] MATCHED vs 1002 (room 1, side 0)
[1001] hp=100 x=8000  |  foe hp=100 x=2300
[1001] hp=80  x=2600  |  foe hp=80  x=1097
[1001] hp=40  x=803   |  foe hp=40  x=450
[1001] hp=20  x=2006  |  foe hp=20  x=2850
[1001] RESULT LOSE (winner=1002)
```

进攻方输了：它一路冲过去，被动方原地等它进射程，双方同时进入攻击范围后，
被动方先打出最后一击。**这类「策略差异导致胜负」才是示例要验证的东西**——
如果两边都是同一套脚本，跑通也只能说明协议没错。

---

## 十一、为什么不引入 ECS（实测）

先澄清一个必须澄清的歧义：**这套代码里有三个东西都被叫做 "entity"**。

| 名字 | 是什么 | 在哪 |
|:--|:--|:--|
| ECS 的 Entity | **只是一个 id**，没有任何数据 | 没有 |
| `pkg/entity` 的 `Entity` | 有坐标和速度的游戏对象 | `Entity.php` |
| 房间里的成员 | 有身份和席位的人 | `Member.php` |

第三个**故意不叫 entity**，就是为了不和前两个混淆。第一个我们不用——
它只在 ECS 容器里才有意义。

「所有东西都走不出 ECS 这套概念」这句话，我认为**在词汇层面成立，在实现层面不成立**。
分三层看：

| 层次 | 是否采用 | 理由 |
|:--|:--|:--|
| **词汇**：世界由实体组成，实体携带数据，系统处理数据 | ✅ **一直在用** | `Room` 是实体、`Machine` 是它携带的组件、`RoomHost` 是系统、`EntitySet` 是位置组件 |
| **存储**：SoA / archetype 连续内存 | ❌ | 实测慢 3.4 倍（下面有数据） |
| **框架**：容器注册、组件查询、系统调度 | ❌ | 单机串行模型下是纯开销，且引入一次间接访问 |

换句话说：**我们吸收 ECS 的思想，但不买它的实现**。判断某个东西该不该是独立对象，
标准就是你说的那句话——「状态机可以绑定到任意实体上」，所以它是独立包；
而「坐标只有部分玩法有」，所以它也是独立包。

但要注意：**继承和 ECS 是对立的两条路**。

- 继承：`Player extends Member`，字段在**编译期**固定，一个对象只能是一种身份
- ECS：`entity + [Position, Health, Fsm]`，组件在**运行期**自由增减

不能既要"多态基类"又要"ECS 的组合自由"。本项目选了中间那条：
**少量明确的独立对象 + 组合，不用继承做身份区分**（数据见 §10.2.1）。

---

ECS 的性能前提是**连续内存 + 顺序遍历**（SoA + 缓存局部性），
而我们的运行时是 AOT PHP——每次访问都是带类型检查的取值。
这个前提到底成不成立，直接测（`bench/probe-ecs`）。

同一份数据（1000 个实体的 x/y/vx/vy，每帧更新位置），三种写法（-O2）：

| 写法 | 每帧耗时 | 单实体 |
|:--|--:|--:|
| A 对象数组（现在 Room 的做法） | **0.10 ms** | **99 ns** |
| B 组件并行数组（ECS 的 SoA 布局） | 0.33 ms | 332 ns |
| C 组件哈希表（多数 PHP ECS 的实现） | 0.37 ms | 368 ns |

**对象比 SoA 快 3.4 倍**，比哈希表快 3.7 倍。原因不难理解：对象方法是一个编译好的单元，
属性访问是固定偏移，`-O2` 能把方法体里的算术全部优化掉；而并行数组版本要在循环里做
4 次带边界检查的下标访问，这部分优化不掉。

唯一对 ECS 有利的数据是新增实体：对象 192 ns/个，SoA 117 ns/个。
但新增是低频操作，而更新循环是每帧都要跑的。

所以结论是：

1. **不引入 ECS 框架。** 它的核心性能收益在 AOT PHP 里不存在，还会额外引入
   组件 id 间接、系统调度、archetype 管理这些开销。
2. **我们的并发模型已经解决了 ECS 想解决的主要问题。** ECS 的一大卖点是
   "系统按组件顺序处理，天然无数据竞争"；而我们的一房一 Service 串行调度，
   本来就只有一个执行者。
3. **组合式需求用更轻的方式满足。** 需要"可插拔的行为"时，组合对象或可选包
   比整套 ECS 便宜得多。
4. **真要 ECS 就做成包。** 这与核心/包的分界一致：SLG/沙盒可能需要，
   棋牌/休闲永远不需要——那它就不该在地基里。

唯一的让步：如果某个热点循环实测发现对象数组不够快，可以**局部**改成 SoA
（比如只把坐标拆成平行数组），但这是优化手段，不是架构。

---

## 十二、M4 数据层：`pkg/db`

### 12.1 先回答「能不能直接用现成 ORM」

这是被问过的问题，我没有凭印象答，而是写了两个探针（`bench/probe-orm`、`bench/probe-orm2`）。

**ORM 依赖的机制，AOT 下全部可用**：

| 机制 | 结果 |
|:--|:--|
| `ReflectionClass` / 读属性 / 读 Attribute | ✅ |
| `newInstanceWithoutConstructor()` | ✅ 构造函数确实不跑，属性默认值保留 |
| `ReflectionProperty::setValue()/getValue()` | ✅ 带类型声明的属性还会做弱类型转换（`"42"` → `42`） |
| `__get` / `__call` / 动态类名 `new $cls` | ✅ |
| **运行时 `eval` 定义子类继承 AOT 类** | ✅ `instanceof`、反射父类、继承的方法都正常 |

最后一条是 Doctrine 懒加载代理的地基，我原本以为必死，实测能跑。

**但把 `doctrine/dbal` 整个塞进 `sources` 真实编译时，撞死在两处**：

```
Fatal error: Unsupported statement: Stmt_If in vendor/autoload.php:5
Fatal error: Readonly property Doctrine\DBAL\Cache\ArrayResult::$columnNames
             only supports direct assignment
```

第一条能绕过（排除 `autoload.php`——AOT 的全局作用域只允许声明，不允许可执行语句）。
第二条绕不过：Doctrine 用反射写 `readonly` 属性，AOT 拒绝，而 `ArrayResult` 是核心结果类。

所以官方文档那句「第三方库无法放入 `sources` 原生编译」是准确的，`embedded-files`
（构建期转 OPcache opcode）就是给这种情况准备的。它能用，但那就不是 AOT 了。

**结论：自己实现。** 但只实现游戏服真正需要的部分。

### 12.2 三层：Attribute、Repo、Source

```php
#[Table("players")]
final class Player
{
    #[Id]                 public int $uid = 0;
    #[Col]                public int $hp = 100;
    #[Col("nick")]        public string $name = "anon";
    public string $cache = "";      // 没有 #[Col] → 不落库，随便用
}

$repo = new Repo(Player::class, new Db("sqlite:/data/game.db"));
$p    = $repo->find(1001);      // ?object
$p->hp = 50;
$repo->update($p);
```

| 层 | 职责 | 文件 |
|:--|:--|:--|
| `Table` / `Id` / `Col` | 声明式映射 | `Table.php` `Id.php` `Col.php` |
| `Meta` | 一次反射，缓存 `ReflectionProperty` 与列清单 | `Meta.php` |
| `Db` | 连接（懒建/重连/事务）、语句复用 | `Db.php` |
| `Source` | 「按键取库」的抽象，单库与分片共用 | `Source.php` `Shards.php` |
| `Repo` | 增删改查 + hydrate | `Repo.php` |

**没有 UnitOfWork、延迟加载、关联图**。这三样对游戏服恰恰是负担：变更追踪要求
「先 load 再改再 flush」，和「每帧改内存、定期整存」根本对立；延迟加载会让某一帧
突然查库，表现为卡帧。

### 12.3 分库：调用代码一个字都不用改

```php
$one  = new Db("mysql:host=db;dbname=game");
$many = new Shards("mysql:host=db;dbname=game_{i}", 4);

new Repo(Player::class, $one);    // 单库
new Repo(Player::class, $many);   // 4 片，业务代码完全一样
```

`Source::forKey($id)` 单库返回自己，分片按 `id % n` 路由。这是分库能落地的关键——
否则每次扩分片都要改业务代码。

两条硬约束，构造时就会失败而不是运行时出错：

- **自增主键 + 分片**：插入前不知道落哪片，直接拒绝。分片表的主键必须由业务分配。
- **分片下 `findAll` 带 ORDER BY / LIMIT**：会变成「每片各取 N 条」，跨片顺序是错的。
  与其返回一个看起来对、实际错的结果，不如抛异常。

`saveMany` 在分片下按片分组、**逐片一个事务**。跨片不保证原子性——那需要两阶段提交，
代价远大于收益。

### 12.4 实测（`bench/db-test`，SQLite）

| 操作 | 结果 |
|:--|--:|
| 按主键读（复用预处理句柄） | **~4 µs** |
| 按主键读（每次 prepare） | 12.8 µs |
| `findAll` 1,000 行（含 hydrate） | ~2 µs/行 |
| `insertMany` 批量插入 | ~2 µs/行 |
| `saveMany` 1,000 对象（全 UPDATE） | ~3.2 µs/条 |

**复用预处理句柄带来 3.2 倍**（12.8 → 4 µs）。`find()` 是最热的路径，每次
`prepare` 一遍纯粹是浪费；所以 `Repo` 的固定 SQL 全部传 `reuse = true`，
而动态拼出来的 SQL 默认不缓存——它们的 SQL 串各不一样，缓存只会撑内存。

PDO 调用本身只占 2 µs（`bench/probe-db`），**所以「下沉到 C++」没有意义**：
一条 SQL 的网络往返和磁盘开销比这高两个数量级。

### 12.5 四个坑

**1. fork 之前绝对不能连库。**

Swoole 是 fork 模型的。如果 master 进程在 `start()` 之前建立了 PDO 连接，
这个 socket 会被所有 worker 共享，多进程同时读写同一个连接会串包。

`Db` 的懒连接就是为了保证「每个 worker 在自己的进程里第一次用时才连」。
示例里连 `CREATE TABLE` 都放在 `onWorkerStart` 里，不在 `boot()` 之前。

**2. `onWorkerStart` 曾经只有一个槽位（框架缺陷，已修）。**

`Gateway::boot()` 注册自己的 worker 启动钩子，**把业务方先注册的那个静默覆盖掉了**。
现象是建表没执行、对局跑完写库报 `no such table`，而日志里一句警告都没有。

现在 `Server::onWorkerStart()` 是累加列表，`fireWorkerStart()` 依次执行。
凡是「多人都可能想挂一个」的生命周期点，都必须能累加。

**3. `foreach` 的 key 与已声明变量同名（AOT 老坑的新变种）。**

```php
$shard = $k % $n;              // $shard 被绑成 Int
foreach ($groups as $shard => $items) {   // key 是 Var → 编译失败
```

改个变量名就好（`$shardNo`）。这条和 §5.9「不要直接 switch 属性」同源：
**AOT 里变量的类型由首次赋值固定，之后不容许跨类型赋值**。

**4. MySQL 的 `rowCount()` 在「值没变」时返回 0。**

`Repo::save()` 是「先 UPDATE，0 行就 INSERT」，在 MySQL 上会把「值没变更」误判成
「记录不存在」，然后重复 INSERT 撞主键。批量整存若可能没变更，直接用 `update()`；
或者打开 `CLIENT_FOUND_ROWS`。这里不自动处理，因为那个选项要在 DSN/属性层设，
影响面比这个方法大。

### 12.6 端到端验证

`demo/duel` 现在把每局结果落库，跑一局后：

```
$ php85 show-records.php
共 1 条对局记录
  #1 room=1 winner=1001 loser=1002 winner_hp=20 frames=109 ended_at=14:38:51
```

断线判负那局也落了（`winner=1002 loser=1001`）。这一条链路证明了
**AOT + Swoole + PDO + Attribute 映射**整体可用。

`DuelService::recordMatch()` 里有个刻意的写法：**落库异常被吞掉，只留日志**。
数据库抖一下不该让正在结算的玩家掉线——最坏情况是丢一条战绩。
游戏服里所有非核心路径的持久化都该是这个态度。

### 12.7 房间快照：崩了能把对局捞回来（T4-03）

#### 边界：快照包不认识 Room

```php
$snap = new Snapshot("duel", 1);
$snap->put("duel", $duel->toArray());   // 谁的数据谁负责序列化
$store->put($snap);
```

`pkg/snapshot` 里没有一句 `use TSF\Room\Room`。理由和 `pkg/db` 不认识实体类一样：
房间要快照，匹配池的排队状态、玩家存档、交易单也要。绑死一种就不通用了。

分工：

| 谁 | 负责 |
|:--|:--|
| `Room` / `MemberTable` / `EntitySet` | 自己的 `toArray()` / `loadFrom()` |
| 玩法（`DuelRoom`） | 自己的 `hp` / 冷却 / 胜负 |
| `pkg/snapshot` | 带版本的 JSON 容器 + 存储 + 哈希 |

#### 三个必须做对的地方

**1. 顺序决定哈希。**

成员按 `byUid` 的插入顺序导出，恢复时**必须按同样顺序插回去**。否则两次导出的
字节不同，"冷哈希一致"这条验收就永远过不去。同理，席位号必须显式带出去——
恢复时走 `add()` 会重新分配座位，客户端按席位号画座位，一换就人物跳位。

**2. 只允许灌进全新房间。**

`loadFrom()` 拒绝任何已有成员的房间。否则就是"用旧快照覆盖正在跑的对局"，
那是数据事故而不是恢复。要重建就 `Room::fromArray()`。

**3. 日期要墙钟，不是单调时钟。**

`atMs` 用 `microtime`，不用 `hrtime`——后者是进程内的单调时钟，跨进程重启就没法比了。

#### 原子写：临时文件 + rename

```php
$fh = fopen($tmp, "wb");  fwrite(...);  fflush($fh);  fclose($fh);
rename($tmp, $final);     // 同文件系统内原子
```

直接写目标文件，在写的途中被 kill -9 会留下半截 JSON——下次启动解析失败，
而且**分不清是"没写完"还是"数据坏了"**。`rename` 保证要么看到旧文件，要么看到新文件。

`fsync` 默认关（抗掉电要开，每次多几毫秒）。

#### 计划里的「热增量」被数据否掉了

原计划是「全量 + 热增量」。先量了全量到底能撑多大：

| 实体数 | 采集 | 恢复 | 字节 |
|--:|--:|--:|--:|
| 200 | 0.17 ms | 0.40 ms | 17.7 KB |
| 1,000 | 1.16 ms | 2.61 ms | 90 KB |
| 5,000 | 8.57 ms | 14.16 ms | 466 KB |
| 10,000 | 14.02 ms | **29.54 ms** | 935 KB |

验收线是 50 ms。**1 万实体的极端房间（几乎没人在一局里见到这个数）仍在预算内**，
而热增量要引入"基准快照 + 增量回放 + 增量丢失后的重建"三套故障处理逻辑。

所以：**不做增量**。理由是数据，不是偷懒。等真有单房间几千实体、且每秒都要存的场景再说。

#### 崩溃恢复实测

`demo/duel/run-crash.sh`：打 2 秒 → `kill -9` → 重启。

```
崩溃前: frame=40  elapsed=2000ms  1001@(7400,5000) v=(3000,0)  双方 hp=80
重启后: frame=60  elapsed=3000ms  1001@(9700,5000) v=(-3000,0)  双方 hp=80
日志:   {"msg":"rooms restored","ctx":{"count":1,"worker":0}}
```

不只是"读回来了"，而是**继续在跑**：帧数从 40 涨到 60，坐标继续推进，
`vx` 从 3000 变成 -3000 说明撞墙反弹也恢复了。

三个时机很关键：

- 快照每 20 帧（1 秒）一次，放在 `onTick` 里而不是定时器里——它跟着帧走，不会和帧不同步；
- **结算时立刻存一次**（带 `winner`），万一在关闭前的 3 秒里崩了，恢复出来是"已结算"而不是"打到一半"；
- 正常收摊时在 `onStop` 里删快照。进程被 `kill -9` 时 `onStop` 不执行，快照留下——这正是恢复需要的。

`bench/snapshot-test` 84 项：编解码、版本拒绝、两份存储行为一致、席位/顺序保持、
冷哈希一致、脏快照容错（非法 uid、未知身份、席位冲突、重复 uid 都能降级恢复）。

### 12.8 恢复限流：测完发现不需要（T4-05）

计划里这条叫「恢复限流，60 房同时恢复 ≤500 ms」，原始假设是"同时恢复会打满 CPU"。
先量了，假设不成立（`bench/snapshot-test`）：

| 房间数 | 落盘 | 枚举 | 恢复 |
|--:|--:|--:|--:|
| 60 | 5.19 ms | 0.06 ms | **1.68 ms** |
| 500 | 434.83 ms | 0.65 ms | **14.82 ms** |

60 房 **1.68 ms**，比验收线快 300 倍；500 房 14.8 ms，线性外推 5 万房才到几百毫秒。
**顺序恢复就够了，人为分批 sleep 只会拖慢启动。** 这是第 6 个被实测修正的计划项。

一个反直觉的点：**瓶颈在落盘不在恢复**——500 房的落盘 435 ms，恢复只有 15 ms
（每个快照要先 `fopen` + `write` + `rename`，而恢复是纯内存分配）。

#### 但"恢复期间服务不可用"是真问题

恢复发生在 `workerStart` 里，那段时间 worker 不接受连接。1.68 ms 无所谓，
规模大了就是启动期的服务真空。所以 `Restorer` 提供的是**可观测与可分批**，不是人为限流：

```php
$stat = (new Restorer(50, 200))->restore($store, "duel", function (Snapshot $s): bool {
    // 重建；返回 false 表示这条数据不可用
});
// ["done" => 60, "skipped" => 2, "ms" => 1, "slow" => false]
```

| 它做 | 它不做 |
|:--|:--|
| 分批记账（为将来"分批 + 延后"留结构） | 不 sleep、不人为限速 |
| 超阈值时在返回值里标 `slow`，由调用方决定告警 | 不依赖 Logger（这个包零依赖） |
| **坏快照就地删除**（否则每次启动都失败一遍） | 不认识任何业务对象 |

`dropBad = true` 那条很关键：一份永远解析不了的快照会让每次启动都卡在同一个地方，
而且你永远不知道它在拖慢启动——删掉才能让下次干净。

#### 什么规模才需要真限流

按 14.8 ms / 500 房的斜率外推：**5 万房约 1.5 秒**。到那个量级才需要
「先恢复活跃房间 + 剩余的延后到后台」，那时 `Restorer` 的分批结构正好用得上。
现在加只是给 1.68 ms 的操作套一层没用的壳。

### 12.9 事件日志：钱不能丢（T4-04）

#### 三块数据各有分工，不是互相替代

| 包 | 存什么 | 语义 | 丢了会怎样 |
|:--|:--|:--|:--|
| `pkg/db` | 当前状态（可 UPDATE） | 可覆盖 | 状态回退 |
| `pkg/snapshot` | 房间的完整快照 | 可覆盖 | 重开一局 |
| **`pkg/eventlog`** | **发生过的事（只追加）** | **不可变** | **数据事故** |

前两个都是"最后写赢"，第三个是"每一条都算数"。一笔充值被覆盖掉和被吞掉是两回事。

#### 持久性有两档，差 3500 倍

实测（`bench/probe-file`）：

| 写法 | 成本 | 抗什么 |
|:--|--:|:--|
| 持久句柄 + `fflush` | **1.2 µs/条** | **进程崩溃（kill -9）** |
| 持久句柄 + `fsync` | **4284 µs/条** | 机器掉电 |

这个区分必须说清楚，因为太容易被混为一谈：

- `kill -9` 只杀进程，`fflush` 过的数据已经在**内核页缓存**里，内核会照常写盘；
- 只有**机器掉电 / 内核崩溃**才会丢页缓存，那才需要 `fsync`。

所以默认 `MODE_FLUSH`。真需要抗掉电（支付类）用 `MODE_SYNC`，而且**必须批量提交**——
1000 笔每条 fsync 要 4.3 秒，等于把服务变成一个串行磁盘。

#### 崩溃时最后一行是半截

写入不是原子的。进程在 `fwrite` 中途被杀会留下半行 JSON。

重放时按 `\n` 切分、逐行解析，**遇到第一个坏行就停**——后面的内容不可信
（可能是同一块缓冲的残余）。`repair()` 把文件截到最后一条完整记录。

修复不是可选优化：**半行留着会让下一次追加接在它后面**，长出一条新的坏行，
从此每次启动都在同一处失败。

#### 序号接续，不回退

`seq` 在构造时扫描已有文件求最大值 +1。截断（归档）后 `lastSeq` **保持不回退**——
否则新事件会和归档前的重号，checkpoint 定位就全乱了。

#### 但这套序号只在一个写入进程下成立

`nextSeq` 是**构造时**扫文件定出来的，之后直接自增。两个进程各持一份 `EventLog` 写同一个
文件，就会各自从同一个序号往下发。实测（两个进程各追加 60 条）：

```
行数 120，坏行 0，id 唯一 True
唯一序号数 61，重复序号 59 个 —— (2,2) (3,2) (4,2) …
```

拆开看影响，别一惊一乍：

- 行是完整的（`fwrite` 一条不会撕裂），`id` 也唯一 → **幂等不受影响，钱不会多发**；
- 但 `replay($fromSeq)` 和 `truncate($uptoSeq)` **以 seq 为坐标** → 一撞就漏读/重读，
  "截到 N 号"也会切错位置。归档和 checkpoint 全建立在这上面。

所以：**多进程要写同一类事实，按 worker 分文件**（`settle.w0.log`）再在读取侧合并。
不要指望加锁——`flock` 不在 AOT 的 std 表面里，硬加会把 2.6 µs/条 变成系统调用级别的成本。
应用层应该在启动时断言：`demo/duel/main.php` 里 `worker_num > 1` 直接抛，拒绝启动。

#### 幂等是业务的事，日志只保证"可安全重放"

日志不去重：同一个 `id` 写两次就是两条。它保证的是"你可以重放任意多次"——
因为日志只追加，重放不改变日志本身。

去重交给业务（唯一键约束、已处理表）。这是刻意的分工：日志不知道什么是"重复"，
只有业务知道。

#### 实测

```
$ ./bench/eventlog-crash/run.sh
写入完成，seq=1000
kill -9（不给它 close 的机会）
重放条数: 1000
坏行数: 0
序号断点: 0
内容不符: 0
PASS 1000 笔交易零丢失
```

跑两遍结果一致。单条追加 **2.6 µs**、重放 **1.9 µs/条**、单条 97 字节。
`bench/eventlog-test` 44 项覆盖：序号接续、半行识别与修复、截断归档、两种模式、幂等去重。

一个测试抓出来的实现缺陷值得记：`lastSeq()` 最初只在构造和修复时更新，`append()` 之后
不更新——调用方拿它做 checkpoint 会漏掉本次进程写的所有事件。这类"看起来对、用起来错"
的方法最容易漏测。

### 12.10 迁移：状态必须跟着数据走（T4-02 收尾）

```
migrations/
├── 001_create_duel_records.sql          ← 通用
├── 002_add_vip.sqlite.sql               ← SQLite 专用
└── 002_add_vip.mysql.sql                ← MySQL 专用
```

```php
$mig = new Migrator($db, $base . "/migrations");
$r = $mig->up();          // ["shards"=>1, "executed"=>[1], "skipped"=>0, "failed"=>[]]
$mig->verify();           // 已执行但文件被改过的那些
$mig->status();           // 每个迁移跑过没有
```

#### 四个决定

**1. 迁移是 SQL 文件，不是 PHP 类。**

迁移是**部署时跑一次**的东西，不该进服务二进制。做成 PHP 类就得跟着 AOT 编译，
还要为"线上版本比代码旧"准备兼容。SQL 文件谁都能看、能 review、能手工补。

**2. 方言用两份文件，不用占位符替换。**

`AUTOINCREMENT` 在 SQLite 里是 `INTEGER PRIMARY KEY AUTOINCREMENT`，
在 MySQL 里是 `INT AUTO_INCREMENT PRIMARY KEY`——**位置和类型都不同**，
字符串替换补不出来。老老实实写两份，看得见、改得对。

找不到对应方言时**跳过而不是报错**：否则"只想给 SQLite 加个索引"就没法写。

**3. 状态存库里，不存文件。**

记录表 `schema_migrations` 跟着数据走。存文件的话，换台机器连同一个库，
工具会以为什么都没跑过——这是"迁移把线上表重建了"最典型的来源。

**4. 分片各自记录。**

每个分片一张 `schema_migrations`。合并成一份的话，某个分片迁移到一半失败，
就再也对不上账了。`statusAll()` 能逐片看进度。

#### checksum：改动已执行的迁移要被抓出来

记录表里存文件内容的 crc32。`verify()` 比对，不一致就报"文件已改动"。

这是迁移工具最有用的一条防线：**改了跑过的迁移，新环境会得到和现有环境不同的表结构**，
而这种不一致往往几个月后才炸。演示里每次启动都会跑一次 `verify()`，不一致就打 warn。

#### 按 `;` 切语句要用状态机

`INSERT INTO t VALUES ('a;b')` 是合法 SQL。`explode(";")` 会把它切成两半，
然后两条都语法错误。所以切分器跟踪引号状态（`'` / `"` / `` ` ``），引号内的分号不切。

#### 两个必须知道的限制

**MySQL 的 DDL 不支持事务回滚。** 每个迁移包在事务里跑，但 MySQL 的 `CREATE TABLE` /
`ALTER TABLE` 是隐式提交的——迁移写错时 MySQL 上会留下半成品，SQLite 上能整体回滚。
这是引擎差异，不是工具能解决的。

**执行时机：部署阶段跑一次，不要放进 `workerStart`。** 早先的实现是每个 worker 在
`workerStart` 里各调一次 `up()`：记录表能挡住"重复执行"，**挡不住并发建表**——
两个进程同时判断"还没跑过"，然后一起 `CREATE TABLE`。

现在分成两段：

```
部署：  tsf migrate .          ← 跑完就退出（等价于直接跑产物 ./duel migrate）
CI：    tsf migrate-status .   ← 只读，有未执行的就返回非零
启动：  只 verify() + 查有无未执行     ← 不建表
```

细节：

- **锁用目录**（`mkdir` 原子），不是 `flock`——后者不在 AOT 的 std 表面里。
  代价是进程被 `kill -9` 时锁会留下，所以锁里写了 pid 和时间，冲突时打印出来并提示
  "确认没人跑就删掉"。
- **启动仍然"不拦住服务"**：缺表时日志里是 `error` 级别的"有未执行的迁移，先跑
  `./duel migrate`"，但进程照常起来——这是沿用了"非核心路径不拦服务"（战绩丢了能再打）。
  要改成硬门禁就把那两处 `Logger::error` 换成 `throw`。
- 退出码是能用的：`tsf migrate` 失败返回非零，可以直接挂在部署脚本的 `&&` 后面。
  验证时注意——在本项目的 shell 里 `$?` 会被环境吃掉，用 `if cmd; then ... fi` 判断才准。

#### 实测

`bench/migrate-test` 50 项：幂等、增量、checksum 变更检测、方言选择、
引号内分号、3 分片各自迁移、指定目标版本。

端到端（`demo/duel`）：删库 → `tsf migrate-status` 报 PENDING 且返回非零 →
`tsf migrate` 建表 → 再 status 是 applied → 启动日志 `db ready`；
跳过 migrate 直接启动则在 0 号 worker 打出 error 级别提示。

`demo/duel` 已改为用它建表：

```
首启:  {"msg":"db ready","ctx":{"executed":1,"skipped":0,"failed":0}}
再启:  {"msg":"db ready","ctx":{"executed":0,"skipped":1,"failed":0}}
```

---

## 十三、M5 分布式：RPC（T5-01）

### 13.1 管道早就在，只缺配对层

做 RPC 之前先看了一遍已有的东西，结论是**不需要新的传输层**：

| 已有 | 在哪 |
|:--|:--|
| 跨进程投递（UDS + 攒批，p99 285 µs） | `Cluster` |
| 请求 id 字段（`Message::$session`，"回应时原样带回"） | `Message` |
| 端到端透传（`Service::send` → `deliverAs` → `cluster->send`） | 已经在传 |

也就是说请求-响应的**管道一直是通的，只是没人把它接起来**。这一层只补三件事：

1. 分配请求 id 并把回调挂上去
2. 收到响应时按 `session` 找回回调
3. 超时后把回调以失败结束，别让调用方永远等

```php
$rpc->call($targetAddr, "getProfile", "1001", function (?string $resp, string $err): void {
    if ($err !== "") { return; }     // timeout / no-method / handler-error
    // $resp
});
```

### 13.2 调用端必须是一个 Service

服务端用 `$m->from` 回包。如果调用方是普通对象，它没有地址——响应回来时
`deliver()` 找不到收件人，直接算 `undelivered` 丢掉。

所以 `Client extends Service`：有了信箱，`session` 回程和"用自己的定时器扫超时"
都自然了。这也是为什么用 `deliverAs($this->addr(), ...)` 而不是 `deliver(...)`——
后者会把 `from` 设成"当前正在处理消息的那个服务"，响应就回错地方了。

### 13.3 只能是异步回调

这个框架是单线程 + 消息循环，没有协程。想在 `call()` 里阻塞等响应，只能在 tick 里
嵌套 tick——那会把别的服务的消息插到中间，产生重入。

所以 RPC 就是回调式。`$cb` **一定会被调用一次**（成功 / 远端错误 / 超时），
调用方不需要自己兜底"回调可能永远不来"。

### 13.4 载荷自己编，不依赖传输层 codec

第一次跑跨进程就炸了：

```
RuntimeException: raw payload 只接受 string，收到 array（msgId=60001）
```

`Cluster` 的默认 codec 是 `RawPayload`，只搬 string。要求使用者"记得把 codec 配成 JSON"
等于把传输层的配置细节泄漏到业务里。

所以 RPC 自己编一次。**但编成什么，由 `RpcCodec` 决定，不是写死的。**

### 13.4.1 协议可插拔：两层分开

| 层 | 谁决定 | 内容 |
|:--|:--|:--|
| 消息头 | 框架 | `msgId`（请求/响应）、`session`（配对 id） |
| **载荷** | **`RpcCodec`** | 方法名、参数、成功/失败、错误内容 |

```php
interface RpcCodec
{
    public function packRequest(string $method, string $body): string;
    public function unpackRequest(string $raw): ?Request;
    public function packResponse(bool $ok, string $body): string;
    public function unpackResponse(string $raw): ?Response;
    public function name(): string;
}
```

**关键决定：只有两个协议号（请求 / 响应），"成功还是失败"交给 codec 编在载荷里。**

第一版用了三个（`MSG_RESP_OK` / `MSG_RESP_ERR`），看着省事，但等于把错误语义
钉死成"data 是一段错误字符串"。想换成 protobuf 的 `enum Status`、想在响应里
带重试建议、想让错误带上可重试标记——都做不到。

两个实现：

| codec | 格式 | 用在哪 |
|:--|:--|:--|
| `JsonCodec`（默认） | `{"m":"方法","b":"载荷"}` / `{"o":1,"b":"..."}` | 默认；**能抓包看**，排障快 |
| `RawCodec` | `方法名\n载荷` / `chr(1|0)+载荷` | body 已被业务序列化好（protobuf 等），不该再套一层转义 |

选 JSON 做默认不是因为快，是因为出问题时 `tcpdump` 里能直接读出方法名和参数，
比对着二进制猜位偏移快得多。要压性能再换 Raw。

测试里还写了一个故意奇怪的 `ReversedCodec`（方法名倒着写、`|` 定界、`Y`/`N` 标记）——
它能跑通，就证明框架里没有残留"响应一定是 JSON"的假设。

#### 协议不匹配时会怎样

两端 codec 不同时，客户端收到的是 `bad-response` 而不是 `bad-request`。
这不是 bug：客户端发的是 raw、服务端解不出来回了 `bad-request`，但那条响应是 JSON 格式，
客户端按 raw 解同样解不出来。**两端协议不一致时，任何一端的诊断都只能是"我解不出来"。**

关键行为是**快速失败**：实测 < 100 ms 结束调用，不会挂在那里等超时。
让调用方等超时是最难排查的故障——它看起来像"网络慢"，实际是"说不到一块去"。

### 13.5 重试：这层不做

任务表里写着"调用、序列化、超时、重试"，第四条我**故意没做**。

重试的正确语义取决于方法是否幂等：「超时了重发一次」对查询无害，对扣钱是灾难。
框架不知道哪个是哪个。所以 `Client` 只把错误原样给出（`timeout` / `unavailable` /
`no-method` / `handler-error`），重不重试、怎么去重，由调用方决定。

硬塞一个自动重试，等于替业务做了一个它没法验证的决定。

### 13.6 实测：延迟是排队，不是层内开销

`bench/rpc-cluster`（两进程走 UDS）：

| 在飞请求数 | avg | p99 |
|--:|--:|--:|
| 1（串行） | **204 µs** | **377 µs** |
| 10 | 276 µs | 419 µs |
| 50 | 850 µs | 1050 µs |
| 200 | 2990 µs | 3451 µs |

**验收线 p99 ≤2 ms，串行实测 377 µs——快 5 倍。**

并发那条曲线值得看清：50 → 200 是在飞数 ×4，延迟从 850 → 2990 是 ×3.5，
**几乎线性**。这说明瓶颈是排队，不是 RPC 层变慢：单次处理的真实成本约 15 µs
（2990 / 200），和串行时 204 µs 的往返相比，绝大部分花在"等前面的人处理完"。

按这个反推吞吐：`2000 × 2990 µs / 200 ≈ 30 ms`，即单连接对约 **6.7 万 req/s**。
这是"一个客户端 + 一个服务端、各自单线程"的上限，不是集群上限。

所以验收口径要写清楚：**p99 ≤2 ms 指的是单次调用（无排队）**。在飞 50 个以内仍达标，
再多就是容量问题，得靠多节点或把慢方法拆出去，而不是优化 RPC 层。

**2026-11-16 复核**（同机、单次运行，只看量级）：串行 p99 **389 µs**；
在飞 25 / 50 / 200 的 p99 分别是 **719 / 2027 / 3997 µs**，斜率约 17 µs/在飞——
和上面"延迟是排队"的结论一致。

顺手改了 `bench/rpc-cluster/run.sh` 的默认在飞窗口（200 → 25）：原来默认值正好落在
"2 ms 验收线不适用"的区间，**跑一次必然 FAIL**——一条永远红的用例，只会训练大家忽略 FAIL。
要看饱和曲线就显式传：`./run.sh 500 2000 200`。

`bench/rpc-test` 28 项覆盖语义：正常/未知方法/处理函数抛异常/超时/迟到响应丢弃/
20 个在飞不串号/参数校验。

---

## 十四、M5 分布式：服务发现（T5-02）

### 14.1 本机没有 Redis，所以先做文件实现

任务表写的是「Redis 注册 + 心跳 + 变更通知」。这个环境没有 Redis 服务，
而为了一个注册表去装一套 Redis，会把整个演示的启动依赖变得很重。

所以抽象出 `Contract\Registry`，先给一个 `FileRegistry`：

| 实现 | 适合 | 代价 |
|:--|:--|:--|
| `FileRegistry` | 单机多进程 / 测试 | 轮询目录 |
| Redis / etcd 实现 | 跨机器 | 一次网络往返 |

上层只看到 `alive()` 和 `get()`。**换 Redis 时业务代码一行不用改**——
和 `pkg/db` 的 `Source` 抽象同一个思路。

### 14.2 心跳必须单独一个文件

第一版用 `touch()` 更新主文件 mtime，极轻——但 **`filemtime()` 只到秒**。

心跳 1 秒、TTL 3 秒时，±1 秒的截断误差意味着：节点在 `t=0.99s` 心跳、mtime 记成 0，
到 `t=3.0s` 时算出来的 age 是 3 秒（**实际只过了 2.01 秒**）——会被误判死亡。

生产上这就是「节点偶尔被摘除、流量抖一下」，而且极难排查：现象是随机的。

所以每个节点两个文件：

| 文件 | 内容 | 写的频率 | 原子性 |
|:--|:--|:--|:--|
| `{id}.json` | 身份（endpoint / codec / tags） | 注册时一次 | `tmp` + `rename` |
| `{id}.beat` | 毫秒时间戳，20 字节 | 每次心跳 | 单次小写，坏了回退 mtime |

写 20 字节比「读-改-写整个身份」或「写临时文件再 rename」都便宜得多，
而精度从秒变成毫秒。

### 14.3 存活判定只能靠 TTL

`kill -9` 不给任何代码执行机会，`onStop` 里的 `unregister()` 根本不会跑。
所以真正的保证只能是 `now - lastSeen >= ttl`，**注销只是"加速收敛"**。

TTL 决定了收敛时间，而收敛时间和误判率是一对矛盾：
- TTL 太小 → GC 抖动或一次调度延迟就误判死亡
- TTL 太大 → 故障恢复慢

默认 1 秒心跳 / 3 秒 TTL：能容忍连续两次心跳丢失。

### 14.4 实测：3016 ms 收敛

`bench/discovery-live`：起一个节点进程 + 一个观察进程，`kill -9` 节点。

```
node: registered id=1 beat=500ms ttl=3000ms
kill -9 节点（不给它注销的机会）
watch: 最后一次心跳后又过了 3016ms 才判定死亡
watch: 活着期间观察到的最大心跳年龄 2912ms
PASS 收敛 3016ms（TTL=3000，轮询余量 800ms）
```

**3016 ms = TTL 3000 + 16ms 轮询余量**，正是设计值。

这个指标一开始算错过：我用「最后一轮扫描到下一轮」的间隔，得到 101 ms，看着很漂亮。
但那是轮询间隔，不是收敛时间——`lastAliveAt` 每轮都跟着"现在"更新，直到节点消失才停。
改成「最后一次心跳到判死」才对得上 TTL 的语义。**指标选错比数字难看更危险**。

### 14.5 codec 协商字段

`NodeInfo` 带 `codec` 名字。两端协议不一致时，`Rpc` 层报的是 `bad-response`
（"我解不出来"）——因为解不出来的那一端**没法知道对方用的是什么**。

把协议名写进注册信息，就能在**建连之前**发现不匹配：

```php
$peer = $registry->get($nodeId);
if ($peer->codec !== $myCodec) {
    // codec-mismatch:raw!=json   ← 错误信息能说出具体差异
}
```

但它只是"声明"不是"保证"：注册表里写 `json`、实际跑 `raw`，注册表管不了。
真正的强保证要靠握手时做能力协商（T5-04 跨路路由时再说）。

### 14.6 变更通知：心跳不能算变更

`refresh()` 对比上次快照，有变化才触发回调。**指纹只含身份字段，不含 lastSeen**——
否则每次心跳都算一次变更，监听者（路由表、负载均衡）会被每秒刷一次。

测试里专门盯了这条：连续 5 次心跳后 `refresh()` 都返回 false，回调只被调用过一次。

`bench/discovery-test` 55 项：注册/覆盖、心跳不改身份、TTL 过期、注销、
变更通知不抖、坏文件容错（坏 JSON / 空文件 / 缺字段 / `.tmp` 残留都不影响其他节点）、
`LocalNode` 自动心跳、codec 协商。

---

## 十五、M5 分布式：分片路由（T5-03）

### 15.1 为什么不能用 `hash % N`

最直觉的分片是 `hash(key) % N`。但 N 一变，**几乎所有 key 都要搬家**：
加一个节点，N 从 3 到 4，`%3` 和 `%4` 对 2/3 的 key 结果都不同。
一扩容就是全量迁移 + 缓存全失效。

一致性哈希把节点和 key 映射到同一个环上，key 顺时针找第一个节点。加节点只影响
它接管的那段弧。

### 15.2 虚拟节点：实测取舍

只放 1 个点/节点时，3 个点在环上随机落位，弧长分布极不均匀。

`bench/shard-test`（3 节点 / 20000 key）：

| vnodes | 最大占比 | 最小占比 | 极差 |
|--:|--:|--:|--:|
| 1 | 98.6% | 0.2% | 98.4% |
| 10 | 52.8% | 8.6% | 44.2% |
| 50 | 37.1% | 30.2% | 6.8% |
| **150** | **34.7%** | **32.1%** | **2.6%** |
| 500 | 38.1% | 30.9% | 7.2% |

**1 个虚拟节点时一个节点能吃掉 98.6% 的 key**——这不是"不太均匀"，是根本没分片。

150 之后基本饱和（500 那行的回弹是单次实验方差，不是变差）。所以默认 150。

### 15.3 扩容迁移：验收线达成

| 场景 | 迁移比例 | 理论 | 只迁到新节点？ |
|:--|--:|--:|:--:|
| 3→4（v=150） | **24.79%** | 25% | 是 |
| 4→5（v=150） | 14.12% | 20% | 是 |
| 4→3（删节点） | 24.00% | 25% | 是 |

**第三列是"一致性"的定义**：所有搬迁的 key 都只流向新节点，**没有任何 key 从老节点 A
迁到老节点 B**。违反这条，扩容就会引发连锁迁移。

4→5 的 14.12% 低于理论 20%——这是 150 个虚拟节点下的正常方差（单个节点覆盖率约 ±30%）。
不是 bug，但也说明：**要更均匀就得加大 vnodes**。16 节点时最大占比 9.2% vs 理论 6.25%（1.48 倍），
同一个原因。

### 15.4 三个坑，都很难查

**1. AOT 把 `int / int` 编译成整数除法。**

```php
$ratio = $moved / count($keys);        // 755 / 10000 = 0（不是 0.0755）
$ratio = (float) $moved / (float) count($keys);   // 0.0755
```

表现是"所有迁移率都是 0.00%"，而分子分母分别打印全是对的。**只有商是 0**。
所有除法必须显式转 float。

**2. 不能用 hash 当数组下标。**

第一版用 `array<int, int>`（hash => nodeId）。PHP 里没问题，但 AOT 下
`array_keys()` 返回的元素与原始 key 类型不一致时，拿它回头查原数组**查不到**。

现象极隐蔽：`size()` 返回 40、`nodes()` 正常、日志全都对，**只有 key 的归属完全不变**。
排查花了两小时，中间一度怀疑编译器做了公共子表达式消除。

改成两个平行数组（`$hashes` / `$owners`），查找只走整数下标，问题消失。

**3. `share()` 漏了回绕段。**

按"每个点到下一个点"算弧长，会漏掉 `[0, 最小点)` 这一段——它由最小点负责（回绕）。
漏了之后各节点占比之和只有 0.68，而不是 1。

### 15.5 性能

| 项 | 结果 |
|:--|--:|
| 查找（160 点 / 2400 点 / 8000 点） | **705 / 1221 / 1672 ns** |
| 建环（100 节点 × 150 vnodes = 15000 点） | **4.77 ms** |

建环一开始是 **109 ms**——因为每次 `add()` 都全量 `ksort`。改成"标记脏、查找前再排"后
降到 4.77 ms（23 倍）。查找的二分是 `log2(8000) ≈ 13` 层，每层一次整数数组访问。

查找那行还有个细节：属性要先取到局部变量（`$hashes = $this->hashes`）。
不取的话 8000 点要 4.4 µs，取了是 1.7 µs——13 层二分里每层省一次属性访问。

`bench/shard-test` 38 项。

---

## 十六、M5 分布式：跨服路由（T5-04）

### 16.1 透明靠地址，不靠特判

```php
$router->call($uid, "getProfile", "1001", function (?string $resp, string $err): void {
    // 不管 uid 在本机还是别的节点，这里拿到的形式完全一样
});
```

很多人会写成"如果目标是自己就走本地，否则走网络"两套分支。这里不需要：
目标地址统一是 `Addr::pack($nodeId, $entry)`，而 `Scheduler::deliverAs()`
内部就是按地址的节点位决定走本地信箱还是 Cluster。

**本机和远端是同一条代码路径，差别只在一个位运算里。**

节点上的哪个服务接跨服请求，由 `NodeInfo::$entry` 声明——比"约定 id=1"可靠，
后者依赖 spawn 顺序，加一个服务就崩。

### 16.2 踩到一个框架级缺陷

跨服测试第一次跑，症状是：**服务端 handler 明确被调用了（计数器 +1），但客户端收不到回包**。

查了很久，最终定位在 `Scheduler::deliver()`：

```php
// 改之前
if (($to >> Addr::ID_BITS) !== $this->localNode) { 走 Cluster }
```

而 `Service::bindAddr()` 注入的是**裸 id**（节点位为 0）。所以：

- 没有 Cluster 时 `localNode = 0`，`0 !== 0` 为 false → 本地投递 ✓
- `setCluster(1)` 之后 `localNode = 1`，`0 !== 1` 为 true → **本地地址被当成跨节点，
  发给不存在的节点 0，然后丢掉** ✗

**这个 bug 只在"接上集群"之后才出现**，单机测试永远看不到。而它影响的不是跨服调用，
是**所有本地服务之间的消息**——包括 RPC 的回包。

修法是把"节点位为 0"显式视为本地：

```php
$toNode = $to >> Addr::ID_BITS;
if ($toNode !== 0 && $toNode !== $this->localNode) { 走 Cluster }
```

这符合框架原有的约定（本地服务地址是裸 id，只有跨节点地址才带节点位），
而且对跨节点消息没有影响（那些地址的节点位非 0）。

### 16.3 实测：双进程，一半本地一半远端

`bench/shard-cross`：两个进程各是一个节点，各注册一个入口服务；节点 2 作为调用方，
按 uid 发 200 个请求。

```
client: 环里有 2 个节点
client: total=200 ok=200 err=0 节点不符=0
client: 路由统计 local=52 remote=148 no_node=0 stale=0 codec=0
client: 本地  n=52  p50=58µs   p99=177µs
client: 远端  n=148 p50=217µs  p99=469µs
```

**"节点不符=0" 是透明性的硬证据**：每个请求的返回体里带着"是哪个节点处理的"
（`echo:u42@n1`），和路由算出来的结论逐个比对，全部对上。

远端比本地慢 3.7 倍——跨一次 UDS 的真实代价（Cluster 往返 p99 291 µs，见 §9.7）。

52/148 的分布不算均匀，是 2 节点一致性哈希的方差（§15.2 那张表：2 节点时最大占比
可以到 52%），不是路由错乱。

### 16.4 一个测试方法上的坑

远端延迟一开始测出来是 **3 ms**，看起来像"跨服很慢"。实际原因是**服务端的 tick 循环
用了 `usleep(3000)`**——Cluster 的收包在 tick 里做，对端 3 ms 才 poll 一次，
这边看到的往返自然就是 3 ms 起。

把服务端 tick 间隔降到 100 µs，延迟立刻变成 217 µs。

**跨进程往返 ≈ 两端的 tick 间隔之和。** 测延迟时把人为 sleep 算进去，
得到的数字只会误导自己。

### 16.5 四个提前拦掉的失败

`Router::call()` 在发出去之前就检查：

| 情况 | 错误 | 理由 |
|:--|:--|:--|
| 环里没有可用节点 | `no-node` | 直接说清楚，别让调用方等超时 |
| 节点记录不见了 | `node-unknown` | 注册表刚清掉，可能重试就好 |
| 节点已过期 | `node-stale` | 环还没重建，但已经不该发 |
| codec 不同 | `codec-mismatch:raw!=json` | **最有用的一条** |

最后一条是这个类存在的重要理由之一：等到调用失败，对端只能报 `bad-response`
（"我解不出来"），因为**解不出来的那一端没法知道对方用的是什么协议**。
注册信息里有 codec 名字，就能在这里直接说出差异。实测同步发现，耗时 < 5 µs。

`bench/shard-router-test` 28 项 + `bench/shard-cross` 双进程。

---

## 十七、M5 分布式：熔断与降级（T5-05）

服务发现挡的是"已经不在了的"，熔断挡的是"还在但不干活的"。
这一节的重点不是三态机，而是**这两层为什么不能互相替代**。

### 17.1 判据：什么叫"对端失败"

这是最容易写错的地方，而且错了之后现象极具迷惑性。

`Router::call()` 的回调只有两个参数：`(?string $resp, string $err)`。
于是**业务错误和传输错误长得一模一样**——都是非空的 `$err`：

```php
$router->call($uid, "getProfile", ...);
// 对端超时          → err = "timeout"
// 对端说查不到角色  → err = "no such role"
```

如果"有错误就算失败"，那么「连续查 3 个不存在的角色」就会把整台服熔断。
上线后表现是：平时一切正常，一开活动批量查空数据，全站挂掉。

所以这里把判据反过来：

> **对端能回业务错误，恰恰证明它活着。**
> 它读到了请求、跑完了逻辑、还把结论编了回来——这比一次成功更能说明链路是通的。

`Rpc::blamesPeer()` 只认两个码：

| 错误 | 算对端？ | 为什么 |
|:--|:--:|:--|
| `timeout` | ✅ | 发了，对面没答 |
| `bad-response` | ✅ | 答了，但解不出来（协议不符也是节点问题） |
| `too-many-pending` | ❌ | **我们自己的**待发表满 |
| `encode-failed` | ❌ | 我们的 codec 编不出来 |
| `bad-addr` | ❌ | 我们的地址算错了 |
| 其他（业务错误） | ❌ | 能回话就是健康的 |

后三个本地错误尤其要注意：算进去的话，**自己过载时会把所有健康节点一起熔断**——
而那正是最需要它们的时候。

### 17.2 服务发现看不出"半死"

这一节存在的主要理由。进程活着不等于能干活的：

| 情况 | 心跳 | 能服务吗 | 谁发现 |
|:--|:--:|:--:|:--|
| 进程被杀 | 停 | 不能 | 注册表（TTL） |
| 工作循环卡死 / GC 卡死 / handler 死循环 | **继续跳** | 不能 | **只有熔断器** |
| socket 没 listen 上（路径冲突） | **继续跳** | 不能 | **只有熔断器** |
| 依赖的下游全超时 | **继续跳** | 不能 | **只有熔断器** |
| 滚动更新时新进程起来了但服务没初始化完 | **继续跳** | 不能 | **只有熔断器** |

后四种场景注册表会一直说"它还活着"，请求照发，然后集体超时。

实机验证就是照这个设计的：节点 3 **心跳正常、socket 也在**，但收到消息一个都不回。
跑出来的证据：

```
node3: **收下了 3 个请求，一个都没回**
node3: cluster_in=3 cluster_out=0
```

收包计数在涨（说明网络、编解码、投递全通），回包计数是 0。
**这种情况用 `kill -9` 是测不出来的**——杀掉之后 TTL 三秒就把它摘了，
量到的是 TTL 而不是熔断。

### 17.3 三态，以及冷却为什么要翻倍

```
            连续失败 N 次（默认 3）
   CLOSED ──────────────────────▶ OPEN
      ▲                             │
      │  探针成功                    │ 冷却结束，放**一个**探针
      └────── HALF_OPEN ◀───────────┘
                 │
                 └─── 探针失败 ────▶ OPEN（冷却 ×2，30 s 封顶）
```

`HALF_OPEN` 只放一个请求，其余继续拒绝。放一批进去的话，节点本来就缓不过来，
"探活"会变成"再压一次"，直接把它按死。

冷却从 2 秒起翻倍，是因为要区分两种情况：

- **偶发抖动**（一次丢包、一次长 GC）：2 秒就试，尽快恢复，不该惩罚它 30 秒；
- **真的坏了**：探针也失败 → 4s → 8s → … → 30s 封顶。
  固定 2 秒重试的话，每次恢复尝试都要卡一次超时，恢复得越慢惩罚得越久，正好反了。

还有一个细节：**熔断之后的迟到失败不刷新 `openedAt`**。

200 个请求在飞、节点此刻死掉时，它们会在 200ms 后陆续超时回来。
如果每次失败都重新计时，冷却会被"在飞数 × 超时时长"往后推——
负载越高、越不可能恢复。测试里专门用 100 次迟到失败钉了这条。

### 17.4 实测

三个进程，节点 3 半死（`bench/breaker-live`）：

```
client: 注册表里活着 3 个节点
client: 节点 3 在注册表里 存活=是（它心跳正常）
client: 第一次超时（耗时 129ms 起）
client: 连续 3 次超时后熔断，从第一次失败到不再发 = 250ms
client: 熔断后 10/10 次立刻返回 circuit-open，p99=30.6µs
client: 同时打健康节点 2：20/20 成功，p50=746µs p99=1432µs
client: 熔断后注册表认为节点 3 存活=是
```

四条验收线：

| 要求 | 实测 |
|:--|:--|
| 故障节点 5 s 内被摘除 | **250 ms**（快 20 倍） |
| 摘除后不再等超时 | **30.6 µs** 返回 `circuit-open`（原来是 120 ms 超时） |
| 熔断期间不影响健康节点 | 20/20 成功，一次没牵连 |
| 注册表确实看不出问题 | 仍报"存活"——两层是互补的 |

### 17.5 刻意不做：熔断后不重路由

节点 3 坏了，uid 归它的玩家请求怎么办？**不转给节点 1/2，直接失败。**

听起来很浪费，但在分片游戏服里这是唯一正确的选择：**玩家数据在节点 3 上**。
把请求转给节点 2，节点 2 读不到那份数据，只会从一个"明确的失败"
变成"看起来成功但数据是错的"——后者危险得多。

要真正做到"节点坏了玩家还能玩"，需要的是**数据层的副本或迁移**（玩家下线即落库、
节点故障后按 uid 重新分片再加载），不是路由层的重试。

所以这里的定位很明确：熔断让失败**快速且可观测**，不假装能修复它。

### 17.6 降级

`call()` 加了可选的第 6 个参数：

```php
$router->call($uid, "getProfile", "1001", $cb, 0, function (int $uid, string $method): ?string {
    return $this->cache->get($uid);   // 返回 null 表示"我也兜不住"
});
```

- 返回 string：**按成功回调**（`$err` 为空）。调用方主动给了兜底值，
  就是承认"这个替代结果可以当结果用"——这正是降级的定义。
- 返回 null：原样报错，调用方该走异常分支。

降级值不带"这是降级数据"的标记。要区分的话，在 fallback 里返回带标记的内容
（`json_encode(["degraded"=>true,"data"=>...])`），而不是让框架替业务决定数据长什么样。

### 17.7 一个必须处理的边角：探针位要还回去

探针发出去了，但我们自己发不动（待发表满、codec 编不出来）——这既不能算对端健康，
也不能算它坏。如果什么都不做，`HALF_OPEN` 的 `probing` 会永远是 `true`，
**这个节点再也等不到下一次探活**，等于被永久判死。

所以 `release()`：只放开探针位，状态留在 `HALF_OPEN`，下一个请求立刻能接着探
（不用再等冷却——冷却的意思是"给对端休息时间"，而这次压根没打扰它）。

### 17.8 默认不清熔断记忆

节点离开环时**不**清它的熔断状态（`setForgetOnLeave(true)` 可以改）。

理由：节点重启后会很快重新入环，而刚重启的节点往往还没预热（缓存空、连接池空）。
保留熔断记忆能让它多缓 2 秒再上量，避免"重启即被压死再重启"的循环。
代价是几十个节点的槽位常驻，可以忽略。

`bench/breaker-test` 86 项（纯逻辑 51 + 错误分类 9 + 接进 Router 26）+
`bench/breaker-live` 三进程。

---

## 十八、M5 分布式：集群压测（T5-06）

前面四项（RPC / 发现 / 分片 / 熔断）都是分开测的，各自的数字都好看。
这一项要回答的是别的问题：**它们同时工作时还成立吗**。

### 18.1 为什么"冻结"而不是"杀掉"

杀掉节点是最省事的故障模拟，但它测错了东西：注册表 3 秒把节点摘掉、环重建成 2 节点、
uid 全部重分布——量到的是**缩容**，不是故障。

真实故障里最难受的恰恰是**节点还在、但它不干活**：包发得出去、回不来，请求一直挂到超时。
所以这里用文件开关把节点 3 冻住：心跳照跳、socket 照收，但一条都不回。

```
阶段 1  基线   3 节点全健康，跑满一轮
阶段 2a 过渡   冻结，一直跑到熔断生效
阶段 2b 稳态   熔断已生效，继续压
阶段 3  恢复   解冻，等冷却，再打
```

驱动端保持固定窗口的在飞请求（不是一次一发——串行测不出排队，而雪崩就是排队引起的），
uid 按归属分桶后**交错**发出，保证相邻三个请求分别去 1/2/3。

### 18.2 容量包线：先给"工作点"，再给数字

"p99 ≤5 ms"在一个具体工作点上才成立。所以先扫窗口，找到拐点：

| 在飞窗口 | 吞吐 | 基线 p99 | 冻结稳态 n2 p99 | 判定 |
|--:|--:|--:|--:|:--|
| 16 | 15.6k req/s | **692 µs** | 2388 µs | 达标，余量充足 |
| **32** | **16.9k req/s** | **1753 µs** | **4188 µs** | 达标，余量 15% |
| 96 | 21.2k req/s | 3143 µs | **8425 µs** | ❌ 稳态超线 |
| 192 | 21.4k req/s | **6581 µs** | 15513 µs | ❌ 基线就超线 |

**拐点在 96 附近**：96 → 192 只多了 0.7% 吞吐，p99 却翻了一倍多。
换句话说 **~20k req/s 是这套东西的实际上限**，再往上加并发只是往队列里堆人。

p99 ≤5 ms 的**稳定工作点**是窗口 ≤48、吞吐 ~19k req/s。
报一个"p99 = 1.7 ms"而不说这个 1.7 ms 是在多小的负载下拿到的，等于没报。

### 18.3 过渡期和稳态必须分开算

第一版我把冻结阶段的 6000 个请求算成一个 p99，得到"健康节点 p99 2945 µs"——看着挺好。
但按节点拆开看，**n2 自己的 p99 是 5637 µs**，已经超线了。合并成一个数组会被延迟最低的
那个节点（本地 n1）摊平，真正被牵连的远端节点反而看不出来。

改成按节点分开、并且把过渡期单独切出来之后，数字才可信：

```
基线     n1 p99=1718µs  n2 p99=3371µs  n3 p99=3361µs    16620 req/s
过渡     n1 p99=2568µs  n2 p99=3406µs  n3 超时 32 次      1029 req/s   135ms
稳态     n1 p99=2346µs  n2 p99=4463µs  n3 全是熔断        20270 req/s
恢复     n1 p99=2278µs  n2 p99=3828µs  n3 成功 644 次     16667 req/s
```

### 18.4 稳态的 p99 反而更高，但这不叫雪崩

冻结稳态下 n2 的 p99 从 3371 µs 涨到 4463 µs，同时**吞吐从 16.9k 涨到 20.3k**。

两个数字一起看才说得通：节点 3 的请求现在微秒级失败、不再占窗口，于是**同样的窗口
承担了更多的 n1/n2 请求**——多出来的 20% 吞吐就是那 1/3 的槽位被重新分配了。
队列变长，延迟跟着涨，这是排队，不是故障扩散。

真正的雪崩长什么样：坏节点的请求占着队列不放，好请求也被拖到超时，
**吞吐塌下去起不来**。这里吞吐反而升了 20%，所以不是。

### 18.5 过渡期的代价是吞吐，不是延迟

那 135 ms 里发生了什么：

```
过渡     total=142 耗时=138ms 吞吐=1029 req/s
  n1 成功 48   n2 成功 47   n3 失败 47（超时 32 / 熔断 15）
```

**吞吐从 16.9k 掉到 1k（-94%），但健康请求的 p99 只有 2.5 ms，没坏。**

原因是**熔断只能拦"还没发出去的"**。冻结发生的那一刻，窗口里已经有 ~11 个发往节点 3
的请求在飞，它们必须各自走到 120 ms 超时才会释放；这期间槽位被占住，新请求发不出去。
所以过渡期的代价不是"好请求变慢"，而是"根本没发新请求"。

这也解释了为什么是 32 次超时而不是 3 次：熔断要等第 3 个失败**回来**才触发，
而失败回来时另外 29 个早就发出去了。**熔断减少的是后续损失，不是当次损失。**

要压缩这段，只能从源头下手：**给单个目标加在飞上限**（不让一个节点占满整个窗口）。
这是 §18.8 补上的东西——加完之后过渡期吞吐从 763 回到 20 992 req/s。

### 18.6 恢复也不是瞬间全开

解冻后等 1.4 秒（冷却 1200 ms + 余量），节点 3 重新可用。但恢复阶段里还有 22 次熔断：

```
恢复     n3 成功 644 / 熔断 22
```

因为 `HALF_OPEN` **只放一个探针**：探针回来之前，后面所有请求继续被拒绝。
这 22 次就是探针在飞的那一小段时间。这是设计如此——一次性全放进去，
缓不过来的节点会被再压死一次。

### 18.7 一个仍然存在的余量问题

稳态 n2 的 p99 是 4188 / 4246 / 4322 µs（连跑三次），离 5 ms 只剩 15% 余量。
这个余量不是被故障吃掉的，是被"同样的窗口承担更多请求"吃掉的（§18.4）。

换句话说：**这套系统在"3 节点健康"时是安全的，在"2 节点且负载不变"时是紧张的。**
生产上要把窗口/并发上限当作容量参数来配，而不是写死一个数。

`bench/cluster-bench`，`./run.sh <每阶段请求数> <在飞窗口> <单目标上限>`。

### 18.8 补上：单目标在飞上限

§18.5 那个"过渡期吞吐掉 94%"不是一个可接受的结论，它是一个**待修的缺陷**。

根因：`Client` 的待发表是**所有目标共用**的。节点卡住时，发往它的请求会一直挂到超时，
这期间它们占着共用的待发表和调用方的并发窗口，把发往健康节点的请求也挤得发不出去。

熔断管不了这一段——它要等第 3 个失败**回来**才触发，而那时已经有几十个请求发出去了。
所以在熔断之前还需要一层：**不管对方是死是活，一个目标最多占 N 个槽位**。

```php
// Shard\Router::call()
$cur = $this->inFlight[$info->nodeId] ?? 0;
if ($cur >= $this->maxPerTarget) {
    $this->rejectedSaturated++;
    $this->degrade($fallback, $uid, $method, $cb, "target-saturated");
    return false;
}
```

三个设计点：

1. **放在熔断检查之前**。`allow()` 会占用 `HALF_OPEN` 的探针位（有副作用），
   先 allow 再因饱和拒绝就要 `release()` 还回去，白绕一圈。而"还没发出去"
   对"对方健不健康"没有任何信息量，本来也不该碰熔断器的状态。
2. **饱和拒绝不记在对端头上**。否则我们自己窗口配小了，会把健康节点判死。
3. **减在飞数必须在调用户回调之前**。用户回调里往往紧接着发下一个请求，
   晚减的话它会被自己卡住。

实测（同一轮内对比，避开机器漂移；每格是 3 次的区间）：

| 单目标上限 | 基线吞吐 | **过渡期吞吐** | 过渡期健康请求完成数 |
|--:|--:|--:|--:|
| 64（≈不限） | 14.7k req/s | **763 ~ 840 req/s** | 36 ~ 38 |
| **12** | 18.2 ~ 18.9k req/s | **19 072 ~ 20 992 req/s** | 774 ~ 795 |
| 16 | 18.6k req/s | 18 076 req/s | — |

**过渡期吞吐差 23~27 倍**，健康请求完成数差 21 倍，而基线在噪声范围内没有变化；
冻结稳态的 n2 p99 反而从 **4.2 ms 降到 2.6~3.0 ms**（单链路上的队列短了：
上限 12 时一个节点最多排 12 个，不设上限时能排到 30 多个）。

> 注：§18.2~18.7 的所有数字都是**修复前**（无上限）测的，复现时给
> `./run.sh` 传第 3 个参数 64；默认值已改成 12。

配置规则：

> **上限必须小于"全局并发 ÷ 目标数"，否则等于没设。**
> 但贴得太近会有零星饱和——上限 12 时基线有 0~1.4% 的请求被拒（`window 32 ÷ 3 ≈ 10.7`）。
> 这是取舍不是 bug：隔离性和"绝不误拒"不可能同时拿满。

实践上取「单节点期望并发的 2~4 倍」并确认它明显小于全局待发表。
默认值 64 是按"几十个客户端共用一条链路"估的。

### 18.9 一个测试自身的坑

加了上限之后压测数据全乱了（6000 个请求 170ms 跑完、每个节点 1994/2000 被拒）。那不是框架的问题，是压测驱动器自己饿死了：

被上限或熔断拒掉时**回调是同步的**，驱动器的 `$pending` 立刻退回原值，
内层循环于是永远满足 `$pending < $window`——几千个请求在一个循环里发完，
一个 `tick()` 都没跑，已经在飞的那些永远收不到响应。

修法是每发 16 个就 `tick()` 一次，并且**停止条件也要在内层查**（熔断生效后全是同步失败，
内层会一路发到底）。写压测的时候很容易忘掉这件事：并发控制器的回调和它自己的循环是耦合的，
同步回合一多，循环就不再让出。

---

## 十九、M6 工具链：配置表导出（T6-01）

```bash
tsf config build <工程>   # Excel → gen/Config/*.php + build/config/*.bin
tsf config check <工程>   # 只校验
```

### 19.1 分界线：代码编进二进制，数据不编

最省事的做法是把每张表生成成 PHP 字面量数组——编译进二进制，运行期零成本。
但那等于**每次改数值都要重新编译**，而策划一天改十次。

所以分成两半：

| 产物 | 什么时候变 | 进二进制吗 |
|:--|:--|:--:|
| `gen/Config/*.php`（字段定义 + 读取代码） | **加列/改类型**才变 | 是 |
| `build/config/*.bin`（数据） | 每次改数值都变 | 否，运行期读文件 |

这条分界决定了 T6-02 热更能不能做：**只有数据在文件里，才存在"换掉文件不重启"**。

### 19.2 表头就是 schema

```
Row 1   id          itemName         quality   attack    tags
Row 2   int(1..9999) string(1..16)   int(1..5) int(0..999) int[]
Row 3   # 物品 id    # 名字           # 品质    # 攻击     # 标签
Row 4   1001        木剑             1         5         1,2,3
```

类型写在 Excel 第 2 行，而不是单独维护一份 schema 文件。理由很实际：
**策划只会改 Excel**。多一个 schema 文件就多一处会忘记同步的地方，而且改列时
还得同时会两种文件格式——那正是"工具链不可靠"的来源。

第 3 行是注释行，靠"第一格以 `#` 开头"识别。这样不写注释的表也不会把首行数据吃掉。

支持的类型：

| 声明 | 含义 | 校验 |
|:--|:--|:--|
| `int` / `int(1..10)` | 32 位整数 | 整数格式、区间、**32 位范围** |
| `float` / `float(0..9.9)` | 小数 | 数字格式、区间 |
| `string` / `string(1..16)` | 字符串 | **字符数**（不是字节数）区间 |
| `bool` | 布尔 | `1/0`、`是/否`、`true/false` 都收 |
| `int[]` | 整数数组 | 每个元素 |
| `ref:item` / `ref:item.price` | 外键 | 目标表**必须存在**、目标列必须存在、值必须在 |
| `ref:item[]` | 外键数组 | 每个元素 |

数组分隔符收 `,`、`，`、`|`、`、` 四种。策划这几种都用过，
只认半角逗号的结果就是"我明明填了但工具说空"——然后你去查代码，最后发现是全角逗号。

### 19.3 校验：全量报错、报 Excel 行号、失败一个字节都不写

```
✗ 配置表校验失败（4 处，未写任何产物）
  bad.xlsx 第 4 行「value」 值 999 超出 int(0..100)
  bad.xlsx 第 5 行「id」 不是整数：abc
  bad.xlsx 第 4 行：主键 1 重复（第 3 行已经用过）
  bad.xlsx 第 4 行「refMissing」：值 999999 在表 [item] 的主键里不存在
```

三件事都是刻意的：

- **一次报全部错误**。报第一个就退出的话，改一个跑一次，十处错误就是十轮。
- **行号是 Excel 里看到的行号**，不是数组下标。差 1 会让策划找不到地方。
- **失败不写任何产物**（连 `gen/` 都不建）。写了就会出现"代码是新的、数据是旧的"，
  运行期表现是某些字段永远是 0 而不是报错——这是最难查的一类故障。

### 19.4 二进制格式

```
"TSFC" 4 | version u16 | nameLen u16 | fieldNum u16 | rowCount u32 | 表名
fields    fieldNum × (u16 名字长度 + 名字 + u16 类型长度 + 类型声明)
rows...
  rowLen u32 | payload（按 fields 顺序的各列）
```

列编码：`int` 4 字节有符号；`float` u16 长度 + 十进制 ASCII；`string` u32 长度 + 字节；
`bool` 1 字节；`int[]` u16 个数 + 每个 4 字节。

三个决定：

**1. 每行带长度。** 读取端按理必须按 schema 顺序读完每列，但万一代码和数据对不上
（少读一列），没有行长度就会一路读歪到别的行里去，报出来是"某列值很离谱"而不是
"schema 不一致"。有行长度就能立刻发现并说清楚。**末尾追加列能兼容也全靠它**——
老代码读完自己那几列后，`next()` 按行长度直接跳到下一行。

**2. 头部存字段清单，不是 hash。** 第一版只放了一个 crc32 指纹，能挡住"代码和数据
不是一批生成的"，但挡的时候只能说"指纹不对"——**说不出哪一列不对**。而配置表出错时，
人最想知道的就是这个。所以改成存完整清单，逐列比对：

```
配置表 [item] 第 3 列不一致：代码期望「quality:int(1..5)」，数据里是「quality:int(0..9)」
→ 重新跑 tsf config build
```

**3. 不用 pack/unpack。** `Cluster\Packet` 早就写下过结论："AOT 下对 pack 的支持面
不确定，而 chr/ord 在所有模式下都稳"。配置文件读取端是 AOT 编译进二进制的，跟着用
chr/ord。

**小数为什么存成十进制字符串**：自己实现 IEEE 754 的编解码（符号位、指数偏移、隐含位、
次正规数）是一大坨容易写错又难测的代码，收益只是每行省几个字节。配置表加载是启动时
一次性的，这点体积换"不会因为舍入写错"很值。用 `%.17G` 保证 round-trip——默认的 14 位
会丢位，`1.2` 导出再读回来就不等于 `1.2` 了。

### 19.4.1 末尾追加列：不用重编程序

字段清单带来的直接好处：代码认识 N 列、文件有 M ≥ N 列时，**多出来的尾列被忽略**。

于是"策划给所有物品加一个活动倍率"这种改动，流程是：

```
改 Excel（在末尾加一列） → tsf config build        ← 只重跑导出
                                      ↑ 不跑 tsf build
```

老程序照常跑，新列等到下次重编才被读到（`Reader::extraFields()` 能列出"有哪几列没用上"）。

端到端验证过（`bench/config-test/run.sh` 第 5 步）：

```
已在 Excel 末尾追加一列 eventRate
    item: 4 行 × 10 列 → build/config/item.bin（488 字节）
结果: 43 通过 / 0 失败
PASS 旧程序读新数据（末尾多一列）完全正常，不需要重编
```

**这个放宽只对末尾有效。** 中间插列会让后续所有列错位，比对在第一个不同的位置就失败，
不会静默读错——这正是"存清单"比"存 hash"多出来的那点安全性。

第一版为了一个 crc32 的"严格"，把"末尾追加"也一并禁掉了。这是过度保守：
严格性的目的是**防止错位**，而末尾追加本来就不会错位。

### 19.5 实测

| 项 | 结果 |
|:--|--:|
| 4 行 × 9 列 → item.bin | 450 字节（含 178 字节字段清单） |
| 10000 行 × 7 列 → big.bin | 764 KB（约 76 字节/行） |
| 加载 10000 行 | **59 ~ 63 ms**（6.2 µs/行） |
| 重新 load 同一张表 | **64 ms** |

最后一行是给 T6-02 的预警：**热更目标是"改 Excel <100 ms 生效"，而一张万行的表
重新加载就要 64 ms**。加上轮询检测和文件读取，单表已经吃掉大半预算；多张大表同时变必然超。
T6-02 要么按表增量重载，要么把验收口径改成"单表 <100 ms"。这个数字现在就记下来，
免得到时候以为是热更写慢了。

> 这批数字是共享 WSL 虚拟机上的实测值，机器被别的进程占用时会漂到 90~100 ms
> （同一条命令连跑能差 60%）。所以断言用的是"比值"和"量级"，不是绝对阈值——
> 性能测试的老规矩。

字段清单的代价：小表按比例看不小（9 列多 178 字节），万行表上完全感觉不到。
换来的是能报出"第几列不对"和末尾追加列免重编，值。

### 19.6 两个真踩到的坑

**1. 自闭合单元格吞掉后一格。**

```xml
<c r="H7" t="inlineStr" /><c r="I7" t="inlineStr"><is><t>1</t></is></c>
```

第一版用 `/<c\b([^>]*)(?:\/>|>(.*?)<\/c>)/`：`[^>]*` 会把结尾的 `/` 一起吃进去，
于是只能走 `>(.*?)</c>` 分支——而它没有闭合标签，`.*?` 一路吞到**下一个单元格的
`</c>`**。现象是整行少一列、后面的值还往前错位。修法是把自闭合单独判：

```php
if (substr($raw, -2) === "/>") { $attrs = substr($raw, 2, -2); $inner = ""; }
```

**2. `Reader` 的游标初值。**

`next()` 的第一步是"跳过上一行剩余"，而它依赖 `rowEnd`。第一版 `rowEnd` 默认 0，
于是第一次 `next()` 把游标拉回文件开头，把魔数 `TSFC` 当行长度读——报"第 0 行长度越界"。
初值必须是**表头之后**的位置。

### 19.7 生成的代码长什么样

```php
final class Item
{
    public int $id = 0;
    public string $itemName = "";
    ...
    /** int[] */
    public array $tags = [];
}

final class ItemTable
{
    /** 本代码认识的列（名字:类型，分号分隔）；文件里多出来的尾列会被忽略 */
    public const SCHEMA = "id:int(1..9999);itemName:string(1..16);quality:int(1..5);...";

    public function load(string $path): int
    {
        $r = Reader::open($path, "item", self::SCHEMA);
        $byKey = [];  $rows = [];
        while ($r->next()) {
            $o = new Item();
            $o->id = $r->int();
            $o->itemName = $r->str();
            ...
            $byKey[$o->id] = $o;
            $rows[] = $o;
        }
        $this->byKey = $byKey;  $this->rows = $rows;   // 整体替换，不叠加
        return count($rows);
    }

    public function get(int $id): ?Item { return $this->byKey[$id] ?? null; }
}
```

`load()` 先把新数据读到局部变量、最后一次性赋值。这个写法是为了热更：
中途抛异常时旧数据还在，不会留下"一半新一半旧"的表。

`Tables::load($dir)` 是总入口，每张表一个静态属性 + 一个访问器（属性带类型，
AOT 下比用数组装对象稳）。

`bench/config-test` 43 项（含"末尾追加列不重编"的端到端验证）+ `bench/config-bench` 加载耗时。

---

## 二十、M6 工具链：配置热更（T6-02）

```php
Tables::load("build/config");
Scheduler::instance()->spawnOf(function (): Watcher {
    return new Watcher("build/config", function (array $changed): array {
        return Tables::reload($changed);
    }, 30);
});
```

### 20.1 判"内容变了"不能看 mtime

第一反应是轮询 `.bin` 的 mtime。**这条路是错的**，而且错得很隐蔽：

- `filemtime()` **只到秒**：一秒内改两次，第二次看不到；
- 改一个**等长的值**（`price 100` → `200`），连文件大小都不变。

两个维度同时失效，结果就是"策划说改了但没生效"，而日志里一次变更都没有。

T5-02 的服务发现踩过一模一样的坑：心跳用 mtime 判活，判死时间在 2~3 秒之间随机。
那次之后定下的规矩是——**判"内容有没有变"要看内容，不要看时间戳**。

所以导出端顺手算 crc32，写一份 `manifest.txt`：

```
# tsf config manifest v1
item.bin 450 1865766726
monster.bin 230 4198351356
```

监听端每 30 ms 只读这几十个字节做字符串比对（`file_get_contents` + 一次 `===`）。
crc 的计算成本由导出端付，**不在热路径上**。

### 20.2 清单写入必须原子，顺序也要对

导出端先写 `manifest.tmp` 再 `rename`。rename 在同一文件系统上是原子的，所以监听端
要么看到旧清单、要么看到新清单，不会读到半截。

顺序也重要：**先写完所有 `.bin`，最后才改清单**。监听端看到新清单时，数据一定已经就位。
反过来的话，监听端会在 `.bin` 还写到一半时就去读。

这个设计顺带省掉了一个东西：不需要"等文件稳定"的延迟（很多热更实现会等 100~200 ms）。
省下的时间直接体现在生效时间上。

### 20.3 按表重载，而不是整批

清单里每个文件单独带 crc，所以能精确算出**哪几张表变了**，只重载那几张：

```php
Tables::reload(["item.bin"]);   // 只重读 item
```

一万行的表读一次 60 ms 以上。改一张 4 行的小表却把所有大表重读一遍，是白花的时间。

而启动时的 `Tables::load()` 是**全量且原子**的：先把所有表读进局部变量，全部成功后再
一次性赋值。中途任何一张报错都不动现有数据。

### 20.4 坏数据不能影响线上

重载抛异常时**不动现有表**，只记错误：

```
stats: reloads=1 failures=1 last_ms=0.11 changed=[item.bin]
       err=配置表 [item] 字段类型越界（build/config/item.bin）
```

策划存了一张坏表，线上不该跟着崩——旧数据继续用，日志里留一条。

注意**失败后不重试**：导出端没修好之前，每 30 ms 重试一次只会刷屏。用户重新导出时
清单内容会再变一次，自然就重试了。

### 20.5 实测

分工要摆在明面上，因为这两段性质完全不同：

| 阶段 | 耗时 | 算热更吗 |
|:--|--:|:--|
| A. 导出（Excel → .bin） | **37 ms** | 不算，是离线工具步骤 |
| B. 落地 → 内存生效（小表） | **34 ms** | ✅ 这才是热更 |

B 的 34 ms = 轮询 30 ms + 重载 0.11 ms。验收线是 100 ms。

**但大表顶破了。** 1 万行 × 7 列的 `big.bin`（764 KB）连测三次：

```
第 1 次：落地→生效 106 ms
第 2 次：落地→生效 71 ms
第 3 次：落地→生效 84 ms
```

原因很直接：重载本身要 63~65 ms（6.4 µs/行），加上 30 ms 轮询，最坏就过百了。

### 20.6 一次没用的优化（值得记）

我以为是解析字节太慢，把 `int()/str()/bool()` 里所有辅助方法调用和属性读全内联了
（每列少两次函数调用 + 一次属性读，1 万行 × 7 列就是 7 万次）。**一点没快**：

| 版本 | 1 万行加载 |
|:--|--:|
| 内联前 | 63 ms |
| 内联后 | 65 ms |

内联没收益，说明瓶颈不在调用开销，而在**分配**：1 万行要建 1 万个对象、1 万个 `int[]` 数组、
2 万个字符串，总共 4 万次分配。按 63 ms 摊下去每次分配 1.6 µs——这才是钱花的地方。

既然没收益，就把内联回退了：多出来的三份字节运算逻辑不值得维护。

想再快只有改数据模型（字符串留在同一个缓冲区里用偏移访问、行对象按需构造），
那会牺牲整个设计的可读性。**当前的取舍是选择不优化。**

### 20.7 验收口径怎么定

"改 Excel <100 ms 生效"这句话在万行表上不成立：37 ms 导出 + 65 ms 重载 + 轮询 ≈ 110~140 ms。

所以要么改口径，要么改实现：

| 方案 | 代价 |
|:--|:--|
| 口径改成"**数据落地 → 生效** ≤100 ms" | 导出是离线步骤，生产上 bin 由 CI 产出，游戏服根本不跑 Excel |
| 口径改成"单表 ≤ 5000 行时 ≤100 ms" | 简单，但等于承认大表不行 |
| 降低重载成本 | 要改数据模型，牺牲可读性（§20.6） |

我倾向第一条：**热更是运行时行为，验收就该量运行时**。导出耗时单独记账（37 ms），
它属于工具链而不是服务。T6-06（`tsf watch`）的"改一行 ≤3 s"里，编译时间是要算的——
但那是因为编译本来就发生在开发机上，和这里的性质不同。

`bench/config-hot`（小表，4 项 PASS）+ `bench/config-bench hot.sh`（万行表）。

---

## 二十一、M6 工具链：协议生成（T6-03）

```bash
tsf proto build <工程>   # proto/*.proto → gen/Proto/*.php
```

### 21.1 为什么不引 protobuf 库

项目里没有 protobuf 运行时（`vendor/` 只有 swoole）。而 `Google\Protobuf\Internal`
大量依赖魔术方法和动态属性，AOT 基本过不去。

更要紧的是：**wire format 本身是公开且稳定的**，自己实现的成本远低于引一个依赖。
所以生成的是**自包含**的编解码——`TSF\Proto\Wire` 编、`TSF\Proto\Reader` 解，
一个字节都不依赖外部库。

### 21.2 生成的是"标准 protobuf 字节"，不是自家格式

这一点决定了前端要不要再生成一套代码。

因为字节就是标准 protobuf，**前端可以直接用 protobuf.js 读同一份 `.proto`**，
我们不需要再生成 TypeScript。如果换成自定义格式，就得自己维护两套编解码，
而且一旦两边不一致，排查成本极高（抓包只能看到"乱码"）。

用 protobuf 官方文档里的规范样例来钉这件事（`bench/proto-test` 第 1 组）：

| 用例 | 期望字节 |
|:--|:--|
| `field 1, varint 150` | `08 96 01` |
| `field 2, string "testing"` | `12 07 74 65 73 74 69 6e 67` |
| `field 6, packed [3,270,86942]` | `32 06 03 8E 02 9E A7 05` |
| `int32 -1` | `18 FF×9 01`（**10 字节**，不是 1 字节） |
| `double 1.0 + float 1.5` | `21 00×6 F0 3F` + `2D 00 00 C0 3F` |

第四条最容易写错：proto3 规定 `int32` 按 64 位符号扩展编码，所以负数一律 10 字节。
想省空间要用 `sint32`（zigzag）——两者语义不同，混用会让前端读出天文数字。

### 21.3 IDL 是 proto3 的**子集**，不支持就报错

支持：

```proto
syntax = "proto3";

// @id 1011
message Bag {
  string owner = 1;
  repeated Item items = 2;
  Quality quality = 3;
}

enum Quality { COMMON = 0; RARE = 1; }
```

不支持（**遇到就报错并给行号**，而不是悄悄忽略）：`map`、`oneof`、嵌套 message、
`service`、`import`、`extend`、`group`、`sfixed32/sfixed64`。

`sfixed32` 特意单独报错而不是当 `fixed32` 处理：**无符号解出来的值是错的**，
宁可不支持，也不要静默算错。

悄悄忽略比不支持糟糕得多——用户会纳闷"我明明写了 map 怎么没生效"，然后去查生成器。

### 21.4 `// @id` 是注释指令

proto 本身没有"消息号"的概念。用注释承载：

```proto
// @id 1001
message LoginReq { ... }
```

选注释而不是自定义 `option`，是因为**注释会被所有 protobuf 工具忽略**，
这份 `.proto` 仍然能被 protoc / protobuf.js 直接使用。换成 `option (msg_id) = 1001;`
就需要 protoc 扩展，前端也要配插件。

### 21.5 生成物：三次写作里踩出来的三条

生成的每个消息类长这样（`Item`）：

```php
final class Item extends Message
{
    public int $id = 0;
    public int $count = 0;

    public function msgId(): int { return 1010; }

    public function encode(): string
    {
        $b = "";
        if ($this->id !== 0)    { $b .= Wire::tag(1, 0) . Wire::varint($this->id); }
        if ($this->count !== 0) { $b .= Wire::tag(2, 0) . Wire::varint($this->count); }
        return $b;
    }

    public static function decode(string $buf): Item
    {
        $m = new Item();
        $r = new Reader($buf, "Item");
        while ($r->next()) {
            $f = $r->field();
            if ($f === 1)      { $m->id = $r->int32(); }
            elseif ($f === 2)  { $m->count = $r->int32(); }
            else               { $r->skip(); }
        }
        return $m;
    }
}
```

**1. `else { $r->skip(); }` 不能少。** 少了它，不认识的字段不会推进游标，
下一轮 `next()` 读到的是字段体，整个消息从此解歪——而现象只是"某些值不对"。
前后端版本不一致是常态（客户端发版不可控），这一句是协议能演进的前提。

**2. 默认值不序列化。** `int32 a = 1` 当值是 0 时一个字节都不写。这是 proto3 语义，
不遵守的话和标准实现互通时会出"对方觉得我没设这个字段"。

**3. 累加器变量名不能拼接。** 第一版写 `"\$p" . $vt`，而 `$vt` 本身以 `$` 开头，
生成了 `$p$v_zig` 这种非法变量名。生成器拼字符串时这类错误编译器不报，
要到 `tsf build` 才炸——所以生成完先跑一次编译是必须的。

### 21.6 顺带纠正一个旧假设：pack 在 AOT 下可用

`Cluster\Packet` 里写着"AOT 下对 pack 的支持面不确定，所以用 chr/ord"。
那是**保守选择，不是实测结论**。这次要编 float/double，手写 IEEE 754 才是真风险，
所以先做了个探针（`bench/probe-pack`）：

```
pack(g, 1.5) = 0000C03F（期望 0000C03F）
unpack(g) = 1.5
double 往返 = OK
边界值往返（-0.0 / -1.25 / 1e-40 / 0.0）OK
pack(P) 往返 = 1234567890123
结果: 5 通过 / 0 失败
```

**pack/unpack 的 g / e / V / P 在 AOT 下完全可用且正确。** 所以 `Proto\Wire` 直接用它编
float/double。`Packet.php` 的注释也顺手改成了实测结论——否则那句"不确定"会继续误导下一个人
（它已经误导了我一次：差点去手写 IEEE 754）。

### 21.7 报错要一次报全

解析阶段（语法）和生成前（语义：未知类型、协议号冲突、字段号重复）是两拨校验。
第一版在解析出错时就 `exit`，于是用户得先修语法、再跑一次才看到类型错误，改一轮跑一轮。

现在两拨合并成一张清单（`bench/proto-test` 用一个故意写错 8 处的文件验证）：

```
✗ 发现 8 处问题，未生成任何文件：
  bad.proto：消息 [NoId] 缺少协议号：在 message 上方加一行 `// @id 1001`
  bad.proto：第 13 行：不支持 map（用 repeated 的键值对消息代替）
  bad.proto：第 18 行：不支持 oneof（改成两个可选字段并自行约定）
  bad.proto：消息 [DupNum] 字段号 1 重复
  bad.proto：第 47 行：不支持嵌套 message（拍平，换个名字）
  协议号 2003 被 BadType 和 DupId 同时使用
  消息 [BadType] 字段 [b] 的类型 [MyUnknown] 不认识
  消息 [SFixed] 字段 [v] 不支持 sfixed32（有符号定点请用 sint32/sint64）
```

而且**一个文件都不生成**——和配置表同样的原则：不要留半成品给下一环。

### 21.8 实测

`bench/proto-test`：8 个消息 / 1 个枚举 → 11 个 PHP 文件（15 KB），
**59 项断言全过**，且这些断言跑在 AOT 编译出来的二进制里。

覆盖：规范样例字节、默认值不序列化、全类型往返（含 int64 大数 / 负 sint / 中文 /
含 `0x00` 的 bytes / 大 double）、未知字段跳三种位置、wire type 不匹配报错、
截断报错、packed 与非 packed 混发、嵌套消息、枚举、协议号派发。

---

## 二十二、M6 工具链：指标与慢调用（T6-04）

```php
Metrics::inc("packet.in");
Metrics::gauge("room.active", 128);
Metrics::watch("battle.tick", 5000);            // 慢阈值 5 ms
$t0 = Metrics::now();
// ... 干活 ...
Metrics::done("battle.tick", $t0, "room=1234"); // 记一次；超阈值打日志
```

### 22.1 不做 HTTP 导出端点

这个框架跑在自己的游戏服上，不在 K8s 里。指标落到日志、由 filebeat/vector 采集，
已经够回答"出问题时能查到"。

为它开一个 HTTP 端口意味着多一个攻击面、多一份配置、多一个会挂的东西，而收益只是
"能在 Grafana 里看曲线"。真要接 Prometheus，`snapshot()` 已经是结构化数据
（`MetricSnapshot[]`），写导出器是几十行——那应该是**需要的时候再加**，不该默认背着。

### 22.2 分桶：误差恒定，不随量级变化

每个八度（2 的幂）切 4 个桶，1 µs 到约 71 分钟，共 128 个桶：

| 真值 | 落桶 | 上报 |
|--:|--:|--:|
| 1 µs | 0 | 1.19 µs |
| 1000 µs | 39 | 1124 µs |
| 1 s | 79 | 1.08 s |

**误差恒为 ≤19%，和量级无关。** 这是指数分桶相对线性分桶的关键优势：线性分桶在
1 ms 附近可能很细，但 10 秒的毛刺会被归进一个巨大的桶里，报出来毫无意义。

代价是 p99 报的是**桶上界**（不是精确值）。监控不需要精确值，需要"有没有变坏"——
19% 的误差对判断毫无影响。

还有个细节：样本很少时（比如只有 3 个样本却要 p99），返回**最大值**而不是 0。
返回 0 会让人以为"很快"，实际是"样本不够"。

### 22.3 慢日志必须限流（这条是安全问题，不是优化）

一个卡顿会让几百个请求同时超过阈值。如果每笔都写一行，日志几秒内能涨到几百 MB——
而**日志 IO 又会让本来就在卡的那条路径更卡**，正反馈直接把服务打死。

所以每个指标默认 **1 秒最多一条**，被压掉的条数记在 `suppressed` 里，下一条日志带上它：

```json
{"lvl":"warn","msg":"slow: battle.tick","ctx":{"metric":"battle.tick","us":9000,"limit_us":5000,"suppressed":49}}
```

`suppressed: 49` 这一项是排障的关键：它告诉你"这里其实卡了 50 次，你只看到 1 条"。
没有它，限流就成了掩盖问题的帮凶。

限流**只影响日志，不影响样本**——50 次慢调用全都会进直方图，p99 照样反映真实情况。

### 22.4 累积还是按窗口

`Reporter` 默认**累积**（不清零）。累积的 p99 会随时间变钝：跑了六小时的 p99 看不出
最近五分钟的退化。所以留了 `new Reporter(60000, true)`——每条日志后清零，
看到的就是"上一个周期"的数字，排障时更有用。代价是丢总量。

### 22.5 实测：埋点有多便宜

```
inc:     81 ns/次
observe: 390 ns/次
log():    2 ns/次
```

一帧埋 100 次 = 39 µs，占 16.7 ms 帧预算的 **0.2%**。可以放心埋。

**这里有个被实测推翻的猜测。** 我原本以为 390 ns 主要是 `log()`（算桶下标要用它），
甚至准备写"如果嫌慢可以改成读 IEEE754 指数位"。量了一下：**`log()` 只要 2 ns**。
成本摊在"一次字符串键查找 + 一次方法调用 + 三次小数组操作"上，没有单一热点。

想再压只能把指标句柄缓存起来（`$h = Metrics::histogram("x")` 然后 `$h->record()`），
收益约 2~3 倍，但调用方要多管一个句柄。现在这样够用，不值得。

这类"以为某处贵、量了发现是别处"的判断，如果不实测就写进文档，下一个人会顺着
错误的前提去做优化——`§21.6` 那次 pack 的教训一样。

### 22.6 输出长什么样

```
metrics: 4 个
NAME                     KIND        COUNT       MEAN        P50        P90        P99        MAX
battle.tick              hist          600      1.8ms      1.6ms      2.4ms      3.4ms      9.1ms
packets.in               counter         -      1.23M          -          -          -          -
room.active              gauge           -         88          -          -          -        150
rpc.echo                 hist         1200      220µs      238µs      238µs      238µs      245µs
```

表头用英文而不是中文：中文列名按字节补空格会对不齐（一个汉字 3 字节），
而对齐是这张表存在的意义——它要能被人一眼扫出异常。

`bench/metrics-test` 37 项。

---

## 二十三、M6 工具链：GM 与后台 API（T6-05）

```php
Registry::on("player.ban", function (array $args): Result {
    $uid = (int) ($args["uid"] ?? 0);
    if ($uid <= 0) { return Result::fail("uid 必填"); }
    return Result::ok("{\"uid\":" . $uid . ",\"banned\":true}");
}, "封禁玩家");

Scheduler::instance()->spawnOf(function (): Server {
    return new Server("127.0.0.1", 9701, $token);
});
```

```bash
curl -s -X POST http://127.0.0.1:9701/gm \
     -H "X-GM-Token: $TOKEN" \
     -d '{"cmd":"player.ban","args":{"uid":1001,"reason":"外挂"}}'
# {"ok":true,"data":{"uid":1001,"banned":true}}
```

### 23.1 为什么是独立通道，不是复用游戏协议

游戏的 `Transport` 走 Swoole（自己的进程模型和事件循环），而 GM 指令**必须能改游戏状态**
——它得跑在 `Scheduler` 这同一个事件循环里。

复用游戏协议的话，要么跨进程再发一次 RPC（还得判断玩家在哪个 shard、目标节点是谁），
要么把管理消息和玩家消息混在一个消息号空间里（谁都能试着发一条 `player.ban`）。
所以另开一个端口，代价是多一个监听，收益是**管理面和游戏面彻底分开**：
游戏协议怎么改都影响不到 GM，GM 通道挂了也不影响玩家。

实现上照 `Cluster` 的方式用裸 socket（`stream_socket_server` + tick 里 poll），
不引 Swoole——两套事件循环混在一起是自找麻烦。

### 23.2 四条"默认安全"，不是"记得配"

| 约束 | 为什么 |
|:--|:--|
| **token 为空就拒绝启动** | 不提供"方便调试的空口令模式"。那种模式一定会被带到线上，然后变成"谁扫到这个端口谁就能发奖" |
| **默认只绑 127.0.0.1** | 要对外必须显式改 host，改的时候会撞见那行注释 |
| **token 走请求头，不走 URL** | URL 会进访问日志、浏览器历史、`ps` 的输出（`curl ...?token=x` 在 ps 里是明文） |
| **鉴权失败限流** | 否则这就是个可以无限猜的口令框。同 IP 一分钟失败 10 次 → 429，并记一条 warn |

第一条尤其值得说：**"方便"是安全配置最常见的杀手**。给一个 `--no-auth` 开关，
半年后它一定出现在某个生产启动脚本里。

### 23.3 token 比较不能提前返回

```php
$diff = 0;
for ($i = 0; $i < $n; $i++) {
    $diff |= ord($given[$i]) ^ ord($want[$i]);
}
return $diff === 0;
```

`===` 对字符串是"先比长度、命中第一个不同字节就返回"，理论上可以被按字节爆破。
异或累加消除了提前返回。长度本身仍会泄漏（不同长度直接 false），但那只泄漏长度。

响应里也**不区分**"没带 token"和"token 不对"——少给爆破者一点信息。

### 23.4 审计日志由框架打，不由 handler 打

让每个 handler 自己记审计，一定有人忘。所以 `Registry::dispatch()` 在调用前后统一记：

```
{"lvl":"info","msg":"gm: player.ban","ctx":{
  "peer":"127.0.0.1:53677","cmd":"player.ban",
  "args":"uid=1001&reason=外挂","ok":true,"us":5}}
```

- **失败也记**（带 `err`）——"谁试过但没成功"有时比成功的记录更重要；
- **参数截断到 512 字节**，防止一个大参数把日志撑爆；
- 审计用 `info` 级别：它必须**默认可见**。用 `warn` 会被真正的告警淹没，
  而审计的价值恰恰在于"事后按 uid 搜一条操作记录"。

handler 抛异常时：细节进日志，响应里只给一句"指令执行失败：xxx"，
**不回堆栈和内部路径**。

### 23.5 迷你 HTTP 的边界（要说清楚）

只支持 `POST /gm` + `GET /health`，一律 `Connection: close`，
不解析 chunked、不支持 keep-alive、不支持 HTTP/2。

管理操作是低频的人手操作（一天几十次），完整 HTTP 带来的复杂度（状态机、分块、超时）
没有收益。**但它不适合直接挂在公网流量上**——要那样用，前面应该放 nginx。

短写会被记进 `short_write` 计数：非阻塞 socket 上写小响应基本不会短写，
但短写必须记下来，否则运维看到的是"curl 卡住"而不是"服务端有问题"。

### 23.6 一个真 bug：`json_decode($body, true)` 分不清 `[]` 和 `{}`

两者都解成数组。于是 `[1,2,3]` 会被当成"没有 cmd 的合法请求"，回 200 + `ok:false`
——发数组过来明明是协议错误，应该是 400。

修法不看解码结果，看**原文**：

```php
$trimmed = ltrim($body);
if ($trimmed === "" || $trimmed[0] !== "{") { → 400 }
```

顺带的好处是错误信息能直接给出正确形状（`{"cmd":"x"}`），比"body 不是 JSON"有用。

### 23.7 实测

- 进程内 **39 项**（路由、鉴权、派发、畸形请求、审计日志内容、爆破限流）
- **真 curl 5 项**：进程内测试用的是我自己写的客户端，框架对不对得让严格客户端说了算。
  特别是**中文参数**——`Content-Length` 必须按字节算，按字符算会截断：

```
GET  /health              → 200 {"ok":true,"data":{"up":true}}
POST /gm 无 token         → 401 {"ok":false,"error":"鉴权失败"}
POST /gm ping             → 200 {"ok":true,"data":{"pong":true}}
POST /gm player.ban       → 200 {"ok":true,"data":{"uid":1001,"banned":true,"reason":"外挂"}}
POST /gm 缺 reason        → 200 {"ok":false,"error":"reason 必填（封禁必须写理由）"}
POST /gm 中文参数          → 200 {"ok":true,"data":{"uid":2,"banned":true,"reason":"刷金"}}
```

`bench/gm-test`（`./run.sh` 跑进程内 + curl 两轮）。

---

## 二十四、M6 工具链：`tsf watch`（T6-06）

```bash
tsf watch <工程>
```

改一行代码 → 自动重编重启。**这一项没达到 3 秒验收线（实测 4.4~4.6 秒），
原因不在 watcher，在编译器。下面是完整证据。**

### 24.1 watcher 该做的部分：做得对，也够快

原来的 `watch`（M0 时期写的）有三个问题，全都改掉了：

| 原来的问题 | 后果 |
|:--|:--|
| 用 `filemtime()`（**秒级**）判变化 | 一秒内保存两次、或改一个等长字符，都发现不了 |
| 只盯 `main.php` + `src/*.php`（**顶层**） | 改子目录、改 `gen/`、改包源码，全都不触发 |
| 直接 `SIGKILL` 子进程 | 跳过 `onStop`（日志没刷、快照没落盘） |

现在的做法：

- **按内容算指纹**（`crc32` 链），不看时间戳。这个坑 T5-02（服务发现）和
  T6-02（配置热更）各踩过一次，这次直接用内容判据。
- **监听集合从 `project.yml` 的 sources 读**。那就是构建输入本身——自己另写一份
  "该监听哪些目录"一定会漂（加了 `gen/` 或新包就漏）。顺带好处：**改框架源码
  也会触发重编**，框架开发同样受益。
- **先 SIGTERM 等 2 秒再 SIGKILL**。开发期也要给优雅退出留机会，否则"退出逻辑对不对"
  永远测不出来。
- 编译失败**不重启**（旧二进制跑着会让人误以为改动生效了）；改好后自动恢复。
- 产物自己退出时**报一次**而不自动重启（一启动就崩的程序会刷屏，还掩盖真错误）。
- `Ctrl+C` 顺手收掉子进程，否则孤儿进程占着端口，下次 watch 莫名启动失败。

实测检测延迟 **205 ms**（轮询 200 ms + 防抖 120 ms）。

### 24.2 修之前的拆解：编译 3.5 秒

拆开量（改**一个字符**之后重建）：

| 阶段 | 耗时 | 谁的责任 |
|:--|--:|:--|
| 检测改动 | **205 ms** | watcher |
| 编译 | **3549 ms** | tpc |
| 链接 | **460 ms** | tpc |
| 合计 | **~4.4 s** | 验收线 3000 ms，**超 45%** |

四个候选杠杆，全部实测否掉：

| 杠杆 | 结果 |
|:--|:--|
| 降优化等级 `-O0` | 4.77 s（**更慢**，因为它让 86 MB 的 PCH 失效重建）；`-O1` 3.38 s、`-O2` 4.26 s |
| 提并行度 `-j 4/8/16` | 3.97 / 3.98 / 3.97 s——**完全一样** |
| 换更快的链接器（lld/mold） | 链接只占 460 ms，全免了也只省 0.46 s；而且环境里没装 |
| 减少防抖/轮询 | 顶多省 0.2 s，杯水车薪 |

为什么并行度没用：改一个文件**只会重编两个 TU**——`codec.cc`（约 160 ms）和聚合
单元 `extension-<工程>.cc`（2.6 s）。并行没有东西可并行，关键路径就是那一个
157 KB 的聚合单元。

这一项我一开始判断错了三次，全部靠实测纠正（记在这里，免得下一个人重走）：

| 曾经的判断 | 实测 |
|:--|:--|
| 「整个工程只有一个翻译单元」 | ❌ 63 个（每个类一个）。改一个文件只重编 2 个 |
| 「3.5 s 是框架头解析 + 模板实例化」 | ❌ 头解析几乎免费：include 0 / 10 / 30 / 60 / 100 / 121 个都是 **102~115 ms** |
| 「需要给 tpc 加 PCH」 | ❌ **tpc 早就有 PCH**：`-include build/cache/pch/<hash>/typephp_pch.hpp`，86 MB 的 `.gch`，装载 **127 ms** |

真正花时间的是那个聚合单元**本身**：157 KB 生成代码在 `-O2` 下 2.6~3.3 s
（同内容 `-O0` 1.78 s）。单类 TU 也不便宜：`Histogram.cc`（11 KB）492 ms、
`Scheduler.cc`（73 KB）1883 ms。

### 24.3 真正的根因：arginfo 的 stub hash 把"函数体"也算进了接口定义

对象缓存本身是对的——`Translator::hasGeneratedObjectFileCache()` 是**内容寻址**的，
无改动构建确实一次编译都不做（724 ms）。问题是**改一行方法体之后它必然失效**：

1. 改任何类的 PHP 源 → tpc 重跑 `gen_stub`，且传的是 `forceRegeneration = true`；
2. `gen_stub.php:139`：`$stubHash = sha1(整个 PHP 源文件)` —— 注释、函数体、空行全算进去；
3. 于是 `<类>_arginfo.h` 第 2 行的 `Stub hash` 变了。实测改一个方法体，整个文件的差异只有这一行：

```
2c2
<  * Stub hash: 163f83707021fe5da03ac55414f68b4211856fe6 */
---
>  * Stub hash: 41b04b97f43079e3ee9000541bed69b97f42b548 */
```

4. 聚合单元 include 了 38 个 `_decl.h` + 69 个 `_arginfo.h`，它的缓存键是这些头的
   **内容哈希** → 键变 → 重编 2.6 s。

也就是说：**改一个函数体，arginfo 的语义内容一个字没变，却把整个工程的聚合单元重编了一遍。**

同一个坑在这个项目里已经是第四次——T5-02 服务发现、T6-02 配置热更、T6-06 watcher，
全是"拿不属于内容的东西当变更依据"。

#### 已修（方案 A）

改成"先生成到临时文件，只在**除 hash 行外**确有差异时才替换正式文件"。
arginfo 的字节于是保持稳定，内容寻址的缓存键不再假失效。

用临时文件而不是"写完再恢复原内容"，是因为后者会改 mtime，而另一条编译路径
（`NativeSourceProjectBuilder::needsCompile`）是按 depfile 里的 mtime 判定的。

没选方案 B（把 hash 改成「渲染出的 arginfo 正文」的 sha1）：那要动 PHP 上游
`gen_stub` 的约定，而方案 A 效果相同、只碰 tpc 的调用点。

实测（同一套 g++ 壳计时，`bench/watch-test`）：

| 场景 | 修前 | 修后 |
|:--|--:|--:|
| **改一个字符（端到端）** | 4.4~4.6 s ❌ | **1.45 s ✅**（检测 211 + 编译重启 1236） |
| 只改注释 | 聚合单元重编 2.6 s | **一次编译都不做**（1307 ms 全是 tpc 自己） |
| 改方法体（真实语句） | 聚合单元 + 类 TU | **只重编该类 TU**（443 ms） |
| 改签名 | 聚合单元 + 依赖它的类 | 一样重编（应该的：参数真的变了） |
| 同秒内改两次 | — | 1.72 s ✅ |
| `demo/duel`（9 个包）改一行 | ~4 s | **1.85 s** |

验收脚本 `bench/watch-test/run.sh` 从"如实失败"变成三项全 PASS。

因为改动在 `~/typephp-src`（不在本仓库、也不是 git 仓库），补丁的来龙去脉与
重新应用的方法记在 **`tpc-patches/README.md`**——**升级 tpc 后要重新应用**，
否则 watch 会退回 4.4 s。

顺带清掉的一处白烧：`tsf/ext/codec/cpp-src/codec.cc` 原本**每次构建都重编**（约 160 ms），
连无改动构建也编——它既不算 phpx 目录里的 misc 源、也不算生成物，三条对象缓存都不覆盖它。
现在给它补了一条"编译命令 + 源文件内容摘要 + depfile 依赖 mtime"的缓存：

| | 修前 | 修后 |
|:--|--:|--:|
| 无改动构建 | 735 ms | **331 ms** |
| 改一个方法体（同场景 A/B） | 1594 ms | **1469 ms** |
| `touch codec.cc`（内容没变） | 重编 | **跳过** |
| 改内容 / touch 它 include 的头 | 重编 | 重编（正确） |

关键取舍是**不猜 include**：第一版扫 `#include` 逐个头找目录，但 `<sys/types.h>`
这种带斜杠的系统头和第三方 `mylib/foo.h` 分不出来，猜错就是静默用旧对象。
改成编译时让编译器产出 depfile（`-MD -MF`），依赖清单由它给；没有 depfile 就不缓存。

还有个副作用值得记住：**别随手改 `-O` 等级**。PCH 是按编译参数算指纹的
（`build/cache/pch/<sha1>/`，现在躺着 7 个 83 MB 目录），`-O2` → `-O0` 会让 86 MB 的
PCH 失效重建。所以那次 `-O0` 端到端反而更慢（4.77 s）——它量到的不是"优化等级的代价"，
是"PCH 废掉的代价"。

### 24.4 这个结果应该怎么处理

不该做的是把验收线改成 5 秒然后宣布达标——那等于把"我不打算解决"伪装成"验收通过"。
更不该做的是给 watcher 加一堆延迟补偿的补丁。

当时的处理是：watcher 照常交付、**端到端如实报 4.4~4.6 s 并标注未达标**、
回归脚本如实失败（它跑的就是 3000 ms 这条线），失败信息里带 breakdown。

**这条坚持后来直接换来了答案**：因为量到的是真数字而不是"调过线的数字"，
才看得清 3.5 s 全压在同一个 TU 上，进而查出 arginfo 的哈希口径。
现在端到端 1.45 s，脚本三项全 PASS。

`bench/watch-test`，`./run.sh`。

---

## 二十五、事件日志接进真实业务：金币结算

§12.9 验的是 `pkg/eventlog` 本身（1,000 笔 kill -9 零丢失），但它一直没接进业务。
这一节把它接到 `demo/duel` 上，做的不是"把对局记录搬进日志"——那是错的用法——
而是**钱**：每局给赢家发 100 金币。

### 25.1 为什么不用 db

| | db | 事件日志 |
|:--|:--|:--|
| 存什么 | **当前状态**（这局谁赢、打了几帧） | **发生过的事**（谁该收多少钱） |
| 丢了怎么办 | 补一条，或者这局不算 | **账不平，补不回来** |
| 写失败 | 重试 / 记日志，玩家照玩 | 必须让上一层知道 |

所以对局记录进 `pkg/db`、金币进 `pkg/eventlog`。分界线就是这一句"丢了会怎样"。

### 25.2 结构：日志是真相，余额是派生的

```
Settle::settle(roomId, winner)
    id = "duel:" + roomId                  ← 业务主键
    if 已结算 → return false                ← 幂等
    log.append(id, "duel.settled", {...})   ← 真相落盘（内部 fflush）
    wallet.credit(winner, 100)              ← 改派生状态
```

`Wallet` 里只有一张 `uid => 金币` 表，**丢了随时能重建**：`Settle` 构造时
`replay()` 整份日志重新折叠一遍。所以进程被 `kill -9` 之后不需要"余额落盘"，
重启即追平。

### 25.3 四个决定

**1. 幂等锚点是业务主键，不是序号。** 用 `duel:<房间号>`。
`EventLog::append()` 写失败时**故意不回滚序号**（宁可留洞，也不让两条事件共用一个序号），
所以序号天然会有洞——拿它当幂等键，崩溃后要么漏、要么重。

**2. 顺序是"日志先行"。** 反过来（先加钱再写日志）在崩溃后会留下"钱加了、账上没有"：
要么认亏（玩家投诉），要么补写一条（伪造历史）。日志先行最坏只是"账上有、内存还没加"，
重启重放一次就追平——**没有第三种结局**。

**2.5 幂等键要求房间号永不重复——这一条踩过。** 结算 id 是 `duel:<房间号>`，而房间号
原本不持久化，`DuelManager` 每次启动从 1 数起；快照恢复只把号抬到"还在跑的房间"之上，
**已结算房间的快照早被删了，那些号就忘了**。于是重启后第一局又是 1 号 →
`Settle` 判"这个 id 结过账" → 直接跳过：**玩家赢了没拿到钱，日志里一行都不多**。

修法是让号只增不回退：`Settle` 重放时记下已结算的最大房号，`DuelManager` 从它 +1 开始。
"同一房间崩溃后重结算"仍然幂等（房号不变，id 就不变）。

这条的教训不是"要小心"，而是：**幂等键一旦取自内存计数器，那个计数器就必须从持久化的
真相里恢复**。`bench/duel-settle` 的用例 [5] 就是照着这个坑写的。

**3. `MODE_FLUSH` 下不要调 `commit()`。** `append()` 内部已经 `fflush`（抗 kill -9 够了），
再调 `commit()` 会触发 `fsync`（实测 4.3 ms/条），等于把结算排成串行磁盘。
要抗掉电就用 `MODE_SYNC`，那时 `append()` 自己会 fsync。

**4. 结算失败要抛，落库失败可以吞。** 同一段代码里两个相邻调用，态度故意相反：
`recordMatch()` 吞异常（丢一条战绩而已），`settleMatch()` 不吞（钱不能静默降级）。

**这条选择的代价要说清楚**：`Scheduler` 对"服务抛异常"的处理是「记 error → `kill($addr)`」，
所以事件日志写失败（磁盘满、句柄失效、目录只读）会连锁成：

1. 房间服务被销毁，玩家**直接掉线**（不走正常的 `MSG_CLOSE` 收摊）；
2. 这一局**战绩也不会写**——`recordMatch()` 排在 `settleMatch()` 之后；
3. 已经播出去的 `RESULT` 里那个赢家拿不到钱。

一次磁盘抖动换掉一局玩家的体验，这是"钱优先"的必然结果。要更温和就在调用处 catch、
记 error、广播失败、再正常收摊——但那属于产品决策，不该由工具悄悄替业务定。

### 25.4 实测

三个 bench 各管一段，合起来才是完整的证明：

| bench | 项数 | 验什么 |
|:--|--:|:--|
| `settle-test` | **42** | 语义：一局只发一次、重复/换赢家都不多发、平局与非法参数不写事件、重启重建、重建两次不翻倍、半截行、同 id 重复只算一次、日志先行 |
| `duel-settle` | **23** | **接线**：真跑 `DuelService`（走 Scheduler spawn，和线上同一条路径），认输判负 → 推一帧 → 赢家到账；多推几帧不重复；两个房间互不影响；平局不发奖；重启后日志能读回；**重启后房号不复用已结算的号**（用例 [5]，照 P0-2 那个坑写的） |
| `settle-crash` | **10** | 崩溃：随机 `kill -9` 之后账不多不少 |

接线那一层不能省：`settle-test` / `settle-crash` 都绕过了 `DuelService`，
而接线恰恰是最容易出错的地方（参数漏传、结算挂在哪个分支、`$reported` 之后还会不会重入）。

崩溃那 10 项的实际输出：

```
PASS 干净跑完           events=50    balance=5000     sum=5000     gaps=0 dup=0
PASS 重放不重复发钱（newly_paid=0）
PASS 第 1 轮被杀（605 局）   events=605   balance=60500
PASS 第 2 轮被杀（1061 局）  events=1061  balance=106100
PASS 第 3 轮被杀（779 局）   events=779   balance=77900
PASS 第 4 轮被杀（597 局）   events=597   balance=59700
PASS 第 5 轮被杀（1536 局）  events=1536  balance=153600
PASS 续跑后账目一致      events=100000 balance=10000000
```

每一轮都验三条：`balance == sum`（不多不少）、`gaps == 0`（房间号 1..K 连续，
说明只从尾部被截、中间没丢）、`dup == 0`（没有重复 id）。处决时机随机（597~1536 局之间），
账始终对得上。

### 25.5 没做的事

- **只做了发奖，没做扣钱**。扣钱要处理"余额不足""并发扣同一个人"，不是加个负号那么简单。
- **余额只在内存**。真实项目要定期把余额快照下来对账；现在每次启动全量重放，
  10 万条约 0.4 s，日志再大就会顶到启动时间。
- **没有 checkpoint / truncate**。`EventLog::truncate()` 已经有了，但"截到哪儿安全"
  必须配合余额快照，没接。

顺带给 `project.json` 加了可选的 `sources`（相对工程目录的额外源码目录）：
bench 要直接验证 `demo/duel/src` 里的 `Settle` / `Wallet`——复制一份去测，测的就成了副本。

---

## 二十六、下一步

**M3 六项、M4 五项、M5 六项全部收口；M6 六项全部交付并全部达标**（T6-06 修完
arginfo 哈希口径后从 4.4 s 降到 1.45 s，见 §24.3）；事件日志已接进真实业务
（金币结算，§25）；迁移也命令化了（`tsf migrate`，部署跑一次、启动只校验，§12.10）。
剩下的是：

1. **余额对账与日志截断**：`Wallet` 现在全靠启动时全量重放（10 万条 0.4 s）。
   日志再长就得配"余额快照 + `truncate()`"，否则启动时间会一直涨（§25.5）。
2. **扣钱**：现在只有发奖。扣钱要处理"余额不足""并发扣同一个人"，
   不是加个负号那么简单（§25.5）。

五块被实测否掉、不用再排期的旧计划：

- 「AOI 整帧 1000 人 p99 ≤50 µs」——物理下限约 200 µs（§10.6）；
- 「快照热增量」——1 万实体的全量仍在预算内（§12.7）；
- 「恢复限流」——60 房 1.68 ms，比验收线快 300 倍（§12.8）；
- 「RPC 自动重试」——幂等性只有业务知道，框架替它做决定会出事（§13.5）；
- 「熔断后重路由」——玩家数据在故障节点上，转过去只会把"明确失败"变成"数据是错的"（§17.5）。
