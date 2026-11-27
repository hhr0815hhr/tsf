/*
 * 最小探针：AOT 产物里能不能执行外部 PHP 代码。
 *
 * 只做一件事：把一段 PHP 源码交给 zend_eval_string 执行，返回结果。
 * 如果这个能在 AOT 产物里跑通，说明：
 *   1. libphp.so 的编译/执行链路是活的（zend_compile_file 已初始化）
 *   2. PHP 扩展可以被 AOT 产物加载
 * 后面才有必要做「脚本文件热更 + 对象传递」。
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_interp.h"
/* zend_call_method / zend_clear_exception 不在 php.h 里，显式包含：
 * 否则隐式声明会把 zval* 截成 int，调用静默失败（trace 拿不到就是这么来的） */
#include "Zend/zend_interfaces.h"
#include "Zend/zend_exceptions.h"
#include "SAPI.h"
#include "ext/standard/info.h"

/* {{{ interp_eval_string(string $code): string|bool */
PHP_FUNCTION(interp_eval_string)
{
    char   *code;
    size_t  code_len;
    zval    retval;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(code, code_len)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_UNDEF(&retval);

    /* 探针：确认实际收到的代码和长度（排查"expecting ;"那类解析错误） */
    if (getenv("INTERP_DEBUG") != NULL) {
        php_printf("DEBUG code_len=%zu code=[%s]\n", code_len, code);
    }

    if (zend_eval_stringl(code, code_len, &retval, "interp_eval_string") == FAILURE) {
        zend_error(E_WARNING, "interp_eval_string: eval failed");
        RETURN_FALSE;
    }

    if (Z_TYPE(retval) == IS_STRING) {
        RETVAL_STR(Z_STR(retval));
        return;
    }
    zval_ptr_dtor(&retval);
    RETURN_TRUE;
}
/* }}} */

/* {{{ interp_require_file(string $path): mixed
 *
 * 文件级编译 + 执行。比 zend_eval_stringl 更贴近真实需求：
 * 热更的是「脚本文件」，不是「代码字符串」。
 */
PHP_FUNCTION(interp_require_file)
{
    char   *path;
    size_t  path_len;
    zval    retval;
    zend_file_handle fh;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH(path, path_len)
    ZEND_PARSE_PARAMETERS_END();

    /* 8.5 里 zend_stream_init_filename 返回 void，打开失败由编译阶段报错 */
    zend_stream_init_filename(&fh, path);

    /* 用 zend_execute_scripts 而不是自己 compile+execute+destroy：
     * 前者走完整的 require 流程，脚本里定义的函数/类会被正确注册到符号表；
     * 后者 destroy_op_array 会把函数表一起销毁（实测 function_exists 返回 false）。
     */
    ZVAL_UNDEF(&retval);
    int rc = zend_execute_scripts(ZEND_REQUIRE, &retval, 1, &fh);
    zend_destroy_file_handle(&fh);

    if (rc == FAILURE) {
        zend_error(E_WARNING, "interp_require_file: execute failed");
        RETURN_FALSE;
    }

    if (Z_TYPE(retval) == IS_UNDEF) {
        RETURN_TRUE;
    }
    /* 任意类型都原样返回 —— 让脚本能 return 一个闭包或数组作为"导出表"。
     * 这一点很重要：PHP 不允许重复定义同名函数，所以热更的脚本不能靠
     * function xxx() 导出，只能 return 闭包（每次 require 得到新闭包）。 */
    RETURN_COPY_VALUE(&retval);
}
/* }}} */

/* {{{ interp_call_obj(string $fn, object $obj): mixed
 *
 * 调用脚本里定义的函数，把宿主侧的对象作为参数传进去。
 *
 * 这是「脚本操作宿主数据」的核心动作：
 *   脚本里 $obj->gold += 100，宿主侧的同一个对象应该跟着变。
 *
 * 前提：脚本已经 interp_require_file 过（函数已注册到符号表）。
 */
PHP_FUNCTION(interp_call_obj)
{
    zend_string *fn;
    zval        *obj;
    zval         retval;
    zval         args[1];

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(fn)
        Z_PARAM_ZVAL(obj)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_TYPE_P(obj) != IS_OBJECT) {
        zend_error(E_WARNING, "interp_call_obj: argument 2 must be object, got type %d", Z_TYPE_P(obj));
        RETURN_FALSE;
    }

    ZVAL_COPY(&args[0], obj);   /* 引用传递：脚本里改的就是同一个对象 */

    /* call_user_function 的第三个参数是 zval*（函数名），不是 zend_string*。
     * 直接传 zend_string* 会造成栈破坏（实测 stack smashing detected）。
     */
    zval fname_zv;
    ZVAL_STR_COPY(&fname_zv, fn);

    if (call_user_function(EG(function_table), NULL, &fname_zv, &retval, 1, args) != SUCCESS) {
        zval_ptr_dtor(&fname_zv);
        zval_ptr_dtor(&args[0]);
        zend_error(E_WARNING, "interp_call_obj: function %s not found or failed", ZSTR_VAL(fn));
        RETURN_FALSE;
    }
    zval_ptr_dtor(&fname_zv);
    zval_ptr_dtor(&args[0]);

    if (Z_TYPE(retval) == IS_LONG) {
        RETVAL_LONG(Z_LVAL(retval));
        return;
    }
    if (Z_TYPE(retval) == IS_STRING) {
        RETVAL_STR(Z_STR(retval));
        return;
    }
    if (Z_TYPE(retval) == IS_DOUBLE) {
        RETVAL_DOUBLE(Z_DVAL(retval));
        return;
    }
    zval_ptr_dtor(&retval);
    RETURN_TRUE;
}
/* }}} */

