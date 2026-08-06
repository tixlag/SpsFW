<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue;

/**
 * Explicit direct RabbitMQ publisher.
 *
 * This name makes the delivery semantics visible at call sites. It delegates
 * to the existing confirmed-capable RabbitMQ publisher and never persists to
 * the transactional outbox.
 */
final class DirectQueuePublisher extends RabbitMQQueuePublisher
{
}

