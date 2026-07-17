<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

use PDO;

final class TransactionManager
{
    private int $depth = 0;

    /** @var list<callable(): void> */
    private array $afterCommitCallbacks = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transactional(callable $callback): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $callback();
            } finally {
                $this->depth--;
            }
        }

        $startedHere = !$this->pdo->inTransaction();
        if ($startedHere) {
            $this->pdo->beginTransaction();
        }
        $this->depth = 1;

        try {
            $result = $callback();
            $this->depth = 0;

            if (!$startedHere) {
                if ($this->afterCommitCallbacks !== []) {
                    throw new \LogicException('afterCommit callbacks require a transaction owned by TransactionManager.');
                }
                return $result;
            }

            $this->pdo->commit();
            $callbacks = $this->afterCommitCallbacks;
            $this->afterCommitCallbacks = [];
            foreach ($callbacks as $afterCommit) {
                $afterCommit();
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->depth = 0;
            $this->afterCommitCallbacks = [];
            if ($startedHere && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function afterCommit(callable $callback): void
    {
        if ($this->depth === 0) {
            $callback();
            return;
        }

        $this->afterCommitCallbacks[] = $callback;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Whether this manager owns the exact PDO connection used by a transactional participant.
     *
     * PDO transactions are connection-local. Two PDO objects may point at the same database and
     * still cannot share a transaction, so identity comparison is intentional here.
     */
    public function manages(PDO $pdo): bool
    {
        return $this->pdo === $pdo;
    }

    /**
     * Fail fast when a storage cannot participate in this manager's transaction.
     */
    public function assertManages(PDO $pdo): void
    {
        if (!$this->manages($pdo)) {
            throw new \LogicException(
                'TransactionManager and transactional storage must use the same PDO instance.',
            );
        }
    }
}
