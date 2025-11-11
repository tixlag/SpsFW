<?php

namespace SpsFW\Core\Psr;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class FileLogger implements LoggerInterface
{
    private string $logFile;

    public function __construct(string $logFile = __DIR__ . "/../../../../../../.logs/sps-fw.log")
    {
        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->logFile = $logFile;
    }

    public function log($level, $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $formatted = sprintf(
            "[%s] [%s] %s - %s\n%s\n\n",
            $timestamp,
            strtoupper($level),
            $remoteAddr,
            $message,
            json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |JSON_UNESCAPED_UNICODE)
        );

        file_put_contents($this->logFile, $formatted, FILE_APPEND | LOCK_EX);
    }

    private function formatMessage(string $message, array $context): string
    {
        foreach ($context as $key => $value) {
            if (is_object($value)) {
                $value = get_class($value);
            } elseif (is_array($value)) {
                $value = var_export($value, true);
            }
            $message = str_replace('{'.$key.'}', $value ?? '', $message);
        }
        return $message;
    }

    // Остальные методы PSR-3 — они просто делегируют в log()
    public function emergency($message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    public function alert($message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }
    public function critical($message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }
    public function error($message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }
    public function warning($message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }
    public function notice($message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }
    public function info($message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }
    public function debug($message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }
}