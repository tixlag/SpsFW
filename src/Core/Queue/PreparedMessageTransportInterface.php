<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue;

interface PreparedMessageTransportInterface
{
    public function publishPrepared(PreparedQueueMessage $message, bool $reliable = false): void;
}
