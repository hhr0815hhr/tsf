<?php

namespace TSF\Snapshot;

/**
 * 内存存储：`kind:key => JSON 串`。
 *
 * 用途有两个：**单测**和**进程内的热备**。
 *
 * 后者值得说清楚：进程被 kill -9 时内存里的东西全没了，所以它救不了你；
 * 但它能救"单个房间崩了、进程还活着"的场景（比如某个房间的数据异常，
 * 把它从上一帧的快照重建）。真正做到 kill -9 零丢失的是 `FileStore` 或 db。
 *
 * 存的是**编码后的字符串**而不是 `Snapshot` 对象：这样它和文件/db 实现
 * 的行为完全一致（包括版本校验的时机），换实现不会出现"内存版能过、文件版报错"。
 */
final class MemoryStore extends Store
{
    /** @var array<string, string> "kind:key" => json */
    private array $data = [];

    private int $puts = 0;

    private int $gets = 0;

    private int $hits = 0;

    public function put(Snapshot $s): bool
    {
        $this->data[$this->id($s->kind, $s->key)] = Codec::encode($s);
        $this->puts++;
        return true;
    }

    public function get(string $kind, int $key): ?Snapshot
    {
        $this->gets++;
        $json = $this->data[$this->id($kind, $key)] ?? null;
        if (!is_string($json)) {
            return null;
        }
        $this->hits++;
        return Codec::decode($json);
    }

    public function delete(string $kind, int $key): bool
    {
        $id = $this->id($kind, $key);
        if (!isset($this->data[$id])) {
            return false;
        }
        unset($this->data[$id]);
        return true;
    }

    /** @return array<int, int> */
    public function keys(string $kind): array
    {
        $prefix = $kind . ":";
        $out = [];
        foreach ($this->data as $id => $json) {
            $s = (string) $id;
            if (str_starts_with($s, $prefix)) {
                $out[] = (int) substr($s, strlen($prefix));
            }
        }
        return $out;
    }

    public function stats(): array
    {
        return [
            "entries" => count($this->data),
            "puts" => $this->puts,
            "gets" => $this->gets,
            "hits" => $this->hits,
        ];
    }

    public function clear(): void
    {
        $this->data = [];
    }

    private function id(string $kind, int $key): string
    {
        return $kind . ":" . $key;
    }
}
