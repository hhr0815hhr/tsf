# tsf 的构建/运行镜像。
#
# ## 为什么需要它
#
# 跑 tsf 要凑一套并不轻松的环境：PHP 8.5 **ZTS**（还要 `--enable-embed` 出 libphp.so）、
# swoole 开 `--enable-swoole-thread`、phpredis（ZTS 版 .so）、zip（composer 装包必需）、
# typephp 编译器（42MB 工具链包）、phpx（要 cmake 现编）、外加 g++ 和 libmpfr。
# 手工装一轮通常 30 分钟起，还容易漏掉某个 `-dev`。
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
ARG REDIS_VERSION=6.3.0
ARG TSF_TOOLCHAIN_TAG=toolchain-0.9.6

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
ARG REDIS_VERSION
ARG TSF_TOOLCHAIN_TAG
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
    apt-get -o Acquire::Retries=5 -o Acquire::http::Timeout=300 -o Acquire::https::Timeout=300 update && \
    apt-get -o Acquire::Retries=5 -o Acquire::http::Timeout=300 -o Acquire::https::Timeout=300 install -y --no-install-recommends \
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
# --with-pdo-mysql       pkg/db 的 DSN 就是 `mysql:host=...`，缺它一 connect 就
#                        "could not find driver"；--with-mysqli 一起带上，
#                        免得业务用 mysqli 时又要重编
#
# 这两项**必须在 configure 里**，不能像 zip 那样事后补 .so：
#   1. pdo_mysql 要用 ext/pdo 的内部头（pdo_sql_parser.h 之类，不随 make install 导出）
#   2. mysqlnd 得编进 PHP 核心才生效 —— 拆成 mysqlnd.so + pdo_mysql.so 的
#      "共享 mysqlnd" 方案能跑，但加载顺序敏感，属于自找麻烦
# PHP 8 起不再支持 libmysqlclient，所以不需要 mysql 的任何 -dev 包，mysqlnd 是内置协议实现。
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
        --with-mysqli --with-pdo-mysql \
    ; \
    make -j"$(nproc)"; \
    make install; \
    ln -sf "${PHP_PREFIX}/bin/php" /usr/local/bin/php85; \
    "${PHP_PREFIX}/bin/php" -v; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix posix && echo "posix ok"; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix pcntl && echo "pcntl ok"; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix pdo_mysql && echo "pdo_mysql ok"; \
    "${PHP_PREFIX}/bin/php" -r 'exit(in_array("mysql", PDO::getAvailableDrivers(), true) ? 0 : 1);' && echo "PDO mysql driver ok"

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

# --- phpredis（pkg/redis 直接 `new \Redis()`，缺了它 AOT 产物启动即报 Class not found）---
# 三个点，都会踩：
# 1. 不用 pecl 命令装 —— 这里的 PHP 是 `./configure && make install` 编的，没带 pear
#    （bin 目录里只有 php/phpize/php-config/phpdbg 那套），`pecl install redis` 不存在。
#    直接拉源码包 phpize 编。
# 2. 版本要挑支持 PHP 8.5 的：6.2.0（2025-03）前后才开始跟 8.5 兼容，
#    6.3.0 是首个明确配 8.5 的稳定版。低版本编得过但跑起来可能出段错误。
# 3. ZTS 不需要额外开关：phpize 从 php-config --configure-options 里读到 --enable-zts，
#    自动带 -DZTS 编出 thread-safe 版本，产物落在 no-debug-zts-<apiversion> 目录。
#    反过来说，如果哪天有人把它换成 NTS 的 php-config，这里不会报错，只会静默产出
#    一个加载不上的 .so —— 所以下面留了一条 `php -m | grep -qi redis` 断言。
# 网络兜底：pecl.php.net 偶发抽风，退到 GitHub release（两者都是 tar.gz，解压命令通用）。
RUN set -eux; \
    curl -fsSL "https://pecl.php.net/get/redis-${REDIS_VERSION}.tgz" -o /tmp/redis.tgz \
      || curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/${REDIS_VERSION}.tar.gz" -o /tmp/redis.tgz; \
    mkdir -p /src/redis && tar -xzf /tmp/redis.tgz -C /src/redis --strip-components=1; \
    cd /src/redis; \
    "${PHP_PREFIX}/bin/phpize"; \
    ./configure --with-php-config="${PHP_PREFIX}/bin/php-config" --enable-redis; \
    make -j"$(nproc)"; \
    make install; \
    echo "extension=redis.so" >> "${PHP_PREFIX}/lib/php.ini"; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix redis && echo "redis loaded"; \
    "${PHP_PREFIX}/bin/php" -r 'exit(class_exists("Redis") ? 0 : 1);' && echo "Redis class ok"

