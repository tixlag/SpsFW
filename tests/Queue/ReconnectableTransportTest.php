<?php

declare(strict_types=1);

use SpsFW\Core\Queue\PreparedMessageTransportInterface;
use SpsFW\Core\Queue\PreparedQueueMessage;
use SpsFW\Core\Queue\ReconnectablePreparedMessageTransport;

require_once dirname(__DIR__) . '/bootstrap.php';

final class ReconnectableTestTransport implements PreparedMessageTransportInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $fails)
    {
    }

    public function publishPrepared(PreparedQueueMessage $message, bool $reliable = false): void
    {
        $this->calls++;
        if ($this->fails) {
            throw new RuntimeException('connection closed');
        }
    }
}

$created = [];
$transport = new ReconnectablePreparedMessageTransport(
    static function () use (&$created): PreparedMessageTransportInterface {
        $next = new ReconnectableTestTransport($created === []);
        $created[] = $next;
        return $next;
    },
);
$message = new PreparedQueueMessage(
    payload: ['ok' => true],
    properties: [],
    routingKey: 'event.ready',
    exchange: 'crm.events',
    messageId: 'reconnect-1',
    availableAt: new DateTimeImmutable(),
);

try {
    $transport->publishPrepared($message, true);
    throw new RuntimeException('first disconnected publish must fail');
} catch (RuntimeException $exception) {
    assert_same('connection closed', $exception->getMessage(), 'transport returns the broker failure');
}

$transport->publishPrepared($message, true);

assert_same(2, count($created), 'failed connection is replaced before the next attempt');
assert_same(1, $created[0]->calls, 'failed transport is not reused');
assert_same(1, $created[1]->calls, 'replacement transport publishes the retry');

echo "Reconnectable transport contract passed\n";
