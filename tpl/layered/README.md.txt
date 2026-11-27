# __PROJECT_NAME__

TSF 分层骨架。`tsf new --layout layered` 生成。

```
.
├── main.php            入口：只做装配，不写业务
├── config/
│   ├── tsf.json        框架配置：端口、集群、鉴权、日志
│   └── game/           业务配置（表驱动，改了不用重编译）
__RULES_TREE__
├── src/
│   ├── Protocol/       协议：消息 ID、内部消息载体
│   ├── Domain/         纯逻辑：规则、数值。不依赖 TSF，能单独测
│   ├── App/            用例编排：Service 所在层
│   ├── Infra/          外部世界：DB / Redis / 文件（骨架里还是空的）
│   └── Support/        通用工具（同上）
├── tests/
│   ├── run.php         单测入口：php85 tests/run.php
│   └── unit/           纯逻辑测试，不需要网络
└── var/                运行时产物（日志、dump），不进仓库
```

## 跑起来

```bash
tsf build .          # 编译
./__PROJECT_NAME__   # 启动（或用 tsf serve .）
php85 client.php     # 另开终端，验证 echo
```

跑单测（**不需要编译，也不需要起服务**）：

```bash
php85 tests/run.php
```

## 四条规矩

这四条是当年踩过的坑，不是风格偏好：

1. **Domain 不 import 任何东西**（连 TSF 都不行）。
   它是唯一能毫秒级单测的部分。里面一旦出现 `new Redis()`、`Scheduler::instance()`，
   规则测试就得连环境，最后没人写测试。

2. **handler 只拆参数，不写规则**。
   `main.php` 里的 `$gw->on(...)` 闭包只做"取出 fd 和内容 → 投一条内部消息"。
   规则写在 Service 里，这样同一段逻辑也能被 GM 命令、定时器复用。

3. **Service 之间发消息，不直接 `new`**。
   `new PlayerService()` 拿到的是个游离对象，它的 `$this->addr` 和调度器里的对不上，
   回包发不出去。用 `spawn("name")` 拿地址。

4. **`onMessage` 里不要写长循环**。
   实测一段 81 ms 的循环会把整个进程的定时器精度拖到 87 ms
   （所有房间一起卡）。要处理大批数据就拆成多条消息，一条一小批。

__RULES_SECTION__
## 加一个新功能

以"加一个领取奖励"为例，顺序是固定的：

1. **Protocol**：`Cmd` 里加 `CLAIM_REQ` / `CLAIM_RESP`，需要内部载荷就加一个 Task 类
2. **Domain**：写规则（`Reward::canClaim()` / `Reward::apply()`），不碰 IO
3. **Domain 测试**：`tests/unit/RewardTest.php`，`php85 tests/run.php` 跑通
4. **App**：在 Service 的 `onMessage` 里编排（调 Domain、必要时调 Infra）
5. **main.php**：只加一行 `$gw->on(...)` 转发

先写规则还是先写协议都行，但**测试要在接网络之前跑通**——接上网络之后再回来测规则，
往往就变成"手动点几下看看"。

## 这个骨架里有什么、没有什么

**有**：一个完整的 echo 链路（Protocol → App → Domain → 回包）、一条 Domain 测试、
配置目录、`.gitignore`。

**没有**：数据库、redis、集群、房间、登录。这些不是"忘了加"，是**按需再加**——
为了结构好看先建一堆空目录，最后只会让人以为那些层必须存在。

需要时看框架文档：

| 要做的事 | 看哪 |
|:--|:--|
| 存档读写、事务、迁移 | `docs/guide/04-data.md` |
| 房间 / 帧循环 / AOI / 匹配 | `docs/guide/03-gameplay.md` |
| 跨服 RPC、注册发现、分片 | `docs/guide/05-distributed.md` |
| 上线前的清单 | `docs/guide/13-production.md` |
| 为什么这么分层 | `docs/guide/14-architecture.md` |

## 一个真实规模的参考

骨架只演示"形状"。想看填满之后的实际体量，看框架仓库的
`demo/battle/`（2038 行：`BattleService` 556 + `BattleRoom` 616 + `SkillTable` 213
+ `Output` 155 + `BattlePlayer` 70 + 装配 428）。