/* {{{ interp_invoke(callable $fn, object $obj): mixed
 *
 * 调用一个「运行期拿到的」闭包。
 *
 * 为什么不直接在 PHP 侧写 $fn($obj)：
 * AOT 编译期必须确定调用目标的签名，$fn 是运行期才知道的闭包，
 * tpc 会拒绝（见 docs/guide/08 的"闭包/动态调用"那条）。
 * 所以动态调用统一走这个 C 层入口。
 */
PHP_FUNCTION(interp_invoke)
{
    zval *fn;
    zval *obj;
    zend_long timeout_ms = 0;
    long long t_start = 0;
    zval  retval;
    zval  args[1];
    int   rc = FAILURE;
    int   bailed_out = 0;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(fn)
        Z_PARAM_ZVAL(obj)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    if (!zend_is_callable(fn, 0, NULL)) {
        zend_error(E_WARNING, "interp_invoke: argument 1 is not callable");
        RETURN_FALSE;
    }

    ZVAL_COPY(&args[0], obj);

    /* zend_try 捕获的是 bailout（Fatal error / 内存耗尽 / 超时），不是 PHP 异常。
     * PHP 的 throw 走 EG(exception)，要看 rc 和 EG(exception)。两者都要处理：
     *   - throw  -> 可恢复，清掉异常继续跑
     *   - bailout -> 不可恢复，记录后让调用方知道"这一局坏了"
     */
    /*
     * 执行预算：脚本是热更进来的，运营也可能改。一个写错的 while 就能
     * 把整个 worker 卡死（Coroutine::cancel 打不断纯 CPU 循环，实测见 §29）。
     *
     * zend_set_timeout 只接受**秒**。所以这是"防永久卡死"的兜底，不是
     * "保证不卡顿"——1 秒的停顿对游戏服仍然明显，真要可控得靠脚本审计。
     * 但没有这一层，一个死循环就是整服停机。
     *
     * PHP 8 的 VM 在每个 opcode 检查 vm_interrupt，所以纯 CPU 死循环也能打断，
     * 走的是 bailout（由下面的 zend_catch 接住），不是普通异常。
     */
    if (timeout_ms > 0) {
        zend_set_timeout((timeout_ms + 999) / 1000, 0);
        t_start = (long long) time(NULL);
    }

    zend_try {
        rc = call_user_function(NULL, NULL, fn, &retval, 1, args);
    } zend_catch {
        bailed_out = 1;
    } zend_end_try();

    /* 恢复：不恢复的话，后续的 AOT 代码也会被同一个超时掐掉 */
    if (timeout_ms > 0) {
        zend_set_timeout(0, 0);
    }

    zval_ptr_dtor(&args[0]);

    if (bailed_out) {
        /* Fatal 级别的错误：进程状态可能已经不可信。
         * 不假装成功，也不主动 exit —— 交给上层的监督者决定。
         */
        if (EG(exception)) {
            zend_clear_exception();
        }
        array_init(return_value);
        add_assoc_bool(return_value, "ok", 0);
        add_assoc_string(return_value, "fatal", "script caused a bailout (fatal error / timeout / oom)");
        /* 让调用方能把"超时"和"别的 fatal"分开处理：超时通常是脚本写错，
         * 其余 fatal 可能意味着进程状态已经不可信 */
        if (timeout_ms > 0 && t_start > 0 && ((long long) time(NULL) - t_start) * 1000 >= timeout_ms) {
            add_assoc_bool(return_value, "timeout", 1);
        }
        return;
    }

    if (EG(exception)) {
        /* 可捕获的 PHP 异常：取类名、message、**文件与行号**，清掉，返回结构化错误。
         *
         * 为什么必须带 file/line：只有 class+message 时，2000 行的脚本报一句
         * "business failed"，运维只能人肉翻。Lua 的 error() 默认给 file:line，
         * 这是脚本能不能上生产的分界——不是"调试舒服"的问题，是"出事能不能定位"。
         *
         * trace 用 getTraceAsString() 而不是自己遍历 trace 数组：
         * 后者要把 args/class/type 逐项取出来，几十行 C 只为拼一个字符串；
         * 而这个字符串的消费方是人（日志、GM 返回），不是程序。
         */
        /* 先摘掉 EG(exception) 再加引用持有对象：
         * zend_call_method 内部走 zend_call_function，而后者在
         * EG(exception) 非空时拒绝执行——不摘掉的话 getTraceAsString()
         * 永远返回 NULL（第一版就是这么静默失败的）。
         * 摘掉之后对象会被释放，所以先 ADDREF，用完再 RELEASE。 */
        zend_object *ex = EG(exception);
        GC_ADDREF(ex);
        zend_clear_exception();

        zend_string *cls = ex->ce->name;
        zval rv;
        zval *msg_zv = zend_read_property(ex->ce, ex, "message", sizeof("message") - 1, 1, &rv);
        zend_string *msg = (msg_zv && Z_TYPE_P(msg_zv) == IS_STRING)
            ? Z_STR_P(msg_zv)
            : ZSTR_EMPTY_ALLOC();

        zval rv_file;
        zval *file_zv = zend_read_property(ex->ce, ex, "file", sizeof("file") - 1, 1, &rv_file);
        zval rv_line;
        zval *line_zv = zend_read_property(ex->ce, ex, "line", sizeof("line") - 1, 1, &rv_line);

        array_init(return_value);
        add_assoc_bool(return_value, "ok", 0);
        add_assoc_str(return_value, "class", zend_string_copy(cls));
        add_assoc_str(return_value, "message", zend_string_copy(msg));
        if (file_zv && Z_TYPE_P(file_zv) == IS_STRING) {
            add_assoc_str(return_value, "file", zend_string_copy(Z_STR_P(file_zv)));
        }
        if (line_zv && Z_TYPE_P(line_zv) == IS_LONG) {
            add_assoc_long(return_value, "line", Z_LVAL_P(line_zv));
        }

        /* traceback：第一帧是抛出点的调用者，抛出点本身由 file/line 补。
         *
         * 用 zend_call_method 而不是 call_user_function：后者在
         * EG(exception) 非空时可能走不通（它要先把当前异常挂起），
         * zend_call_method 直接按对象调用，实测能拿到字符串。 */
        zval trace_ret;
        ZVAL_UNDEF(&trace_ret);
        zval *tr = zend_call_method(ex, ex->ce, NULL, "gettraceasstring",
            sizeof("gettraceasstring") - 1, &trace_ret, 0, NULL, NULL);
        if (tr != NULL && Z_TYPE(trace_ret) == IS_STRING) {
            add_assoc_str(return_value, "trace", zend_string_copy(Z_STR(trace_ret)));
        }
        if (!Z_ISUNDEF(trace_ret)) {
            zval_ptr_dtor(&trace_ret);
        }

        OBJ_RELEASE(ex);
        return;
    }

    if (rc == FAILURE) {
        zend_error(E_WARNING, "interp_invoke: call failed");
        RETURN_FALSE;
    }

    if (Z_TYPE(retval) == IS_UNDEF) {
        RETURN_TRUE;
    }
    RETURN_COPY_VALUE(&retval);
}
/* }}} */

