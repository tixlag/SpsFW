<?php

namespace SpsFW\Core\Queue;

use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;

class RabbitMQQueuePublisher implements QueuePublisherInterface
{
    private RabbitMQClient $client;
    private string $defaultRoutingKey;

    public function __construct(RabbitMQClient $client, string $defaultRoutingKey = '')
    {
        $this->client = $client;
        $this->defaultRoutingKey = $defaultRoutingKey;
    }

    public function publish(JobInterface $job): void
    {
        $payload = [
            'jobName' => $job->getName(),
            'payload' => $job->serialize(),
        ];

        $this->client->publish($payload, [], $this->defaultRoutingKey);
    }
}