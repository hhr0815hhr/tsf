// 原生帧编解码原语：PHP 侧唯一使用者是 TSF\Protocol\Frame\NativeHeaderFrame。
//
// 这一层只提供"字节操作"，不含任何协议语义（不分帧策略、不认 msgId 含义）——
// 语义在 src/Protocol 里，扩展只负责快。
//
// 帧格式：| len(4B, 含头) | msgId(2B) | flags(1B) | pad(1B) | body |
//
// 实测收益（bench/REPORT.md，交替采样 5 轮取中位数）：+14.4%（16B）/ +11.2%（1KB）。
// 早期单次采样曾得出 +55%，是离群值，已更正——单次采样不足以支撑结论。
//
// 设计约束：**不使用全局状态**。帧长度由调用方传入（c_frame_len 的返回值），
// 避免嵌套或交错调用时串数据。

#include <phpx.h>
#include <cstring>
#include <string>

using namespace php;

static const unsigned int MAX_FRAME = 1024 * 1024;
static const unsigned int HEADER = 8;

static inline unsigned int rb32(const unsigned char *p) {
    return ((unsigned int) p[0] << 24) | ((unsigned int) p[1] << 16) | ((unsigned int) p[2] << 8) |
           (unsigned int) p[3];
}

String php_c_encode(Int msgId, Str body, Int flags) {
    const char *data = body.data();
    size_t n = body.length();
    unsigned int len = (unsigned int) (HEADER + n);
    std::string out;
    out.resize(len);
    out[0] = (char) ((len >> 24) & 0xFF);
    out[1] = (char) ((len >> 16) & 0xFF);
    out[2] = (char) ((len >> 8) & 0xFF);
    out[3] = (char) (len & 0xFF);
    out[4] = (char) (((unsigned long long) msgId >> 8) & 0xFF);
    out[5] = (char) ((unsigned long long) msgId & 0xFF);
    out[6] = (char) (((unsigned long long) flags) & 0xFF);
    out[7] = 0;
    if (n > 0) {
        memcpy(&out[HEADER], data, n);
    }
    return String(out);
}

Int php_c_frame_len(Str buf) {
    const unsigned char *p = (const unsigned char *) buf.data();
    size_t have = buf.length();
    if (have < HEADER) {
        return 0;
    }
    unsigned int len = rb32(p);
    if (len < HEADER || len > MAX_FRAME) {
        return -1;
    }
    if (have < len) {
        return 0;
    }
    return (Int) len;
}

String php_c_decode_body(Str buf, Int len) {
    if (len <= (long long) HEADER) {
        return String();
    }
    size_t have = buf.length();
    if ((size_t) len > have) {
        return String();
    }
    return String(std::string(buf.data() + HEADER, (size_t) len - HEADER));
}
