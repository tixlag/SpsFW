<?php

namespace SpsFW\Core\Queue\Traits;

/**
 * Reflection-based payload hydration for simple DTO-like jobs.
 */
trait AutoPayloadJobTrait
{
    public function toPayload(): array
    {
        return get_object_vars($this);
    }

    public static function fromPayload(array $payload): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Missing required payload key "%s" for job %s',
                $name,
                static::class
            ));
        }

        return $reflection->newInstanceArgs($args);
    }
}
