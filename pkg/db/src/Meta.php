<?php

namespace TSF\Db;

/**
 * 实体元数据：一次反射的结果，Repo 持有它反复使用。
 *
 * **不做全局静态缓存**。理由是：一个实体类通常只有一个 Repo 实例，
 * 反射成本（微秒级）在构造时付一次就够；而静态属性在 AOT 下的生命周期
 * 行为我们没验证过，不值得为省几微秒冒这个险。
 *
 * 这里也不做「列类型到 PHP 类型」的显式转换表：`ReflectionProperty::setValue()`
 * 对带类型声明的属性本身就会做弱类型转换，实测 `"42"` 写进 `public int $hp`
 * 会得到 int 42。少一层转换就少一处可能出错的映射。
 */
final class Meta
{
    public string $cls = "";

    public string $table = "";

    /** 主键属性名（列名与属性名相同） */
    public string $idProp = "";

    /** 主键列名 */
    public string $idCol = "";

    /** 主键由数据库生成（INSERT 时排除，插入后回填） */
    public bool $idAuto = false;

    /** 普通列：属性名 => 列名（**不含**主键），用于 INSERT / UPDATE 的列清单 */
    public array $cols = [];

    /** 全部映射：属性名 => 列名（**含**主键），用于 hydrate */
    public array $colOf = [];

    /** 属性名 => ReflectionProperty（含主键）。缓存下来，热路径不再查反射 */
    public array $props = [];

    /** 建实例用。可空是因为 AOT 对未初始化 typed property 的支持不确定 */
    public ?\ReflectionClass $rc = null;

    /**
     * 解析一个实体类。缺 #[Table] / #[Id] 都是硬错误——
     * 这类错误只有在第一次访问时才会暴露，早抛比晚抛好。
     */
    public static function parse(string $cls): Meta
    {
        $m = new Meta();
        $m->cls = $cls;

        $rc = new \ReflectionClass($cls);
        $m->rc = $rc;

        // ---- 表名 ----
        $tAttrs = $rc->getAttributes(Table::class);
        $table = "";
        if ($tAttrs !== []) {
            $args = $tAttrs[0]->getArguments();
            if (isset($args[0])) {
                $table = (string) $args[0];
            }
        }
        if ($table === "") {
            throw new \RuntimeException("db: 实体 [" . $cls . "] 缺少 #[Table(\"表名\")]");
        }
        $m->table = $table;

        // ---- 属性 ----
        $list = $rc->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($list as $pr) {
            $name = $pr->getName();

            // 主键
            $idAttrs = $pr->getAttributes(Id::class);
            if ($idAttrs !== []) {
                if ($m->idProp !== "") {
                    throw new \RuntimeException("db: 实体 [" . $cls . "] 声明了多个 #[Id]");
                }
                $ia = $idAttrs[0]->getArguments();
                $auto = false;
                if (isset($ia[0])) {
                    $auto = (bool) $ia[0];
                } elseif (isset($ia["auto"])) {
                    $auto = (bool) $ia["auto"];
                }
                $m->idProp = $name;
                $m->idCol = $name;
                $m->idAuto = $auto;
                $m->colOf[$name] = $name;
                $m->props[$name] = $pr;
                continue;
            }

            // 普通列
            $colAttrs = $pr->getAttributes(Col::class);
            if ($colAttrs === []) {
                continue;
            }
            $col = $name;
            $ca = $colAttrs[0]->getArguments();
            if (isset($ca[0])) {
                $tmp = (string) $ca[0];
                if ($tmp !== "") {
                    $col = $tmp;
                }
            }
            $m->cols[$name] = $col;
            $m->colOf[$name] = $col;
            $m->props[$name] = $pr;
        }

        if ($m->idProp === "") {
            throw new \RuntimeException("db: 实体 [" . $cls . "] 缺少 #[Id]");
        }
        if ($m->cols === []) {
            throw new \RuntimeException("db: 实体 [" . $cls . "] 没有任何 #[Col] 属性");
        }
        return $m;
    }
}
