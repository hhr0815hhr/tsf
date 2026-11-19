# Docker

`Dockerfile` 提供一个装好的 tsf 环境：PHP 8.5 ZTS（含 `--enable-embed`）、swoole
（`--enable-swoole-thread`）、typephp 编译器、phpx，外加 g++ 与 cmake。
省掉手工搭环境那一轮（通常 30 分钟起，还容易漏 `-dev` 包）。

## 状态：语法检查通过，完整构建被本机网络卡住

**已确认的**：

- ✅ `docker build --check` 通过（`Check complete, no warnings found`）
- ✅ 依赖地址可达：PHP 8.5.12（24,469,403 字节）、swoole v6.2.5
- ✅ 构建参数来自能跑的环境（phpx 的 `CMakeCache.txt` 里 `PHP_CONFIG`）
- ✅ Dockerfile 前 10 步都过了：APT 索引拉取成功、**109 个包全部下载完成**

**卡住的**：

- ❌ `gcc-12` 的 .deb（约 40MB）在本机容器网络下拉不动，
  连续 `Ign:` 重试后 `E: Unable to fetch some archives`

判断依据：`docker run` 里单独装 `gcc-12` 也超时（150 秒未完成），
所以**不是构建方式或 Dockerfile 的问题**，是本机到 Debian 镜像的线路对 40MB 文件不友好。

## 构建时的两个网络坑（实测）

这个镜像要拉约 200MB 的 deb + 24MB PHP 源码 + swoole + 42MB 工具链，
网络不行的环境下失败是常态。两条经验：

### 1. BuildKit 走 daemon 代理，可能 502

`docker info` 里有 `HTTP Proxy: http.docker.internal:3128`（Docker Desktop 的代理）。
代理不通时 `apt-get update` 报 `502 Bad Gateway`，**而 `docker run` 里同样的命令是通的**——
因为容器运行时网络不走它。

绕开方式（用旧构建器，走容器网络）：

```bash
DOCKER_BUILDKIT=0 docker build -f docker/Dockerfile -t tsf:latest .
```

另外 Dockerfile 开头原本有 `# syntax=docker/dockerfile:1.7`，它要从 Docker Hub 拉
frontend 镜像——代理不通时连这一步都过不去，而本文件没用到 1.7 的任何特性，已删掉。

### 2. 大文件包容易失败

`apt-get` 卡在某个包的 `Ign:` 上反复重试，就是线路拉不动大文件。Dockerfile 里已经加了
`-o Acquire::Retries=5`，但仍需一条能下 40MB 的线路。国内可选：

```bash
--build-arg APT_MIRROR=mirrors.aliyun.com
```

（注意：实测在**本机**环境里容器内官方源比 WSL 侧阿里云快，
 所以别急着换源，先看是哪一段卡住。）

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
