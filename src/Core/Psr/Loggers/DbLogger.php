<?php

namespace SpsFW\Core\Psr\Loggers;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use PDO;
use SpsFW\Core\Db\Db;

class DbLogger implements LoggerInterface
{
    private PDO $db;
    private string $tableName;
    private int $limitContext;
    private array $config;

    public function __construct(
        string $tableName = 'logs',
        int $limitContext = 0,
        array $config = []
    ) {
        $this->db = Db::get();
        $this->tableName = $tableName;
        $this->limitContext = $limitContext;
        $this->config = array_merge([
            'include_ip' => true,
            'include_backtrace' => false,
            'max_message_length' => 5000,
        ], $config);

        $this->createTableIfNotExists();
    }

    private function createTableIfNotExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(20) NOT NULL,
            channel VARCHAR(100) DEFAULT 'application',
            remote_addr VARCHAR(45) NULL,
            message TEXT NOT NULL,
            context JSON,
            INDEX idx_level (level),
            INDEX idx_timestamp (timestamp),
            INDEX idx_channel (channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function log($level, $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $remoteAddr = $this->config['include_ip'] ? ($_SERVER['REMOTE_ADDR'] ?? 'CLI') : null;
        $channel = $context['channel'] ?? 'application';

        unset($context['channel']);

        if ($this->limitContext > 0) {
            $context = $this->limitContext($context, $this->limitContext);
        }

        if ($this->config['include_backtrace'] && $level === LogLevel::ERROR) {
            $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        }

        $message = substr($message, 0, $this->config['max_message_length']);

        $contextJson = !empty($context)
            ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $stmt = $this->db->prepare("
            INSERT INTO {$this->tableName} 
            (timestamp, level, channel, remote_addr, message, context) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $timestamp,
            strtoupper($level),
            $channel,
            $remoteAddr,
            $message,
            $contextJson
        ]);
    }

    private function limitContext(array $context, int $maxItems = 100): array
    {
        $result = [];
        $count = 0;
        foreach ($context as $key => $value) {
            if ($count >= $maxItems) {
                $result['...'] = 'truncated: ' . (count($context) - $maxItems) . ' more items';
                break;
            }

            if (is_object($value) && !method_exists($value, '__toString')) {
                $value = ['__class' => get_class($value)];
            }

            $result[$key] = is_array($value)
                ? $this->limitContext($value, $maxItems)
                : $value;
            $count++;
        }
        return $result;
    }

    // Методы PSR-3
    public function emergency($message, array $context = []): void {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}