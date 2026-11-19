# tsf 的构建/运行镜像。
#
# ## 为什么需要它
#
# 跑 tsf 要凑一套并不轻松的环境：PHP 8.5 **ZTS**（还要 `--enable-embed` 出 libphp.so）、
# swoole 开 `--enable-swoole-thread`、typephp 编译器（42MB 工具链包）、phpx（要 cmake 现编）、
# 外加 g++ 和 libmpfr。手工装一轮通常 30 分钟起，还容易漏掉某个 `-dev`。
#
# ## 用法
#
#   docker build -f docker/Dockerfile -t tsf:latest .
#   docker run --rm -v "$PWD":/app -w /app tsf:latest tsf doctor
#   docker run --rm -v "$PWD":/app -w /app tsf:latest tsf build .
#
# 产物要跑起来还需要监听端口，那时用 `-p`：
#
#   docker run --rm -p 9001:9001 -v "$PWD":/app -w /app tsf:latest ./my-server
#
# ## 一个不能改的路径
#
# `tsf build` 生成的链接命令把 rpath 写死成：
#   /usr/local/php-8.5-zts/lib
#   $HOME/.tsf/toolchain/typephp/vendor/swoole/phpx/lib
# 所以镜像里 PHP 前缀**必须**是这个路径，改成 /opt/php 之类的产物会跑不起来
# （报 "error while loading shared libraries"）。

ARG PHP_VERSION=8.5.12
ARG SWOOLE_VERSION=6.2.5
ARG TSF_TOOLCHAIN_VERSION=v0.7.0

# 国内网络建议换源，能省几分钟（Debian 官方源拉索引有时会卡在 Ign: 重试）：
#   docker build --build-arg APT_MIRROR=mirrors.aliyun.com -f docker/Dockerfile -t tsf:latest .
# 默认留空 = 用官方源，保持对所有人可用。
ARG APT_MIRROR=""

# ---------------------------------------------------------------------------
# 阶段一：构建环境（含编译器、工具链、phpx）
# ---------------------------------------------------------------------------
FROM debian:bookworm AS builder

ARG PHP_VERSION
ARG SWOOLE_VERSION
ARG TSF_TOOLCHAIN_VERSION
ARG APT_MIRROR

ENV DEBIAN_FRONTEND=noninteractive \
    PHP_PREFIX=/usr/local/php-8.5-zts