# --- typephp 编译器（工具链包，约 42MB / 解压 172MB）---
# 两个坑：
# 1. 这个脚本在 **TypeServer** 仓库，不在 tsf 仓库（写错 URL 会 404）
# 2. 不要用 `curl ... | bash` —— 404 时 curl -f 失败，但 bash 收到空输入照样
#    "成功"，错误被拖到下一步（cd 到不存在的目录）才暴露。先落盘再执行。
# 最后一句是补丁断言：tpc-patches/README.md 说「升级 tpc 之后这些补丁会丢」，
# 而只有打了补丁的 tpc 才只重编变化部分（否则改一行重编整个聚合单元）。
# toolchain-0.9.6 已含补丁；将来换版本时这条会拦住，提示需要重新应用。
# （注释只能写在这里，不能写进 RUN 的续行 —— 会被 shell 当成与后续命令同一行的注释）
RUN set -eux; \
    curl -fsSL -o /tmp/install-toolchain.sh \
      "https://raw.githubusercontent.com/hhr0815hhr/TypeServer/main/scripts/install-toolchain.sh"; \
    TSF_TOOLCHAIN_TAG="${TSF_TOOLCHAIN_TAG}" bash /tmp/install-toolchain.sh; \
    test -f /root/.tsf/toolchain/typephp/bin/tpc.php; \
    grep -q "Stub hash: <ignored>" /root/.tsf/toolchain/typephp/src/Translator.php \
      || { echo "ERROR: tpc 补丁缺失，见 TypeServer/tpc-patches/README.md"; exit 1; }

# --- phpx（AOT 运行时，源码在工具链 vendor 里，要现编）---
# 工具链包里带着打包机器上的 build/ 目录，里面 CMakeCache.txt 记的是
# /home/hhr/typephp-src/... 的路径，换个目录就会报
# "CMakeCache.txt directory ... is different than the directory ... was created"。
# 所以先删掉重建，别指望复用别人的缓存。
RUN set -eux; \
    cd "$HOME/.tsf/toolchain/typephp/vendor/swoole/phpx"; \
    rm -rf build; \
    cmake -B build -DCMAKE_BUILD_TYPE=Release \
          -DPHP_CONFIG="${PHP_PREFIX}/bin/php-config"; \
    cmake --build build -j"$(nproc)"; \
    test -f lib/libphpx.so

# --- zip 扩展（composer 的 ZipDownloader 必需）---
# 没有它 `composer require` 直接失败：
#   The zip extension and unzip/7z commands are both missing
# 而 composer 是镜像里装依赖的唯一入口，所以这不是"可选扩展"。
#
# 为什么是**单独一层**、而不是把 `--with-zip` 加进上面 PHP 的 ./configure：
# 加进去会作废 PHP 编译层（约 8 分钟），而这里只需要 20 秒。
# 代价是 PHP 的 configure 选项与最终扩展清单不再一一对应，故留此注释 + 断言。
# 依赖都已就绪：builder 的 apt 列表里有 libzip-dev，PHP 源码树在 /src/php-8.5.12。
# ext/zip 不依赖 PHP 内部私有头（不像 pdo_pgsql），在源码树里 phpize 即可。
RUN set -eux; \
    cd "/src/php-${PHP_VERSION}/ext/zip"; \
    "${PHP_PREFIX}/bin/phpize"; \
    ./configure --with-php-config="${PHP_PREFIX}/bin/php-config" --with-zip; \
    make -j"$(nproc)"; \
    make install; \
    echo "extension=zip.so" >> "${PHP_PREFIX}/lib/php.ini"; \
    "${PHP_PREFIX}/bin/php" -m | grep -qix zip && echo "zip loaded"; \
    "${PHP_PREFIX}/bin/php" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' && echo "ZipArchive ok"

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

