<?php

declare(strict_types=1);

use SpsFW\Core\Queue\Outbox\TransactionManager;

require_once dirname(__DIR__) . '/bootstrap.php';

final class TransactionManagerPdo extends PDO
{
    public bool $transaction = false;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        $this->begins++;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        $this->commits++;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        $this->rollbacks++;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }
}

$pdo = new TransactionManagerPdo();
$manager = new TransactionManager($pdo);
$events = [];

$result = $manager->transactional(function () use ($manager, &$events): string {
    $manager->afterCommit(static function () use (&$events): void {
        $events[] = 'committed';
    });
    assert_same([], $events, 'afterCommit does not run inside transaction');
    return 'ok';
});

assert_same('ok', $result, 'transaction returns callback result');
assert_same(['committed'], $events, 'afterCommit runs after commit');
assert_same(1, $pdo->begins, 'root transaction begins once');
assert_same(1, $pdo->commits, 'root transaction commits once');

$manager->transactional(function () use ($manager): void {
    $manager->transactional(static fn (): null => null);
});
assert_same(2, $pdo->begins, 'nested transaction does not begin independently');
assert_same(2, $pdo->commits, 'nested transaction does not commit independently');

try {
    $manager->transactional(function () use ($manager, &$events): void {
        $manager->afterCommit(static function () use (&$events): void {
            $events[] = 'must-not-run';
        });
        throw new RuntimeException('rollback');
    });
    throw new RuntimeException('exception was not propagated');
} catch (RuntimeException $exception) {
    assert_same('rollback', $exception->getMessage(), 'transaction propagates callback error');
}

assert_same(1, $pdo->rollbacks, 'failed root transaction rolls back');
assert_same(['committed'], $events, 'rollback discards afterCommit callbacks');

echo "Transaction manager contract passed\n";
