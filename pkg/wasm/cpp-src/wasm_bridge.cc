// wasm 桥接：主实例 + 灰度实例 + 确定性分流 + 原子切换 + 观测。
//
// 设计取舍：
//   - **分流用 hash(key) 而不是随机数**。同一玩家必须始终走同一版本，否则同一局里
//     一半请求走旧逻辑一半走新逻辑，状态会分裂——这种 bug 比"发布了个坏版本"更难查。
//   - **swap 是原子的**：先打开新实例，成功后才替换指针，失败保持旧实例可用。
//     调用是同步的（无协程、无并发持有），所以旧实例可以立即关闭；spike 第三版用
//     延后释放是因为它允许并发调用，这里不需要那层复杂度。
//   - 槽位固定 4 个：够用（一般就一个玩法模块 + 一个灰度），越少越好查。

#include <phpx.h>
#include <chrono>
#include <string>

using namespace php;

extern "C" {
void *tp_open(const char *path);
int tp_call2(void *h, const char *name, long long a, long long b, long long *out);
void tp_close(void *h);
}

struct Slot {
    void *main_host;
    void *canary_host;
    int ratio;        // 0-1000，灰度比例（千分比）
    int main_calls;
    int canary_calls;
};

static const int MAX_SLOTS = 4;
static Slot g_slots[MAX_SLOTS];
static bool g_init = false;

static void ensure_init() {
    if (g_init) {
        return;
    }
    for (int i = 0; i < MAX_SLOTS; i++) {
        g_slots[i].main_host = nullptr;
        g_slots[i].canary_host = nullptr;
        g_slots[i].ratio = 0;
        g_slots[i].main_calls = 0;
        g_slots[i].canary_calls = 0;
    }
    g_init = true;
}

static Slot *slot_of(Int h) {
    ensure_init();
    if (h < 0 || h >= MAX_SLOTS) {
        return nullptr;
    }
    if (g_slots[h].main_host == nullptr) {
        return nullptr;
    }
    return &g_slots[h];
}

// 确定性分流：Knuth 乘法散列。同一个 key 永远落到同一侧。
static bool pick_canary(const Slot &s, Int key) {
    if (s.canary_host == nullptr || s.ratio <= 0) {
        return false;
    }
    if (s.ratio >= 1000) {
        return true;
    }
    unsigned long long k = static_cast<unsigned long long>(key < 0 ? -key : key);
    unsigned long long h = (k * 2654435761ULL) >> 8;
    return static_cast<int>(h % 1000ULL) < s.ratio;
}

Str php_wasm_version() {
    return Str("tsf-wasm/0.2");
}

/// 加载主实例。成功返回槽号（>=0），失败 -1，槽满 -2。
Int php_wasm_load(Str name, Str path) {
    ensure_init();
    for (int i = 0; i < MAX_SLOTS; i++) {
        if (g_slots[i].main_host == nullptr) {
            std::string p = path.toStdString();
            void *h = tp_open(p.c_str());
            if (h == nullptr) {
                return -1;
            }
            g_slots[i].main_host = h;
            return i;
        }
    }
    return -2;
}

/// 加载灰度实例：**不动主实例**，失败时主实例照常服务。
Int php_wasm_canary(Int slot, Str path) {
    Slot *s = slot_of(slot);
    if (s == nullptr) {
        return -1000;
    }
    std::string p = path.toStdString();
    void *h = tp_open(p.c_str());
    if (h == nullptr) {
        return -1;
    }
    if (s->canary_host != nullptr) {
        tp_close(s->canary_host);
    }
    s->canary_host = h;
    s->canary_calls = 0;
    return 0;
}

/// 设置灰度比例（千分比）。0 = 全走主，1000 = 全走灰度。
Int php_wasm_ratio(Int slot, Int permille) {
    Slot *s = slot_of(slot);
    if (s == nullptr) {
        return -1000;
    }
    if (permille < 0) {
        permille = 0;
    }
    if (permille > 1000) {
        permille = 1000;
    }
    s->ratio = static_cast<int>(permille);
    return s->ratio;
}

/// 按 key 分流调用。key <= 0 时恒走主实例（用于无状态调用）。
Int php_wasm_call2(Int slot, Int key, Str fn, Int a, Int b) {
    Slot *s = slot_of(slot);
    if (s == nullptr) {
        return -1000;
    }
    void *host = s->main_host;
    bool canary = key > 0 && pick_canary(*s, key);
    if (canary) {
        host = s->canary_host;
    }
    std::string name = "[method]runtime." + fn.toStdString();
    long long out = 0;
    int rc = tp_call2(host, name.c_str(), a, b, &out);
    if (canary) {
        s->canary_calls++;
    } else {
        s->main_calls++;
    }
    if (rc == 0) {
        return static_cast<Int>(out);
    }
    return static_cast<Int>(-2000 + rc);
}

/// 原子切换：新实例打开成功才替换，失败返回负值且旧实例继续服务。
/// 返回耗时微秒（>=0），失败 -1。
Int php_wasm_swap(Int slot, Str path) {
    Slot *s = slot_of(slot);
    if (s == nullptr) {
        return -1000;
    }
    auto t0 = std::chrono::steady_clock::now();
    std::string p = path.toStdString();
    void *h = tp_open(p.c_str());
    if (h == nullptr) {
        return -1;
    }
    void *old = s->main_host;
    s->main_host = h;
    if (s->canary_host != nullptr) {
        tp_close(s->canary_host);
        s->canary_host = nullptr;
        s->ratio = 0;
    }
    if (old != nullptr) {
        tp_close(old);
    }
    auto t1 = std::chrono::steady_clock::now();
    return static_cast<Int>(
        std::chrono::duration_cast<std::chrono::microseconds>(t1 - t0).count());
}

/// 观测：主/灰度各被调用了多少次。
Str php_wasm_stats(Int slot) {
    Slot *s = slot_of(slot);
    if (s == nullptr) {
        return Str("-1000");
    }
    std::string out = "main=" + std::to_string(s->main_calls) +
                      ",canary=" + std::to_string(s->canary_calls) +
                      ",ratio=" + std::to_string(s->ratio);
    return Str(out);
}

void php_wasm_close(Int slot) {
    Slot *s = slot_of(slot);
    if (s == nullptr) {
        return;
    }
    if (s->main_host != nullptr) {
        tp_close(s->main_host);
        s->main_host = nullptr;
    }
    if (s->canary_host != nullptr) {
        tp_close(s->canary_host);
        s->canary_host = nullptr;
    }
    s->ratio = 0;
}
