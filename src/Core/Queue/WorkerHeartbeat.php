<?php

namespace SpsFW\Core\Queue;

use Psr\SimpleCache\CacheInterface;

class WorkerHeartbeat
{
    private const INSTANCE_INDEX_TTL_FACTOR = 20;

    private CacheInterface $cache;
    private string $workerId;
    private int $ttl;
    private ?string $instanceId;

    public function __construct(CacheInterface $cache, string $workerId, int $ttl = 60, ?string $instanceId = null)
    {
        $this->cache = $cache;
        $this->workerId = $workerId;
        $this->ttl = $ttl;
        $this->instanceId = $instanceId;
    }

    public function attachInstance(string $instanceId): void
    {
        $this->instanceId = $instanceId;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    public function beat(): void
    {
        $now = time();
        $this->cacheSet($this->workerHeartbeatKey(), $now, $this->ttl);

        if ($this->instanceId !== null) {
            $this->touchInstance($this->instanceId, $now);
            $this->cacheSet($this->workerHeartbeatKey($this->instanceId), $now, $this->ttl);
        }
    }

    public function setStatus(string $status, array $data = []): void
    {
        $statusPayload = [
            'status' => $status,
            'pid' => getmypid(),
            'hostname' => gethostname() ?: 'unknown',
            'instance_id' => $this->instanceId,
            'updated_at' => time(),
            'data' => $data
        ];

        $this->cacheSet($this->workerStatusKey(), $statusPayload, $this->ttl);

        if ($this->instanceId !== null) {
            $this->touchInstance($this->instanceId, $statusPayload['updated_at']);
            $this->cacheSet($this->workerStatusKey($this->instanceId), $statusPayload, $this->ttl);
        }
    }

    public function setError(string $error): void
    {
        $errorPayload = [
            'error' => $error,
            'pid' => getmypid(),
            'hostname' => gethostname() ?: 'unknown',
            'instance_id' => $this->instanceId,
            'timestamp' => time()
        ];

        $this->cacheSet($this->workerErrorKey(), $errorPayload, $this->ttl * 10);

        if ($this->instanceId !== null) {
            $this->touchInstance($this->instanceId, $errorPayload['timestamp']);
            $this->cacheSet($this->workerErrorKey($this->instanceId), $errorPayload, $this->ttl * 10);
        }
    }

    public function isAlive(): bool
    {
        if ($this->instanceId !== null) {
            $lastBeat = (int)($this->cacheGet($this->workerHeartbeatKey($this->instanceId), 0) ?? 0);
            return $lastBeat > 0 && (time() - $lastBeat) < $this->ttl * 2;
        }

        if ($this->getInstancesStatuses() !== []) {
            return true;
        }

        $lastBeat = (int)($this->cacheGet($this->workerHeartbeatKey(), 0) ?? 0);
        return $lastBeat > 0 && (time() - $lastBeat) < $this->ttl * 2;
    }

    public function getStatus(): ?array
    {
        if ($this->instanceId !== null) {
            $status = $this->cacheGet($this->workerStatusKey($this->instanceId));
            return is_array($status) ? $status : null;
        }

        $status = $this->cacheGet($this->workerStatusKey());
        if (is_array($status)) {
            return $status;
        }

        $instances = $this->getInstancesStatuses();
        if ($instances === []) {
            return null;
        }

        $first = reset($instances);
        return is_array($first) ? ($first['status'] ?? null) : null;
    }

    public function getLastError(): ?array
    {
        if ($this->instanceId !== null) {
            $error = $this->cacheGet($this->workerErrorKey($this->instanceId));
            return is_array($error) ? $error : null;
        }

        $error = $this->cacheGet($this->workerErrorKey());
        if (is_array($error)) {
            return $error;
        }

        $instances = $this->getInstancesStatuses();
        if ($instances === []) {
            return null;
        }

        $first = reset($instances);
        return is_array($first) ? ($first['last_error'] ?? null) : null;
    }

    /**
     * @return array<string, array{
     *     instance_id: string,
     *     alive: bool,
     *     last_beat: int,
     *     status: mixed,
     *     last_error: mixed
     * }>
     */
    public function getInstancesStatuses(): array
    {
        $instances = $this->getInstancesIndex();
        $result = [];
        $dirty = false;
        $now = time();

        foreach ($instances as $instanceId => $lastSeen) {
            if (!is_string($instanceId) || $instanceId === '') {
                $dirty = true;
                continue;
            }

            $lastBeat = (int)($this->cacheGet($this->workerHeartbeatKey($instanceId), 0) ?? 0);
            if ($lastBeat <= 0 || ($now - $lastBeat) >= ($this->ttl * 2)) {
                unset($instances[$instanceId]);
                $dirty = true;
                continue;
            }

            $status = $this->cacheGet($this->workerStatusKey($instanceId));
            $lastError = $this->cacheGet($this->workerErrorKey($instanceId));

            $result[$instanceId] = [
                'instance_id' => $instanceId,
                'alive' => true,
                'last_beat' => $lastBeat,
                'status' => $status,
                'last_error' => $lastError,
            ];
        }

        if ($dirty) {
            $this->setInstancesIndex($instances);
        }

        uasort($result, static function (array $left, array $right): int {
            return ($right['last_beat'] ?? 0) <=> ($left['last_beat'] ?? 0);
        });

        return $result;
    }

    public function clear(): void
    {
        if ($this->instanceId !== null) {
            $this->cacheDelete($this->workerHeartbeatKey($this->instanceId));
            $this->cacheDelete($this->workerStatusKey($this->instanceId));
            $this->cacheDelete($this->workerErrorKey($this->instanceId));

            $instances = $this->getInstancesIndex();
            unset($instances[$this->instanceId]);
            $this->setInstancesIndex($instances);
            return;
        }

        $this->cacheDelete($this->workerHeartbeatKey());
        $this->cacheDelete($this->workerStatusKey());
        $this->cacheDelete($this->workerErrorKey());

        $instances = $this->getInstancesIndex();
        foreach (array_keys($instances) as $instanceId) {
            if (!is_string($instanceId) || $instanceId === '') {
                continue;
            }

            $this->cacheDelete($this->workerHeartbeatKey($instanceId));
            $this->cacheDelete($this->workerStatusKey($instanceId));
            $this->cacheDelete($this->workerErrorKey($instanceId));
        }

        $this->cacheDelete($this->instancesIndexKey());
    }

    private function workerHeartbeatKey(?string $instanceId = null): string
    {
        $workerToken = $this->workerCacheToken();

        if ($instanceId === null) {
            return "worker.heartbeat.{$workerToken}";
        }

        return "worker.heartbeat.{$workerToken}.{$this->instanceCacheToken($instanceId)}";
    }

    private function workerStatusKey(?string $instanceId = null): string
    {
        $workerToken = $this->workerCacheToken();

        if ($instanceId === null) {
            return "worker.status.{$workerToken}";
        }

        return "worker.status.{$workerToken}.{$this->instanceCacheToken($instanceId)}";
    }

    private function workerErrorKey(?string $instanceId = null): string
    {
        $workerToken = $this->workerCacheToken();

        if ($instanceId === null) {
            return "worker.error.{$workerToken}";
        }

        return "worker.error.{$workerToken}.{$this->instanceCacheToken($instanceId)}";
    }

    private function instancesIndexKey(): string
    {
        return "worker.instances." . $this->workerCacheToken();
    }

    private function touchInstance(string $instanceId, int $timestamp): void
    {
        $instances = $this->getInstancesIndex();
        $instances[$instanceId] = $timestamp;
        $this->setInstancesIndex($instances);
    }

    /**
     * @return array<string, int>
     */
    private function getInstancesIndex(): array
    {
        $raw = $this->cacheGet($this->instancesIndexKey(), []);
        if (!is_array($raw)) {
            return [];
        }

        $index = [];
        foreach ($raw as $instanceId => $lastSeen) {
            if (is_string($instanceId) && $instanceId !== '' && is_numeric($lastSeen)) {
                $index[$instanceId] = (int)$lastSeen;
            }
        }

        return $index;
    }

    /**
     * @param array<string, int> $instances
     */
    private function setInstancesIndex(array $instances): void
    {
        $ttl = max($this->ttl * self::INSTANCE_INDEX_TTL_FACTOR, $this->ttl * 2);
        $this->cacheSet($this->instancesIndexKey(), $instances, $ttl);
    }

    private function instanceCacheToken(string $instanceId): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $instanceId);
        if (!is_string($normalized) || $normalized === '') {
            $normalized = 'instance';
        }

        if (strlen($normalized) > 80) {
            $normalized = substr($normalized, 0, 80);
        }

        return sprintf('%s_%s', $normalized, substr(sha1($instanceId), 0, 10));
    }

    private function workerCacheToken(): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $this->workerId);
        if (!is_string($normalized) || $normalized === '') {
            $normalized = 'worker';
        }

        if (strlen($normalized) > 80) {
            $normalized = substr($normalized, 0, 80);
        }

        return sprintf('%s_%s', $normalized, substr(sha1($this->workerId), 0, 10));
    }

    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        try {
            $this->cache->set($key, $value, $ttl);
        } catch (\Throwable) {
            // Ignore cache write failures to avoid crashing worker loop.
        }
    }

    private function cacheGet(string $key, mixed $default = null): mixed
    {
        try {
            return $this->cache->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function cacheDelete(string $key): void
    {
        try {
            $this->cache->delete($key);
        } catch (\Throwable) {
            // Ignore cache delete failures in cleanup paths.
        }
    }
}
