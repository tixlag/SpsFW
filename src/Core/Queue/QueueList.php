<?php

namespace SpsFW\Core\Queue;

class QueueList
{
/**
 * @var array<string, array{queue: string, exchange: string, routing_key: string}>
 */
private array $list;

public function __construct(array $list)
{
    $this->list = $list;
}

    /**
     * @return  array<string, array{queue: string, exchange: string, routing_key: string}>
     */
    public function getConfig($queueName): array
    {
        return $this->list[$queueName];
    }

}