<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue;

final class ReconnectablePreparedMessageTransport implements PreparedMessageTransportInterface
{
    private readonly \Closure $factory;
    private ?PreparedMessageTransportInterface $transport = null;

    /**
     * @param callable(): PreparedMessageTransportInterface $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = \Closure::fromCallable($factory);
    }

    public function publishPrepared(PreparedQueueMessage $message, bool $reliable = false): void
    {
        try {
            $this->transport()->publishPrepared($message, $reliable);
        } catch (\Throwable $exception) {
            $this->transport = null;
            throw $exception;
        }
    }

    private function transport(): PreparedMessageTransportInterface
    {
        if ($this->transport === null) {
            $transport = ($this->factory)();
            if (!$transport instanceof PreparedMessageTransportInterface) {
                throw new \LogicException('Reconnectable transport factory returned an invalid transport.');
            }
            $this->transport = $transport;
        }

        return $this->transport;
    }
}
