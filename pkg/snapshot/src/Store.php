<?php

namespace TSF\Snapshot;

/**
 * 快照存哪。抽象出来是因为这个选择完全取决于部署：
 *
 * | 实现 | 适合 | 代价 |
 * |:--|:--|:--|
 * | `MemoryStore` | 单测、单进程内的"热备"（进程崩了就没） | 零 IO |
 * | `FileStore` | 单机部署、要扛 kill -9 | 一次 write + fsync |
 * | 自己的 db 实现 | 多节点、要跨机恢复 | 一次网络往返 |
 *
 * 上层（采集/恢复）不关心这些差异，只看到 `put/get`。
 *
 * 用抽象类而不是 interface：这个项目里 `Source` 也是抽象类，
 * 而 interface 在 AOT 下还没有先例，没必要冒这个险。
 */
abstract class Store
{
    /** 存一份。同 kind+key 覆盖——定期整存就是不断覆盖 */
    abstract public function put(Snapshot $s): bool;

    /** 取一份；没有或损坏返回 null */
    abstract public function get(string $kind, int $key): ?Snapshot;

    abstract public function delete(string $kind, int $key): bool;

    /**
     * 某类快照的全部 key。
     *
     * 进程重启后要"把所有房间捞回来"，就靠它枚举——所以**存储必须能列出 key**，
     * 这也是为什么 `put` 不能只写成一坨 blob 而要保持一 key 一份。
     */
    abstract public function keys(string $kind): array;
}
