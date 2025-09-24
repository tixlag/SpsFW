<?php

namespace SpsFW\Core\Queue\Heartbeat;

use SpsFW\Core\Queue\RabbitMQWorkerRunner;

interface WorkerStrategyInterface
{
    public function run(RabbitMQWorkerRunner $runner): void;
}