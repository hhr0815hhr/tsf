// wasm 桥接（第一版，单模块）。
//
// 这一版的目标不是功能完整，而是把「pkg 里的 C++ 能被 tsf build 编进去、并链上
// libtp_host」这条链路打通——之前 tsf 只收 pkg/src，且不生成 ld-flags，
// 所以带原生的包根本没法用 tsf 构建。
//
// 多模块注册表 / 灰度 swap / 观测在 spike/e1 的第三版里已经验证过，
// 等这条链路稳定后按需搬过来。

#include <phpx.h>
#include <string>

using namespace php;

// 由 libtp_host 提供（工程在 project.json 的 ld-flags 里链接）
extern "C" {
void *tp_open(const char *path);
int tp_call2(void *h, const char *name, long long a, long long b, long long *out);
void tp_close(void *h);
}

static void *g_host = nullptr;

Str php_wasm_version() {
    return Str("tsf-wasm/0.1");
}

/// 加载一个已预编译的 .cwasm；成功返回 0，失败 -1，重复加载 -2。
///
/// 注意必须用 tp-precompile 产出（fuel/epoch 是编译期插桩，产物带 fingerprint），
/// 用 wasmtime CLI 编的会被宿主直接拒绝。
Int php_wasm_load(Str name, Str path) {
    if (g_host != nullptr) {
        return -2;
    }
    std::string p = path.toStdString();
    g_host = tp_open(p.c_str());
    return g_host == nullptr ? -1 : 0;
}

/// 通用按名调用（两个 i64）。名字运行时解析，宿主不感知业务函数名。
Int php_wasm_call2(Int h, Str fn, Int a, Int b) {
    if (g_host == nullptr) {
        return -1000;
    }
    // 宿主 ABI 要求带 [method]runtime. 前缀；业务侧传裸名
    std::string name = "[method]runtime." + fn.toStdString();
    long long out = 0;
    int rc = tp_call2(g_host, name.c_str(), a, b, &out);
    return rc == 0 ? static_cast<Int>(out) : static_cast<Int>(-2000 + rc);
}

Int php_wasm_call1(Int h, Str fn, Int a) {
    return -9101;  // 第一版只做 call2
}

void php_wasm_close(Int h) {
    if (g_host != nullptr) {
        tp_close(g_host);
        g_host = nullptr;
    }
}
