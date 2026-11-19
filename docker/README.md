# Docker

`Dockerfile` 提供一个装好的 tsf 环境：PHP 8.5 ZTS（含 `--enable-embed`）、swoole
（`--enable-swoole-thread`）、typephp 编译器、phpx，外加 g++ 与 cmake。
省掉手工搭环境那一轮（通常 30 分钟起，还容易漏 `-dev` 包）。

## 状态：**未在本机 build 过**

写这份 Dockerfile 时，本机的 Docker daemon 没在运行
（`dockerDesktopLinuxEngine` 管道不存在，WSL 也没开集成），所以：

- ✅ 做过的：依赖版本与下载地址逐个 curl 验证（PHP 8.5.12 / swoole v6.2.5 均可达）、
  构建参数从**当前能跑的机器**上抄的（`phpx/build/CMakeCache.txt` 里的 `PHP_CONFIG`）
- ❌ 没做过的：真正的 `docker build`

首次构建预留 30 分钟（PHP 从源码编译占大头）。失败大概率出在下面三个点。

## 用法

```bash
docker build -f docker/Dockerfile -t tsf:latest .

# 环境自检
docker run --rm -v "$PWD":/app -w /app tsf:latest tsf doctor

# 编译一个工程
docker run --rm -v "$PWD/demo/battle":/app -w /app tsf:latest tsf build .

# 跑起来（记得映射端口）
docker run --rm -p 9002:9002 -v "$PWD/demo/battle":/app -w /app tsf:latest ./battle
```

## 已知的三个不确定点

| # | 点 | 现象与处理 |
|:--|:--|:--|
| 1 | **swoole.ini 落点** | PHP 8.5 的扩展配置目录可能是 `lib/php.d` 或 `etc/conf.d`。Dockerfile 用了 fallback，构建日志里能看到实际走哪条 |
| 2 | **configure 参数够不够** | 只开了框架用到的：openssl / zlib / curl / sockets / mbstring / fileinfo / sqlite3。缺某个扩展会在 `tsf build` 或运行期暴露（不是构建期） |
| 3 | **phpx 的 cmake 参数** | 只传了 `-DPHP_CONFIG` 和 `-DCMAKE_BUILD_TYPE=Release`，这是从现有 build 的 CMakeCache 抄的。若有别的必填项，cmake 阶段就会报 |

## 验证清单

```bash
# 1) swoole 装上了
docker run --rm tsf:latest php85 -m | grep swoole

# 2) 工具链齐（doctor 会逐项说缺什么）
docker run --rm tsf:latest tsf doctor

# 3) 真实工程能编过 —— 这条过了基本就能用
docker run --rm -v "$PWD/demo/battle":/app -w /app tsf:latest tsf build .
```

## 为什么 PHP 前缀必须是 `/usr/local/php-8.5-zts`

`tsf build` 生成的链接命令把 rpath 写死了：

```
-Wl,-rpath,/usr/local/php-8.5-zts/lib
-Wl,-rpath,$HOME/.tsf/toolchain/typephp/vendor/swoole/phpx/lib
```

所以镜像里 PHP 不能装到 `/opt/php` 之类的地方——编译能过，但产物运行时报
`error while loading shared libraries: libphp.so`。这也是 Dockerfile 里
`PHP_PREFIX` 写死的原因。

## 镜像里有什么

| 层 | 内容 |
|:--|:--|
| 基础 | `debian:bookworm` |
| PHP | `/usr/local/php-8.5-zts`（ZTS + embed，产出 `libphp.so`） |
| 扩展 | swoole 6.2.5（thread 模式） |
| 工具链 | `~/.tsf/toolchain/typephp`（tpc 编译器 + 源码 + vendor） |
| phpx | `~/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so` |
| 其他 | g++ / cmake / libmpfr（`tsf build` 要） |
| tsf 本体 | `/opt/tsf`，`tsf` 命令已进 PATH |

## 镜像不做的事

- **不做多阶段瘦身**：运行阶段也保留编译器和工具链。理由是一个镜像既 `tsf build`
  又能跑产物，比"两个镜像省 300MB"更实用——第一次用不用先搞懂该用哪个
- **不内置业务工程**：`WORKDIR /app` 空着，靠 `-v` 挂载