/* {{{ interp_probe(): array  —— 报告运行时状态，用来诊断为什么跑不起来 */
PHP_FUNCTION(interp_probe)
{
    array_init(return_value);
    add_assoc_bool(return_value, "compile_file_set",
        zend_compile_file != NULL);
    add_assoc_bool(return_value, "compile_string_set",
        zend_compile_string != NULL);
    add_assoc_string(return_value, "sapi", sapi_module.name ? sapi_module.name : "(none)");
    add_assoc_long(return_value, "executor_globals_init",
        (long) (EG(function_table) != NULL));
}
/* }}} */

ZEND_BEGIN_ARG_INFO_EX(arginfo_interp_eval_string, 0, 0, 1)
    ZEND_ARG_INFO(0, code)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_interp_probe, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry interp_functions[] = {
    PHP_FE(interp_eval_string,  arginfo_interp_eval_string)
    PHP_FE(interp_require_file, arginfo_interp_eval_string)
    PHP_FE(interp_call_obj,     arginfo_interp_eval_string)
    PHP_FE(interp_invoke,       arginfo_interp_eval_string)
    PHP_FE(interp_probe,        arginfo_interp_probe)
    PHP_FE_END
};

PHP_MINFO_FUNCTION(interp)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "interp support", "enabled");
    php_info_print_table_row(2, "version", PHP_INTERP_VERSION);
    php_info_print_table_end();
}

zend_module_entry interp_module_entry = {
    STANDARD_MODULE_HEADER,
    "interp",
    interp_functions,
    NULL,   /* MINIT */
    NULL,   /* MSHUTDOWN */
    NULL,   /* RINIT */
    NULL,   /* RSHUTDOWN */
    PHP_MINFO(interp),
    PHP_INTERP_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_INTERP
ZEND_GET_MODULE(interp)
#endif
