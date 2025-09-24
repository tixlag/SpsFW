<?php

namespace SpsFW\Core\Queue;

use Psr\SimpleCache\CacheInterface;

class WorkerHeartbeat
{
    private CacheInterface $cache;
    private string $workerId;
    private int $ttl;

    public function __construct(CacheInterface $cache, string $workerId, int $ttl = 60)
    {
        $this->cache = $cache;
        $this->workerId = $workerId;
        $this->ttl = $ttl;
    }

    public function beat(): void
    {
        $this->cache->set("worker:heartbeat:{$this->workerId}", time(), $this->ttl);
    }

    public function setStatus(string $status, array $data = []): void
    {
        $this->cache->set("worker:status:{$this->workerId}", [
            'status' => $status,
            'pid' => getmypid(),
            'updated_at' => time(),
            'data' => $data
        ], $this->ttl);
    }

    public function setError(string $error): void
    {
        $this->cache->set("worker:error:{$this->workerId}", [
            'error' => $error,
            'timestamp' => time()
        ], $this->ttl * 10); // Храним ошибки дольше
    }

    public function isAlive(): bool
    {
        $lastBeat = $this->cache->get("worker:heartbeat:{$this->workerId}");
        return $lastBeat && (time() - $lastBeat) < $this->ttl * 2;
    }

    public function getStatus(): ?array
    {
        return $this->cache->get("worker:status:{$this->workerId}");
    }

    public function getLastError(): ?array
    {
        return $this->cache->get("worker:error:{$this->workerId}");
    }

    public function clear(): void
    {
        $this->cache->delete("worker:heartbeat:{$this->workerId}");
        $this->cache->delete("worker:status:{$this->workerId}");
        $this->cache->delete("worker:error:{$this->workerId}");
    }
}