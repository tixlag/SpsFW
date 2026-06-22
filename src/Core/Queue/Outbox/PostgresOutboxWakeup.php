<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

use PDO;

final class PostgresOutboxWakeup implements OutboxWakeupInterface
{
    private bool $listening = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $channel = 'spsfw_outbox',
    ) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $channel)) {
            throw new \InvalidArgumentException('Invalid PostgreSQL notification channel.');
        }
    }

    public function notify(\DateTimeImmutable $availableAt): void
    {
        $statement = $this->pdo->prepare('SELECT pg_notify(?, ?)');
        $statement->execute([$this->channel, $availableAt->format(DATE_ATOM)]);
    }

    public function wait(int $timeoutMilliseconds): void
    {
        if (!$this->listening) {
            $this->pdo->exec('LISTEN ' . $this->channel);
            $this->listening = true;
        }

        if (!method_exists($this->pdo, 'pgsqlGetNotify')) {
            usleep(max(1, $timeoutMilliseconds) * 1000);
            return;
        }

        $this->pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, max(0, $timeoutMilliseconds));
    }
}