# 运行期依赖：libmpfr / libgmp（phpx 链了）、libssl / libcurl / libxml2 / sqlite、
# libzip4（zip.so 链了它 —— 少了它 `php -m` 里 zip 直接不出现）
# 另外保留 build-essential + cmake —— 这个镜像同时要能 `tsf build`，
# 见文件顶部「为什么不做多阶段瘦身」。
# unzip 是 composer 的兜底解压器：zip 扩展在时用不到，但它只要 200KB，
# 留着可以少一类"扩展又出问题就彻底卡死"的故障模式。
#
# 注意：注释不能写在 RUN 的行继续（`\` 换行）里面，shell 会把它当参数传给 apt-get。
RUN if [ -n "$APT_MIRROR" ]; then \
        for f in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
            if [ -f "$f" ]; then sed -i "s|deb.debian.org|${APT_MIRROR}|g" "$f"; fi; \
        done; \
    fi; \
    apt-get -o Acquire::Retries=5 -o Acquire::http::Timeout=300 -o Acquire::https::Timeout=300 update && \
    apt-get -o Acquire::Retries=5 -o Acquire::http::Timeout=300 -o Acquire::https::Timeout=300 install -y --no-install-recommends \
        libmpfr6 libgmp10 libssl3 libcurl4 libxml2 libsqlite3-0 libonig5 \
        libzip4 libstdc++6 ca-certificates \
        libgmp-dev libmpfr-dev \
        curl xz-utils unzip \
        build-essential cmake \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*.deb /tmp/*

COPY --from=builder /usr/local/php-8.5-zts /usr/local/php-8.5-zts
COPY --from=builder /root/.tsf /root/.tsf

# php85 是给 tsf 的约定名；php 是 composer / 各类工具 shebang 里写死的名字，
# 两个都建上，否则 composer 装好也跑不起来（/usr/bin/env: 'php': No such file）
RUN ln -sf /usr/local/php-8.5-zts/bin/php /usr/local/bin/php85 \
 && ln -sf /usr/local/php-8.5-zts/bin/php /usr/local/bin/php \
 && /usr/local/php-8.5-zts/bin/php -m | grep -qi swoole \
 && /usr/local/php-8.5-zts/bin/php -m | grep -qix redis \
 && /usr/local/php-8.5-zts/bin/php -m | grep -qix zip \
 && /usr/local/php-8.5-zts/bin/php -m | grep -qix pdo_mysql \
 && /usr/local/php-8.5-zts/bin/php -r 'exit(class_exists("Redis") && class_exists("ZipArchive") && in_array("mysql", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
 && echo "swoole ok / redis ok / zip ok / pdo_mysql ok"

# 产物运行时要从这两个目录找 .so（rpath 写死的）
ENV LD_LIBRARY_PATH=/usr/local/php-8.5-zts/lib:/root/.tsf/toolchain/typephp/vendor/swoole/phpx/lib

# 装 composer，不预置 tsf 源码：
# 工程是靠 `-v` 挂进来的，框架本来就是 composer 包，工程自己带 vendor。
# 镜像只负责「环境」——PHP/swoole/tpc/phpx/g++ —— 不掺进任何业务代码。
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
 && php85 /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm -f /tmp/composer-setup.php \
 && composer --version

WORKDIR /app

# 默认给个可用的 shell；实际用法是挂载工程目录后跑 tsf build / 产物
CMD ["bash"]
