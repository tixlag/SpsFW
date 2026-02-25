<?php

namespace SpsFW\Core\Workers;

use InvalidArgumentException;

class WorkerConfig
{
    /**
     * @var array<string, array{type: string, config: array{queue: string, exchange: string, routing_key: string}}>
     */
    private array $config;


    /**
     * @param array<string, array{type: string, config: array{queue: string, exchange: string, routing_key: string}}> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config['config'] ?? $config;
    }

    /**
     * @param string $workerName
     * @return array{type: string, config: array{queue: string, exchange: string, routing_key: string}}
     */
    public function getConfig(string $workerName): array
    {
        if (!isset($this->config[$workerName]) || !is_array($this->config[$workerName])) {
            throw new InvalidArgumentException("Unknown worker config: {$workerName}");
        }

        return $this->config[$workerName];
    }


    /**
     * @param array $config
     * @return array{queue: string, exchange: string, routing_key: string}|null
     */
    public function getQueueConfig(string $workerName): ?array
    {
        $worker = $this->config[$workerName] ?? null;
        if (!is_array($worker)) {
            return null;
        }

        if (($worker['type'] ?? null) !== 'queueConsumer') {
            return null;
        }

        $config = $worker['config'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        return $config;
    }

    /**
     * @return array<string, array{type: string, config: array}>
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, array>
     */
    public function getQueueWorkers(): array
    {
        $workers = [];
        foreach ($this->config as $workerName => $workerDef) {
            if (($workerDef['type'] ?? null) === 'queueConsumer') {
                $workers[$workerName] = $workerDef['config'] ?? [];
            }
        }

        return $workers;
    }

    /**
     * @return string[]
     */
    public function getQueueWorkerNames(): array
    {
        return array_keys($this->getQueueWorkers());
    }
}
