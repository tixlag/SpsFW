<?php

namespace SpsFW\Core\Workers;

class WorkerConfig
{
    /**
     * @var array<string, array{type?: string, queue: string, exchange: string, routing_key: string}>
     */
    private array $config;


    /**
     * @param array<string, array{type?: string, queue: string, exchange: string, routing_key: string}> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @param string $workerName
     * @return array{type?: string, queue: string, exchange: string, routing_key: string}
     */
    public function getConfig($workerName): array
    {
        return $this->config[$workerName];
    }
}
