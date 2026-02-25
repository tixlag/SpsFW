<?php

namespace SpsFW\Core\Queue;

use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\PayloadJobInterface;
use SpsFW\Core\Queue\Traits\AutoPayloadJobTrait;

/**
 * Base class for payload-first queue jobs.
 *
 * New jobs should extend this class and provide getName().
 */
abstract class PayloadQueueJob implements JobInterface, PayloadJobInterface
{
    use AutoPayloadJobTrait;

    abstract public function getName(): string;

    public function serialize(): string
    {
        return json_encode($this->toPayload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function deserialize(string $payload): static
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \DomainException(sprintf('Invalid payload for %s', static::class));
        }

        return static::fromPayload($decoded);
    }
}
