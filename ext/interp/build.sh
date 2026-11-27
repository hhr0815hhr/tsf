#!/bin/bash
# 编译 ext/interp（脚本热更的运行时）。产物：本目录下的 modules/interp.so
#
# 为什么要有这个脚本：interp 的源码随 composer 包分发（vendor/hhr0815hhr/tsf/ext/interp），
# 但 .so 必须在**目标机**上现编——扩展 ABI 绑定具体的 PHP 构建（版本 + 是否 ZTS）。
#
#   bash vendor/hhr0815hhr/tsf/ext/interp/build.sh            # 机器上的命令是 php85 / phpize85 / php-config85
#   PHP_BIN=php PHPIZE=phpize PHP_CONFIG=php-config bash .../build.sh   # PHP 8.5 就是默认 php 的机器
#
# interp 必须和 AOT 产物用的是**同一份 PHP**：>= 8.5 且线程安全（ZTS）。
# 编到别的 PHP 上不会报错，只是加载时才发现符号对不上。
#
# 已编过且源码没变时直接退出（约 0.1 秒）；改了 interp.c 要重编，直接再跑一次。
set -u
cd "$(dirname "$0")" || exit 1

PHP_BIN=${PHP_BIN:-php85}
PHPIZE=${PHPIZE:-phpize85}
PHP_CONFIG=${PHP_CONFIG:-php-config85}
JOBS=${JOBS:-4}

for tool in "$PHP_BIN" "$PHPIZE" "$PHP_CONFIG"; do
    if ! command -v "$tool" > /dev/null 2>&1; then
        echo "FAIL 找不到 $tool（可用 PHP_BIN / PHPIZE / PHP_CONFIG 指定命令名）"
        exit 1
    fi
done

# 版本与 ZTS 的检查用解释器自己做，不解析 `php-config --version` 的输出
if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80500 && ZEND_THREAD_SAFE ? 0 : 1);'; then
    echo "FAIL interp 需要 PHP >= 8.5 且线程安全（ZTS）：$($PHP_BIN -v | head -1)"
    exit 1
fi

# phpize 的输出目录就是本目录；configure/make 的日志留在 build/ 里，失败时打尾部
LOG=build/build.log
mkdir -p build

if [ ! -f modules/interp.so ] || [ interp.c -nt modules/interp.so ] \
    || [ config.m4 -nt modules/interp.so ] || [ php_interp.h -nt modules/interp.so ]; then
    {
        "$PHPIZE"
        ./configure --with-php-config="$(command -v "$PHP_CONFIG")"
        make -j"$JOBS"
    } > "$LOG" 2>&1
    if [ ! -f modules/interp.so ]; then
        echo "FAIL 编译失败（日志 $PWD/$LOG）"
        tail -5 "$LOG"
        exit 1
    fi
fi

echo "interp.so 已就绪：$PWD/modules/interp.so"
echo "加载：echo \"extension=$PWD/modules/interp.so\" > <某目录>/interp.ini，运行时设 PHP_INI_SCAN_DIR='<该目录>:<php 的 conf.d>'"
