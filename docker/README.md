# Docker

`Dockerfile` 提供一个装好的 tsf 环境：PHP 8.5 ZTS（含 `--enable-embed`）、swoole
（`--enable-swoole-thread`）、phpredis、typephp 编译器（**含 tpc 补丁**）、phpx、composer，
外加 g++ 与 cmake。

## 状态：已验证

```bash
docker build -f docker/Dockerfile -t tsf:latest .          # 313MB，约 25 分钟

docker run --rm -v "$PWD":/app -w /app tsf:latest tsf build .
#   Successfully compiled 89 files
#   Build successful: battle  (3795 KB)

docker run --rm -v "$PWD":/app -w /app tsf:latest ./battle
#   GAME_9002_OK   GM_9701_OK   日志正常写入
```

编译和运行都实测过（用 `demo/battle`：89 个文件编译、100 人压测收发正常、
GM 端口通、`var/log/app.log` 有写入）。

## 用法

镜像**不预置框架代码**——工程靠 `-v` 挂进来，`vendor/` 也在工程目录里。
所以第一次要装依赖：

```bash
# 1) 构建镜像（PHP 从源码编译，约 25 分钟）
docker build -f docker/Dockerfile -t tsf:latest .

# 2) 在工程目录里装框架（需要 composer.json；没有就先 tsf new）
docker run --rm -v "$PWD":/app -w /app tsf:latest composer require hhr0815hhr/tsf

# 3) 编译
docker run --rm -v "$PWD":/app -w /app tsf:latest tsf build .

# 4) 起服务（映射端口）
docker run --rm -p 9001:9001 -v "$PWD":/app -w /app tsf:latest ./my-server
```

如果工程用的是仓库内的 `tsf/` submodule（像 `demo/*` 那样），把仓库根挂进来即可：

```bash
docker run --rm -v /path/to/TypeServer:/work -w /work/demo/battle tsf:latest \
  php85 /work/tsf/bin/tsf build .
```

## 镜像里有什么

| 层 | 内容 |
|:--|:--|
| 基础 | `debian:bookworm` |
| PHP | `/usr/local/php-8.5-zts`（ZTS + embed → `libphp.so`；pcntl / posix 已开） |
| 扩展 | swoole 6.2.5（thread 模式）、phpredis 6.3.0（ZTS）、zip（composer 依赖它解包）、pdo_mysql + mysqli（`pkg/db` 的 DSN 是 `mysql:`） |
| 工具链 | `~/.tsf/toolchain/typephp`（tpc + src + vendor），**含 tpc-patches 的改动** |
| phpx | `~/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so` |
| 其他 | g++ / cmake / libgmp-dev / libmpfr-dev / composer |
| 命令 | `php85`、`php`（都指向 ZTS 版）、`composer` |

**为什么 PHP 前缀必须是 `/usr/local/php-8.5-zts`**：`tsf build` 生成的链接命令把
rpath 写死了（`-Wl,-rpath,/usr/local/php-8.5-zts/lib` 和 `.../vendor/swoole/phpx/lib`）。
换到 `/opt/php` 之类的地方编译能过，但产物运行时报 `error while loading shared libraries`。

## 构建期的四个坑（都踩过）

### 1. 工具链脚本在 TypeServer 仓库，不在 tsf 仓库

`raw.githubusercontent.com/hhr0815hhr/**tsf**/main/scripts/install-toolchain.sh` 是 404。
而 `curl ... | bash` 会把 404 静默吞掉（`curl -f` 失败，bash 收到空输入照样返回 0），
错误只能拖到下一步 `cd` 时才暴露。Dockerfile 里改成先落盘再执行 + `test -f` 断言。

### 2. 工具链包自带 `build/`，CMakeCache 指向打包机器的路径

报 `CMakeCache.txt directory ... is different than ... /home/hhr/typephp-src/...`，
所以 phpx 那步必须先 `rm -rf build` 再 cmake。

### 3. composer 的 shebang 是 `php`，不是 `php85`

装完 composer 报 `/usr/bin/env: 'php': No such file or directory`。镜像里两个名字都建了软链。

### 4. 编译业务工程要 `libgmp-dev`，不只是 `libgmp10`

只装运行时库时，编译会撞 `phpx_big_int.h:9:10: fatal error: gmpxx.h: No such file`。
头文件也得在。

## 网络环境备注

- **BuildKit 走 daemon 代理**（`docker info` 里的 `HTTP Proxy: http.docker.internal:3128`）。
  代理不通时 `apt-get update` 报 502，而 `docker run` 里同样的命令是通的。
  可用 `DOCKER_BUILDKIT=0` 走容器网络绕开。
- **apt 默认 30 秒超时且不续传**：40MB 的包（如 gcc-12）反复 `Ign:` 重试永远下不完。
  Dockerfile 里已加 `-o Acquire::http::Timeout=300 -o Acquire::Retries=5`。
- 需要换源时：`--build-arg APT_MIRROR=mirrors.aliyun.com`（默认空 = 官方源）。

## 相关

tpc 的本地改动记录在仓库根的 `tpc-patches/README.md`。Dockerfile 里有一条断言，
换工具链版本时若补丁丢失会直接构建失败（而不是等 `tsf watch` 变慢才发现）。
