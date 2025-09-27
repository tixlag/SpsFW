<?php

namespace SpsFW\Core\Workers;

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
        $this->config = $config;
    }

    /**
     * @param string $workerName
     * @return array{type: string, config: array{queue: string, exchange: string, routing_key: string}}
     */
    public function getConfig(string $workerName): array
    {
        return $this->config[$workerName];
    }


    /**
     * @param array $config
     * @return array{queue: string, exchange: string, routing_key: string}|null
     */
    public function getQueueConfig(string $workerName): ?array
    {
        if ($this->config[$workerName]['type'] == 'queueConsumer')
            return $this->config[$workerName]['config'];
        else
            return null;
    }
}