# 换源（可选）：Debian 12 的源可能是 deb822 格式（debian.sources），也可能是老的
# sources.list，两处都试一次，省得因为镜像版本不同而失效。
RUN if [ -n "$APT_MIRROR" ]; then \
        for f in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
            if [ -f "$f" ]; then sed -i "s|deb.debian.org|${APT_MIRROR}|g" "$f"; fi; \
        done; \
    fi; \
    apt-get -o Acquire::Retries=5 update && \
    apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
        build-essential autoconf bison re2c pkg-config \
        cmake git curl ca-certificates xz-utils \
        libxml2-dev libssl-dev libsqlite3-dev libcurl4-openssl-dev \
        libonig-dev libzip-dev libpng-dev \
        libgmp-dev libmpfr-dev \
    && rm -rf /var/lib/apt/lists/*

# --- PHP 8.5 ZTS + embed ---
# --enable-zts           phpx 的前置条件（多线程各自持有 PHP 请求上下文）
# --enable-embed=shared  产出 libphp.so，AOT 产物要链它
# --enable-pcntl         Swoole 的多进程 Server 依赖 fork/signal
# --enable-posix         框架里用了 posix_getpid()（日志的 pid 字段）
RUN set -eux; \
    curl -fsSL "https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz" -o /tmp/php.tar.gz; \
    mkdir -p /src && tar -xzf /tmp/php.tar.gz -C /src; \
    cd "/src/php-${PHP_VERSION}"; \
    ./configure \
        --prefix="${PHP_PREFIX}" \
        --enable-zts \
        --enable-embed=shared \
        --enable-pcntl \
        --enable-posix \
        --disable-cgi --disable-phpdbg \
        --with-openssl --with-zlib --with-curl \
        --enable-sockets --enable-mbstring --enable-fileinfo \
        --with-sqlite3 --with-pdo-sqlite \
    ; \
    make -j"$(nproc)"; \
    make install; \
    ln -sf "${PHP_PREFIX}/bin/php" /usr/local/bin/php85; \
    "${PHP_PREFIX}/bin/php" -v; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix posix && echo "posix ok"; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix pcntl && echo "pcntl ok"

# --- swoole（--enable-swoole-thread 要求 ZTS）---
RUN set -eux; \
    curl -fsSL "https://github.com/swoole/swoole-src/archive/refs/tags/v${SWOOLE_VERSION}.tar.gz" -o /tmp/swoole.tar.gz; \
    tar -xzf /tmp/swoole.tar.gz -C /src; \
    cd "/src/swoole-src-${SWOOLE_VERSION}"; \
    "${PHP_PREFIX}/bin/phpize"; \
    ./configure --with-php-config="${PHP_PREFIX}/bin/php-config" \
                --enable-swoole-thread \
                --enable-openssl; \
    make -j"$(nproc)"; \
    make install; \
    mkdir -p "${PHP_PREFIX}/lib"; \
    echo "extension=swoole.so" >> "${PHP_PREFIX}/lib/php.ini"; \
    "${PHP_PREFIX}/bin/php" -m | grep -qi swoole && echo "swoole loaded"

# --- typephp 编译器（工具链包，约 42MB / 解压 168MB）---
RUN set -eux; \
    curl -fsSL "https://raw.githubusercontent.com/hhr0815hhr/tsf/main/scripts/install-toolchain.sh" \
      | TSF_TOOLCHAIN_VERSION="${TSF_TOOLCHAIN_VERSION}" bash

# --- phpx（AOT 运行时，源码在工具链 vendor 里，要现编）---
RUN set -eux; \
    cd "$HOME/.tsf/toolchain/typephp/vendor/swoole/phpx"; \
    cmake -B build -DCMAKE_BUILD_TYPE=Release \
          -DPHP_CONFIG="${PHP_PREFIX}/bin/php-config"; \
    cmake --build build -j"$(nproc)"

# 用字面路径而不是 $HOME：Dockerfile 的 ENV 里 $HOME 不一定有值（取决于基础镜像），
# 展开成空串的话 TPC 会变成 /typephp/bin/tpc.php，直到 `tsf build` 才报错。
ENV TPC=/root/.tsf/toolchain/typephp/bin/tpc.php \
    TSF_PHP=php85

# ---------------------------------------------------------------------------
# 阶段二：运行/开发镜像
#
# 不做极端裁剪：镜像里保留编译器和工具链，这样同一个镜像既能 `tsf build`
# 也能直接跑产物 —— 「一个镜像干两件事」比「两个镜像省 300MB」更实用，
# 至少第一次用的时候不用先搞懂哪个镜像干什么。
# ---------------------------------------------------------------------------
FROM debian:bookworm

ARG PHP_VERSION
ARG APT_MIRROR

ENV DEBIAN_FRONTEND=noninteractive \
    PHP_PREFIX=/usr/local/php-8.5-zts \
    TPC=/root/.tsf/toolchain/typephp/bin/tpc.php \
    TSF_PHP=php85

# 运行期依赖：libmpfr / libgmp（phpx 链了）、libssl / libcurl / libxml2 / sqlite
# 另外保留 build-essential + cmake —— 这个镜像同时要能 `tsf build`，
# 见文件顶部「为什么不做多阶段瘦身」。
#
# 注意：注释不能写在 RUN 的行继续（`\` 换行）里面，shell 会把它当参数传给 apt-get。
RUN if [ -n "$APT_MIRROR" ]; then \
        for f in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
            if [ -f "$f" ]; then sed -i "s|deb.debian.org|${APT_MIRROR}|g" "$f"; fi; \
        done; \
    fi; \
    apt-get -o Acquire::Retries=5 update && \
    apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
        libmpfr6 libgmp10 libssl3 libcurl4 libxml2 libsqlite3-0 libonig5 \
        libstdc++6 ca-certificates \
        build-essential cmake \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /usr/local/php-8.5-zts /usr/local/php-8.5-zts
COPY --from=builder /root/.tsf /root/.tsf

RUN ln -sf /usr/local/php-8.5-zts/bin/php /usr/local/bin/php85 \
 && /usr/local/php-8.5-zts/bin/php -m | grep -qi swoole \
 && echo "swoole ok"

# 产物运行时要从这两个目录找 .so（rpath 写死的）
ENV LD_LIBRARY_PATH=/usr/local/php-8.5-zts/lib:/root/.tsf/toolchain/typephp/vendor/swoole/phpx/lib

# tsf 自己是 composer 包；镜像里预置一份，方便 `tsf new` 起新工程
RUN mkdir -p /opt/tsf && cd /opt/tsf \
 && curl -fsSL -o tsf.tar.gz "https://github.com/hhr0815hhr/tsf/archive/refs/heads/main.tar.gz" \
 && tar -xzf tsf.tar.gz --strip-components=1 -C /opt/tsf \
 && rm -f tsf.tar.gz \
 && ln -sf /opt/tsf/bin/tsf /usr/local/bin/tsf \
 && tsf --version

WORKDIR /app

# 默认给个可用的 shell；实际用法是挂载工程目录后跑 tsf build / 产物
CMD ["bash"]
