<?php

namespace SpsFW\Core\Router;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\BaseException;

class DIContainer
{
    private array $singletons = [];
    private array $compiledMap = [];
    private array $resolved = []; // Кэш разрешённых зависимостей

    /**
     * @throws ReflectionException
     * @throws BaseException
     */
    public function __construct()
    {
        $compiledPath = DICacheBuilder::$DIDir . '/compiled_di.php';
        if (file_exists($compiledPath)) {
            $this->compiledMap = require $compiledPath;
        } else {
            DICacheBuilder::compileDI();
        }
    }

    /**
     * @template T of object|null
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object|null
    {
        // Уже создан — вернуть singleton
        if (isset($this->singletons[$class])) {
            return $this->singletons[$class];
        }

        if (!isset($this->compiledMap[$class])) {
            return null;
        }

        // Создаём все объекты в правильном порядке
        return $this->createWithDependencies($class);
    }

    private function createWithDependencies(string $class): object|null
    {
        if (isset($this->singletons[$class])) {
            return $this->singletons[$class];
        }

        if (!isset($this->compiledMap[$class])) {
            return null;
        }

        $info = $this->compiledMap[$class];

        // Создаём временные объекты для всех зависимостей
        $tempObjects = [];
        $this->createAllDependencies([$class], $tempObjects);

        // Теперь у нас есть все объекты, инициализируем их в правильном порядке
        $this->initializeObjects($tempObjects);

        return $this->singletons[$class] ?? null;
    }

    private function createAllDependencies(array $classes, array &$tempObjects): void
    {
        foreach ($classes as $class) {
            if (isset($tempObjects[$class]) || isset($this->singletons[$class])) {
                continue;
            }

            if (!isset($this->compiledMap[$class])) {
                continue;
            }

            $info = $this->compiledMap[$class];

            // Создаём пустой объект (используем serialize trick - быстрее рефлексии)
            $tempObjects[$class] = $this->createEmptyObject($info['class']);

            // Рекурсивно создаём зависимости
            if (!empty($info['args'])) {
                $this->createAllDependencies($info['args'], $tempObjects);
            }
        }
    }

    private function createEmptyObject(string $className): object
    {
        // Самый быстрый способ создать объект без конструктора
        return unserialize(sprintf('O:%d:"%s":0:{}', strlen($className), $className));
    }

    private function initializeObjects(array $tempObjects): void
    {
        foreach ($tempObjects as $class => $object) {
            if (isset($this->singletons[$class])) {
                continue;
            }

            $info = $this->compiledMap[$class];

            // Собираем аргументы для конструктора
            $args = [];
            foreach ($info['args'] as $depClass) {
                if (isset($this->singletons[$depClass])) {
                    $args[] = $this->singletons[$depClass];
                } elseif (isset($tempObjects[$depClass])) {
                    $args[] = $tempObjects[$depClass];
                } else {
                    throw new \RuntimeException("Cannot resolve dependency: $depClass");
                }
            }

            // Вызываем конструктор напрямую
            if (!empty($args)) {
                $object->__construct(...$args);
            }

            // Сохраняем как singleton
            $this->singletons[$class] = $object;
        }
    }

    public function resolveAbstract(string $abstract): string
    {
        return Config::$bindings[$abstract] ?? $abstract;
    }


}