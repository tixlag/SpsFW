<?php

namespace SpsFW\Core\Redis;

use SpsFW\Core\Config;

class RedisClient
{
    private static ?self $instance = null;
    private ?\Redis $redis = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function connection(): \Redis
    {
        if ($this->redis === null) {
            $cfg = Config::get('redis');
            $this->redis = new \Redis();
            $this->redis->connect(
                $cfg['host'] ?? '127.0.0.1',
                (int) ($cfg['port'] ?? 6379),
                (float) ($cfg['timeout'] ?? 2.0)
            );
            if (!empty($cfg['password'])) {
                $this->redis->auth($cfg['password']);
            }
            if (!empty($cfg['database'])) {
                $this->redis->select((int) $cfg['database']);
            }
            $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        }
        return $this->redis;
    }

    public function get(string $key): string|false
    {
        return $this->connection()->get($key);
    }

    /**
     * Атомарно получить значение и удалить ключ.
     * Redis >= 6.2: native GETDEL.
     * Для старых версий — Lua-скрипт.
     */
    public function getdel(string $key): string|false
    {
        try {
            $result = $this->connection()->rawCommand('GETDEL', $key);
            return $result === null ? false : $result;
        } catch (\Throwable) {
            $lua = "local v = redis.call('GET', KEYS[1]); if v then redis.call('DEL', KEYS[1]) end; return v";
            $result = $this->connection()->eval($lua, [$key], 1);
            return $result === null ? false : $result;
        }
    }

    public function set(string $key, string $value): bool
    {
        return $this->connection()->set($key, $value);
    }

    /**
     * SET key value EX ttl (atomic)
     */
    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->connection()->setex($key, $ttl, $value);
    }

    /**
     * SET key value NX EX ttl — установить только если ключа нет.
     * Используется для distributed locks и rate limiting.
     */
    public function setnx(string $key, string $value, int $ttl): bool
    {
        return (bool) $this->connection()->set($key, $value, ['nx', 'ex' => $ttl]);
    }

    public function del(string ...$keys): int
    {
        return $this->connection()->del(...$keys);
    }

    /**
     * Атомарный инкремент + установка TTL при первом вызове.
     * Используется для rate limiting.
     * Возвращает текущее значение счётчика.
     */
    public function incrWithTtl(string $key, int $ttl): int
    {
        $count = $this->connection()->incr($key);
        if ($count === 1) {
            $this->connection()->expire($key, $ttl);
        }
        return $count;
    }

    public function incr(string $key): int
    {
        return $this->connection()->incr($key);
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->connection()->expire($key, $ttl);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->connection()->exists($key);
    }

    public function ttl(string $key): int
    {
        return $this->connection()->ttl($key);
    }

    /**
     * Выполнить произвольную Lua-команду.
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->connection()->eval($script, array_merge($keys, $args), count($keys));
    }

    /**
     * Publish в канал (для Pub/Sub).
     */
    public function publish(string $channel, string $message): int
    {
        return $this->connection()->publish($channel, $message);
    }

    /**
     * Закрыть соединение (вызывать в конце скрипта / после pcntl_fork в воркерах).
     */
    public function close(): void
    {
        if ($this->redis !== null) {
            $this->redis->close();
            $this->redis = null;
        }
    }
}
