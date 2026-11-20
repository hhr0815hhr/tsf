<?php

namespace TSF\Registry;

/**
 * 极简 RESP 客户端：只够注册表用，不追求做一个通用 Redis 客户端。
 *
 * ## 为什么不用 `pkg/redis`
 *
 * 那个包包的是 php-redis 扩展（`\Redis`）。**扩展类在 AOT 产物里不存在**：
 * 编译器能把源码编进去，但运行时 `new \Redis()` 找不到类——实测症状是
 * 「编译成功 66 files，运行时 class 'TSF\Redis\Client' is undefined」。
 * swoole 之所以能用，是因为 phpx 给它做了绑定；redis 没有。
 *
 * 所以这里直接用 `stream_socket_*` 说 RESP。和 Cluster 用的是同一批原语，
 * AOT 下已验证可用，也不引入扩展依赖。
 *
 * ## 边界
 *
 * - **同步阻塞**。一次命令一个往返；调用方在 `Service::onMessage` 里用它，
 *   整条 worker 会被挡住（本机 ~0.2ms，跨机看 RTT）。要并发就得开协程模式。
 * - 不支持 pipeline / 事务 / 订阅。注册表只需要几条命令读和写。
 * - 单连接、不自动重连：断了就把异常抛给调用方，由它决定重连还是降级。
 */
final class Resp
{
    private string $host;

    private int $port;

    private string $pass;

    private int $db;

    private float $timeout;

    /** @var resource|null */
    private $sock = null;

    public function __construct(string $host = "127.0.0.1", int $port = 6379, string $pass = "", int $db = 0, float $timeout = 1.5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->pass = $pass;
        $this->db = $db;
        $this->timeout = $timeout > 0 ? $timeout : 1.5;
    }

    public function connect(): bool
    {
        if ($this->sock !== null) {
            return true;
        }
        $errno = 0;
        $errstr = "";
        $sock = @stream_socket_client(
            "tcp://" . $this->host . ":" . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, (int) $this->timeout);
        $this->sock = $sock;

        if ($this->pass !== "") {
            try {
                $this->cmd("AUTH", $this->pass);
            } catch (\Throwable $e) {
                $this->close();
                return false;
            }
        }
        if ($this->db > 0) {
            try {
                $this->cmd("SELECT", (string) $this->db);
            } catch (\Throwable $e) {
                $this->close();
                return false;
            }
        }
        return true;
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->sock !== null;
    }

    /**
     * 发一条命令并读回结果。
     *
     * 返回类型刻意写成联合而不是 `mixed`：本仓库 lint 禁 `mixed`，
     * 而且调用方确实只需要这四种形态。
     *
     * @param string ...$args
     * @return string|int|array|null
     */
    public function cmd(string ...$args): string|int|array|null
    {
        if ($this->sock === null) {
            throw new \RuntimeException("redis: 未连接");
        }
        $payload = "*" . count($args) . "\r\n";
        foreach ($args as $a) {
            $payload .= "$" . strlen($a) . "\r\n" . $a . "\r\n";
        }
        $n = @fwrite($this->sock, $payload);
        if ($n === false || $n < strlen($payload)) {
            throw new \RuntimeException("redis: 写入失败");
        }
        return $this->readReply();
    }

    /** @return string|int|array|null */
    private function readReply(): string|int|array|null
    {
        $line = @fgets($this->sock);
        if (!is_string($line) || $line === "") {
            throw new \RuntimeException("redis: 读超时或连接断开");
        }
        $type = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");

        if ($type === "+") {
            return $body;
        }
        if ($type === "-") {
            // 错误回复：抛出去而不是返回 null。null 会被上层当成"键不存在"，
            // 把"命令写错了"和"没有这个键"混为一谈，排障时极难分辨
            throw new \RuntimeException("redis: " . $body);
        }
        if ($type === ":") {
            return (int) $body;
        }
        if ($type === "$") {
            $len = (int) $body;
            if ($len < 0) {
                return null;    // $-1 = nil
            }
            $data = "";
            $need = $len + 2;
            while (strlen($data) < $need) {
                $chunk = @fread($this->sock, $need - strlen($data));
                if (!is_string($chunk) || $chunk === "") {
                    throw new \RuntimeException("redis: 批量串被截断");
                }
                $data .= $chunk;
            }
            return substr($data, 0, $len);
        }
        if ($type === "*") {
            $n = (int) $body;
            if ($n < 0) {
                return null;    // *-1 = nil 数组
            }
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = $this->readReply();
            }
            return $out;
        }
        throw new \RuntimeException("redis: 无法识别的回复类型 [" . $type . "]");
    }
}
