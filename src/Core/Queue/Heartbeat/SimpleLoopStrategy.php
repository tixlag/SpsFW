<?php

namespace SpsFW\Core\Queue\Heartbeat;

use SpsFW\Core\Queue\RabbitMQWorkerRunner;

class SimpleLoopStrategy implements WorkerStrategyInterface
{
    public function run(RabbitMQWorkerRunner $runner): void
    {
        $runner->start();

        while ($runner->isRunning()) {
            $runner->runIteration();
        }

        $runner->stop();
    }
}
