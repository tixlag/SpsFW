<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Thin wrapper over PHP's reflection attribute API for the compile layer.
 *
 * Centralizes attribute lookup so the metadata builders have a single, mockable read path. It returns
 * instantiated attribute instances and offers typed single/first-or-null accessors plus presence checks.
 * It only reads; it never mutates. Step 1 covers arbitrary class / method / property / parameter
 * attributes (notably #[Route], #[Middleware]); the compile-specific attributes (#[Operation], #[Response],
 * #[Items], #[Field]) are introduced in Step 3 and read through the same path.
 */
final class AttributeReader
{
    /**
     * Instantiate every attribute named $name declared on $reflector.
     *
     * @template T of object
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector
     * @param class-string<T> $name
     * @return list<T>
     */
    public function getInstances(
        ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector,
        string $name,
    ): array {
        $instances = [];
        foreach ($reflector->getAttributes($name) as $attribute) {
            $instances[] = $attribute->newInstance();
        }
        return $instances;
    }

    /**
     * The first instantiated attribute named $name, or null when none is declared.
     *
     * @template T of object
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector
     * @param class-string<T> $name
     * @return ?T
     */
    public function firstInstance(
        ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector,
        string $name,
    ): ?object {
        foreach ($this->getInstances($reflector, $name) as $instance) {
            return $instance;
        }
        return null;
    }

    /**
     * Whether an attribute named $name is declared on $reflector (without instantiating it).
     *
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector
     * @param class-string $name
     */
    public function has(
        ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $reflector,
        string $name,
    ): bool {
        return $reflector->getAttributes($name) !== [];
    }
}
