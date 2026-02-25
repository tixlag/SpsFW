<?php

namespace SpsFW\Core\Queue\Interfaces;

/**
 * Optional fast-path contract for queue jobs.
 *
 * Legacy jobs can continue implementing JobInterface with
 * serialize()/deserialize() without migration.
 */
interface PayloadJobInterface
{
    public function toPayload(): array;

    public static function fromPayload(array $payload): static;
}
