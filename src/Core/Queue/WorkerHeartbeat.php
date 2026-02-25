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
        $this->cache->set($this->workerHeartbeatKey(), $now, $this->ttl);

        if ($this->instanceId !== null) {
            $this->touchInstance($this->instanceId, $now);
            $this->cache->set($this->workerHeartbeatKey($this->instanceId), $now, $this->ttl);
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

        $this->cache->set($this->workerStatusKey(), $statusPayload, $this->ttl);

        if ($this->instanceId !== null) {
            $this->touchInstance($this->instanceId, $statusPayload['updated_at']);
            $this->cache->set($this->workerStatusKey($this->instanceId), $statusPayload, $this->ttl);
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

        $this->cache->set($this->workerErrorKey(), $errorPayload, $this->ttl * 10);

        if ($this->instanceId !== null) {
            $this->touchInstance($this->instanceId, $errorPayload['timestamp']);
            $this->cache->set($this->workerErrorKey($this->instanceId), $errorPayload, $this->ttl * 10);
        }
    }

    public function isAlive(): bool
    {
        if ($this->instanceId !== null) {
            $lastBeat = (int)($this->cache->get($this->workerHeartbeatKey($this->instanceId)) ?? 0);
            return $lastBeat > 0 && (time() - $lastBeat) < $this->ttl * 2;
        }

        if ($this->getInstancesStatuses() !== []) {
            return true;
        }

        $lastBeat = (int)($this->cache->get($this->workerHeartbeatKey()) ?? 0);
        return $lastBeat > 0 && (time() - $lastBeat) < $this->ttl * 2;
    }

    public function getStatus(): ?array
    {
        if ($this->instanceId !== null) {
            return $this->cache->get($this->workerStatusKey($this->instanceId));
        }

        $status = $this->cache->get($this->workerStatusKey());
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
            return $this->cache->get($this->workerErrorKey($this->instanceId));
        }

        $error = $this->cache->get($this->workerErrorKey());
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

            $lastBeat = (int)($this->cache->get($this->workerHeartbeatKey($instanceId)) ?? 0);
            if ($lastBeat <= 0 || ($now - $lastBeat) >= ($this->ttl * 2)) {
                unset($instances[$instanceId]);
                $dirty = true;
                continue;
            }

            $status = $this->cache->get($this->workerStatusKey($instanceId));
            $lastError = $this->cache->get($this->workerErrorKey($instanceId));

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
            $this->cache->delete($this->workerHeartbeatKey($this->instanceId));
            $this->cache->delete($this->workerStatusKey($this->instanceId));
            $this->cache->delete($this->workerErrorKey($this->instanceId));

            $instances = $this->getInstancesIndex();
            unset($instances[$this->instanceId]);
            $this->setInstancesIndex($instances);
            return;
        }

        $this->cache->delete($this->workerHeartbeatKey());
        $this->cache->delete($this->workerStatusKey());
        $this->cache->delete($this->workerErrorKey());

        $instances = $this->getInstancesIndex();
        foreach (array_keys($instances) as $instanceId) {
            if (!is_string($instanceId) || $instanceId === '') {
                continue;
            }

            $this->cache->delete($this->workerHeartbeatKey($instanceId));
            $this->cache->delete($this->workerStatusKey($instanceId));
            $this->cache->delete($this->workerErrorKey($instanceId));
        }

        $this->cache->delete($this->instancesIndexKey());
    }

    private function workerHeartbeatKey(?string $instanceId = null): string
    {
        if ($instanceId === null) {
            return "worker:heartbeat:{$this->workerId}";
        }

        return "worker:heartbeat:{$this->workerId}:{$instanceId}";
    }

    private function workerStatusKey(?string $instanceId = null): string
    {
        if ($instanceId === null) {
            return "worker:status:{$this->workerId}";
        }

        return "worker:status:{$this->workerId}:{$instanceId}";
    }

    private function workerErrorKey(?string $instanceId = null): string
    {
        if ($instanceId === null) {
            return "worker:error:{$this->workerId}";
        }

        return "worker:error:{$this->workerId}:{$instanceId}";
    }

    private function instancesIndexKey(): string
    {
        return "worker:instances:{$this->workerId}";
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
        $raw = $this->cache->get($this->instancesIndexKey(), []);
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
        $this->cache->set($this->instancesIndexKey(), $instances, $ttl);
    }
}
