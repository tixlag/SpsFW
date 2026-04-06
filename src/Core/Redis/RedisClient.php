<?php

namespace SpsFW\Core\Redis;

use SpsFW\Core\Config;
use SpsFW\Core\Psr\Cache\FileCache;

class RedisClient
{
    private static ?self $instance = null;
    private ?\Redis $redis = null;
    private bool $usingFallback = false;
    private ?FileCache $fallback = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function tryConnect(): void
    {
        if ($this->redis !== null || $this->usingFallback) {
            return;
        }
        try {
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
        } catch (\Throwable) {
            $this->redis = null;
            $this->usingFallback = true;
            $this->fallback = new FileCache(sys_get_temp_dir() . '/spsFW_redis_fallback');
        }
    }

    public function connection(): \Redis
    {
        $this->tryConnect();
        if ($this->redis === null) {
            throw new \RuntimeException('Redis unavailable');
        }
        return $this->redis;
    }

    /**
     * Вернуть true, если Redis недоступен и активен FileCache-fallback.
     */
    public function isFallback(): bool
    {
        $this->tryConnect();
        return $this->usingFallback;
    }

    public function get(string $key): string|false
    {
        if ($this->isFallback()) {
            $value = $this->fallback->get($key, false);
            return $value === false ? false : (string) $value;
        }
        return $this->connection()->get($key);
    }

    /**
     * Атомарно получить значение и удалить ключ.
     * Redis >= 6.2: native GETDEL.
     * Для старых версий — Lua-скрипт.
     */
    public function getdel(string $key): string|false
    {
        if ($this->isFallback()) {
            $value = $this->fallback->get($key, false);
            if ($value !== false) {
                $this->fallback->delete($key);
            }
            return $value === false ? false : (string) $value;
        }
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
        if ($this->isFallback()) {
            return $this->fallback->set($key, $value);
        }
        return $this->connection()->set($key, $value);
    }

    /**
     * SET key value EX ttl (atomic)
     */
    public function setex(string $key, int $ttl, string $value): bool
    {
        if ($this->isFallback()) {
            return $this->fallback->set($key, $value, $ttl);
        }
        return $this->connection()->setex($key, $ttl, $value);
    }

    /**
     * SET key value NX EX ttl — установить только если ключа нет.
     * Используется для distributed locks и rate limiting.
     */
    public function setnx(string $key, string $value, int $ttl): bool
    {
        if ($this->isFallback()) {
            if ($this->fallback->has($key)) {
                return false;
            }
            return $this->fallback->set($key, $value, $ttl);
        }
        return (bool) $this->connection()->set($key, $value, ['nx', 'ex' => $ttl]);
    }

    public function del(string ...$keys): int
    {
        if ($this->isFallback()) {
            $count = 0;
            foreach ($keys as $key) {
                if ($this->fallback->has($key)) {
                    $this->fallback->delete($key);
                    $count++;
                }
            }
            return $count;
        }
        return $this->connection()->del(...$keys);
    }

    /**
     * Атомарный fixed-window инкремент + установка TTL при первом вызове.
     * Для Redis используется Lua-скрипт, для FileCache-fallback режим best-effort.
     * Возвращает текущее значение счётчика.
     */
    public function incrWithTtl(string $key, int $ttl): int
    {
        if ($this->isFallback()) {
            $windowKey = $key . ':_w';
            if (!$this->fallback->has($windowKey)) {
                $this->fallback->set($windowKey, '1', $ttl);
                $this->fallback->set($key, '1', $ttl + 5);
                return 1;
            }
            $current = (int) ($this->fallback->get($key, '0'));
            $new = $current + 1;
            $this->fallback->set($key, (string) $new, $ttl + 5);
            return $new;
        }

        $lua = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
if count == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return count
LUA;

        return (int) $this->connection()->eval($lua, [$key, (string) $ttl], 1);
    }

    public function incr(string $key): int
    {
        if ($this->isFallback()) {
            $current = (int) ($this->fallback->get($key, '0'));
            $new = $current + 1;
            $this->fallback->set($key, (string) $new);
            return $new;
        }
        return $this->connection()->incr($key);
    }

    public function expire(string $key, int $ttl): bool
    {
        if ($this->isFallback()) {
            $value = $this->fallback->get($key);
            if ($value === null) {
                return false;
            }
            return $this->fallback->set($key, $value, $ttl);
        }
        return $this->connection()->expire($key, $ttl);
    }

    public function exists(string $key): bool
    {
        if ($this->isFallback()) {
            return $this->fallback->has($key);
        }
        return (bool) $this->connection()->exists($key);
    }

    public function ttl(string $key): int
    {
        if ($this->isFallback()) {
            // PSR SimpleCache не предоставляет TTL через API — возвращаем -1 (без TTL)
            return $this->fallback->has($key) ? -1 : -2;
        }
        return $this->connection()->ttl($key);
    }

    /**
     * Выполнить произвольную Lua-команду.
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        if ($this->isFallback()) {
            return null;
        }
        return $this->connection()->eval($script, array_merge($keys, $args), count($keys));
    }

    /**
     * Publish в канал (для Pub/Sub).
     */
    public function publish(string $channel, string $message): int
    {
        if ($this->isFallback()) {
            return 0;
        }
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

