<?php

namespace SpsFW\Core\Psr\Cache;

use DateInterval;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use SpsFW\Core\Redis\RedisClient;

/**
 * PSR-16 CacheInterface implementation backed by Redis.
 *
 * When Redis is unavailable, RedisClient automatically falls back to FileCache,
 * so this class continues to work transparently.
 *
 * Key sanitization ensures compatibility with FileCache fallback (which rejects
 * {}()/@ characters).
 *
 * Usage in client code:
 *   Config::setDIBindings([
 *       CacheInterface::class => RedisCache::class,
 *   ]);
 *
 *   // Or with custom prefix:
 *   Config::setDIBindings([
 *       CacheInterface::class => fn() => new RedisCache(keyPrefix: 'myapp:'),
 *   ]);
 */
class RedisCache implements CacheInterface
{
    private RedisClient $redis;
    private string $keyPrefix;

    public function __construct(
        string $keyPrefix = '',
        ?RedisClient $redis = null,
    ) {
        $this->keyPrefix = $keyPrefix;
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    /**
     * Sanitize cache key for FileCache compatibility.
     *
     * FileCache::getFile() replaces any character except [a-zA-Z0-9_\-.] with '_'.
     * FileCache::validateKey() also rejects {}()/@ characters.
     *
     * This method is only applied when RedisClient is in fallback mode.
     * When Redis is available, keys are passed through unchanged.
     */
    private function sanitizeKey(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        // Match FileCache::getFile() behavior: only allow [a-zA-Z0-9_\-.]
        return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $key);
    }

    /**
     * Build the full prefixed key.
     * Sanitization is deferred until we know whether we're in fallback mode.
     */
    private function buildKey(string $key, bool $sanitize = true): string
    {
        $fullKey = $this->keyPrefix . $key;
        return $sanitize ? $this->sanitizeKey($fullKey) : $fullKey;
    }

    /**
     * Serialize value for storage.
     *
     * Redis stores strings only. We serialize non-string values
     * so they can be restored on retrieval.
     */
    private function serialize(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        // Prefix with a marker so we can distinguish serialized from plain strings
        return '@ser:' . serialize($value);
    }

    /**
     * Unserialize value retrieved from storage.
     */
    private function unserialize(string $value): mixed
    {
        if (str_starts_with($value, '@ser:')) {
            $serialized = substr($value, 5);
            $result = @unserialize($serialized);
            if ($result !== false || $serialized === 'b:0;') {
                return $result;
            }
            // If unserialize fails, return the raw string
            return $value;
        }

        return $value;
    }

    /**
     * Resolve TTL to integer seconds.
     */
    private function resolveTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $end = $now->add($ttl);
            return (int) $end->getTimestamp() - (int) $now->getTimestamp();
        }

        return $ttl > 0 ? $ttl : null;
    }

    // ─── CacheInterface Implementation ───────────────────────────────────────

    /**
     * @throws InvalidArgumentException
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $isFallback = $this->redis->isFallback();
        $fullKey = $this->buildKey($key, $isFallback);

        if ($isFallback) {
            // FileCache handles mixed values natively
            return $this->redis->get($fullKey) ?: $default;
        }

        $value = $this->redis->get($fullKey);
        if ($value === false) {
            return $default;
        }

        return $this->unserialize($value);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $isFallback = $this->redis->isFallback();
        $fullKey = $this->buildKey($key, $isFallback);
        $serialized = $this->serialize($value);
        $ttlSeconds = $this->resolveTtl($ttl);

        if ($isFallback) {
            if ($ttlSeconds !== null) {
                return $this->redis->setex($fullKey, $ttlSeconds, $serialized);
            }
            return $this->redis->set($fullKey, $serialized);
        }

        if ($ttlSeconds !== null) {
            return $this->redis->setex($fullKey, $ttlSeconds, $serialized);
        }

        return $this->redis->set($fullKey, $serialized);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function delete(string $key): bool
    {
        $isFallback = $this->redis->isFallback();
        $fullKey = $this->buildKey($key, $isFallback);
        return $this->redis->del($fullKey) > 0;
    }

    public function clear(): bool
    {
        if ($this->redis->isFallback()) {
            // Can't easily clear only our prefixed keys in FileCache fallback
            // without tracking them separately.
            return false;
        }

        try {
            $script = <<<LUA
                local keys = redis.call('keys', ARGV[1] .. '*')
                if #keys > 0 then
                    redis.call('del', unpack(keys))
                end
                return #keys
            LUA;

            $this->redis->eval($script, [], [$this->keyPrefix]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('All keys must be strings');
            }
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('All keys must be strings');
            }
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $isFallback = $this->redis->isFallback();
        $keysArray = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('All keys must be strings');
            }
            $keysArray[] = $this->buildKey($key, $isFallback);
        }

        if (empty($keysArray)) {
            return true;
        }

        return $this->redis->del(...$keysArray) === count($keysArray);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function has(string $key): bool
    {
        $isFallback = $this->redis->isFallback();
        $fullKey = $this->buildKey($key, $isFallback);
        return $this->redis->exists($fullKey);
    }
}
