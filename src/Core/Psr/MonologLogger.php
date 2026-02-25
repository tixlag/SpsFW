<?php

namespace SpsFW\Core\Psr;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class MonologLogger implements LoggerInterface
{
    private ?Logger $logger = null;
    private FileLogger $fallbackLogger;

    public function __construct(
        string $logFile = __DIR__ . "/../../../../../../.logs/sps-fw.log",
        string $channel = 'spsfw',
        $level = 100
    ) {
        $this->fallbackLogger = new FileLogger($logFile);

        if (!class_exists(Logger::class) || !class_exists(StreamHandler::class) || !class_exists(JsonFormatter::class)) {
            return;
        }

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handler = new StreamHandler($logFile, $level, true, 0644);
        $handler->setFormatter(new JsonFormatter());

        $this->logger = new Logger($channel);
        $this->logger->pushHandler($handler);
    }

    public function emergency($message, array $context = []): void
    {
        $this->write(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->write(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->write(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->write(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->write(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->write(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->write(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->write(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->write($level, $message, $context);
    }

    private function write($level, $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, (string)$message, $context);
            return;
        }

        $this->fallbackLogger->log($level, (string)$message, $context);
    }
}
