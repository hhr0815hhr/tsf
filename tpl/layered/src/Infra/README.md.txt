# src/Infra —— 外部世界

这一层放**所有会碰 IO 的代码**：数据库、缓存、文件、HTTP 客户端、时钟。

## 它和别的层的关系

- `App` 调它，它不调 `App`
- 它**不知道业务规则**。`PlayerRepo::load()` 只负责"把行读出来变成对象"，
  不负责"玩家等级不够能不能领奖"——那是 Domain 的事

## 典型文件

```
Infra/
├── Repo/           数据库读写（pkg/db）
│   ├── PlayerRepo.php
│   └── RankRepo.php
├── Cache/          redis（如果用了）
│   └── SessionStore.php
└── Clock.php       时间来源
```

## 为什么单独一层

这样 Domain 才能保持"零依赖"。如果 `PlayerRepo` 混进 Domain，
那 Domain 的单测就都要连库。

## 骨架里还没有它

示例只有 echo，不涉及持久化。第一个真实需求（存档读写）出现时在这里加，
不要为了"结构完整"先建空目录。
