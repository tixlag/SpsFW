<?php

namespace SpsFW\Core\Router;

use ReflectionClass;
use ReflectionNamedType;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Middleware\MiddlewareInterface;

class DependencyResolver
{
    /**
     * @param array<string, mixed> $dependencies
     * @param DIContainer $container
     */
    public function __construct(
        private array $dependencies = [],
        private DIContainer $container = new DIContainer()
    ) {
    }

    /**
     * @param string $dependency
     * @param mixed $value
     * @return void
     */
    public function addDependency(string $dependency, mixed $value): void
    {
        $this->dependencies[$dependency] = $value;
    }

    /**
     * @template T of object|null
     * @param class-string<T> $className
     * @return T
     * @throws BaseException
     */
    public function createControllerInstance(string $className): object|null
    {
        $globalStartTime = hrtime(true);

        if (!file_exists(DICacheBuilder::$DIDir . '/compiled_di.php')) {
            DICacheBuilder::compileDI($this->container);
        }

        $res = $this->container->get($className);
        $endTime = hrtime(true);
        $duration = ($endTime - $globalStartTime) / 1e6;

        if (!headers_sent()) {
            header(sprintf('X-DI-speed: %s', number_format($duration, 2)));
        }

        return $res;
    }

    /**
     * @param class-string<MiddlewareInterface> $className
     * @param array<string, mixed> $params
     * @return MiddlewareInterface
     */
    public function createMiddlewareInstance(string $className, array $params = []): MiddlewareInterface
    {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $className();
        }

        $dependencies = array_merge($this->dependencies, $params);
        return $this->createInstanceWithDependencies($reflection, $dependencies);
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     * @param array<string, mixed> $extraDependencies
     * @return T
     */
    public function createInstanceWithDependencies(
        ReflectionClass $reflection,
        array $extraDependencies = []
    ): object {
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return $reflection->newInstance();
        }

        $parameters = $constructor->getParameters();
        $args = [];

        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();
            $paramType = $parameter->getType();

            // Проверяем дополнительные зависимости
            if (isset($extraDependencies[$paramName])) {
                $args[] = $extraDependencies[$paramName];
                continue;
            }

            // Проверяем тип параметра
            if ($paramType instanceof ReflectionNamedType) {
                $typeName = $paramType->getName();

                // Ищем зависимость по типу
                foreach (array_merge($this->dependencies, $extraDependencies) as $key => $dependency) {
                    if (is_object($dependency) && is_a($dependency, $typeName)) {
                        $args[] = $dependency;
                        continue 2;
                    }
                    if (is_array($dependency) && is_a($dependency[1], $typeName)) {
                        $args[] = $dependency[1];
                        continue 2;
                    }
                }

                // Если параметр - класс, создаем экземпляр рекурсивно
                if (!$paramType->isBuiltin() && class_exists($typeName)) {
                    $args[] = $this->createInstanceWithDependencies(new ReflectionClass($typeName));
                    continue;
                }
            }

            // Используем значение по умолчанию
            if ($parameter->isOptional()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Не удалось разрешить зависимость '{$paramName}' для класса '{$reflection->getName()}'"
            );
        }

        return $reflection->newInstanceArgs($args);
    }
}