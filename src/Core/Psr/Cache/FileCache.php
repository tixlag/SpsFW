<?php

namespace SpsFW\Core\Psr\Cache;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

class FileCache implements CacheInterface
{
    private string $cacheDir;
    private int $defaultTtl;

    public function __construct(string $cacheDir = __DIR__ . "/../../../../../../../.cache/tmp", int $defaultTtl = 3600)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $file = $this->getFile($key);

        if (!file_exists($file)) {
            return $default;
        }

        $data = file_get_contents($file);
        if ($data === false) {
            return $default;
        }

        $item = json_decode($data, true);
        if ($item === null) {
            return $default;
        }

        // Проверка TTL
        if ($item['expires_at'] !== null && time() > $item['expires_at']) {
            unlink($file);
            return $default;
        }

        return $item['value'];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $file = $this->getFile($key);

        $expiresAt = null;
        if ($ttl !== null) {
            if ($ttl instanceof \DateInterval) {
                $expiresAt = (new \DateTime())->add($ttl)->getTimestamp();
            } else {
                $expiresAt = time() + $ttl;
            }
        }

        $item = [
            'value' => $value,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ];

        $data = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($data === false) {
            return false;
        }

        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $file = $this->getFile($key);
        return file_exists($file) && unlink($file);
    }

    public function clear(): bool
    {
        $files = glob($this->cacheDir . '/*.cache');
        if ($files === false) {
            return true;
        }

        $success = true;
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
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
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
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
        $success = true;

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('All keys must be strings');
            }
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        $file = $this->getFile($key);

        if (!file_exists($file)) {
            return false;
        }

        $data = file_get_contents($file);
        if ($data === false) {
            return false;
        }

        $item = json_decode($data, true);
        if ($item === null) {
            return false;
        }

        return $item['expires_at'] === null || time() <= $item['expires_at'];
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        if (preg_match('/[{}()\/@]/', $key)) {
            throw new InvalidArgumentException('Cache key contains illegal characters');
        }
    }

    private function getFile(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
}