<?php

namespace SpsFW\Core\Queue;

class RabbitMQConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $password,
        public string $vhost = '/',
        public float $connectionTimeout = 3.0,
        public float $readWriteTimeout = 3.0,
        public int $heartbeat = 0
    ) {}
}