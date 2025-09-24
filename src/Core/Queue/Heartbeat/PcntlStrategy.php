<?php

namespace SpsFW\Core\Queue\Heartbeat;

use SpsFW\Core\Queue\RabbitMQWorkerRunner;

class PcntlStrategy implements WorkerStrategyInterface
{
    private bool $running = true;
    private bool $gracefulShutdown = false;

    public function __construct()
    {
        if (!extension_loaded('pcntl')) {
            throw new \RuntimeException('PCNTL extension is not loaded');
        }

        pcntl_async_signals(true);

        // Graceful shutdown
        pcntl_signal(SIGTERM, function() {
            $this->gracefulShutdown = true;
            $this->running = false;
        });

        // Immediate shutdown
        pcntl_signal(SIGINT, function() {
            $this->running = false;
        });

        // Reload config (USR1)
        pcntl_signal(SIGUSR1, function() {
            // Можно добавить логику перезагрузки конфигурации
        });
    }

    public function run(RabbitMQWorkerRunner $runner): void
    {
        $runner->start();

        while ($this->running) {
            $runner->runIteration();

            if ($this->gracefulShutdown && !$runner->isRunning()) {
                break;
            }
        }

        $runner->stop();
    }
}