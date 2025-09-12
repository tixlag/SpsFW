<?php

namespace SpsFW\Core\Router;

use ReflectionException;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\BaseException;

class DIContainer
{
    private array $singletons = [];
    private array $compiledMap = [];

    public function setCompiledMap(array $compiledMap): void
    {
        $this->compiledMap = $compiledMap;
    }
    private array $resolved = []; // Кэш разрешённых зависимостей

    /**
     * @throws ReflectionException
     * @throws BaseException
     */
    public function __construct(string $cachePath)
    {
        $cacheDIPath =  $cachePath . '/compiled_di.php';
        if (file_exists($cacheDIPath)) {
            $this->compiledMap = require $cacheDIPath;
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

    /**
     * Разрешает абстракцию в реальный класс или инстанс.
     * Поддерживает:
     * - Простые привязки: Interface::class => Concrete::class
     * - Инстансы: Interface::class => new Concrete()
     * - Конфигурация с аргументами: Interface::class => ['class' => ..., 'args' => [...]]
     *
     * @throws BaseException
     */
    public function resolveAbstract(string $abstract): object|string
    {
        $binding = Config::getDIBinding($abstract);

        if ($binding === null) {
            return $abstract; // Нет привязки — используем сам класс
        }

        // Случай 1: Это уже готовый объект
        if (is_object($binding)) {
            return $binding;
        }

        // Случай 2: Это строка — обычный класс
        if (is_string($binding)) {
            return $binding;
        }

        // Случай 3: Это массив — конфигурация с аргументами
        if (is_array($binding)) {
            if (!isset($binding['class'])) {
                throw new BaseException("Binding for '$abstract' is an array but missing 'class' key.");
            }

            $class = $binding['class'];
            $args = $binding['args'] ?? [];

            // Если есть аргументы — создаём объект прямо здесь!
            $resolvedArgs = [];
            foreach ($args as $arg) {
                if (is_string($arg) && class_exists($arg)) {
                    // Это класс — разрешаем его через DI
                    $resolvedArgs[] = $this->get($arg);
                } elseif (is_object($arg)) {
                    // Это уже инстанс — просто передаём
                    $resolvedArgs[] = $arg;
                } else {
                    // Это скаляр — например, строка URL или int timeout
                    $resolvedArgs[] = $arg;
                }
            }

            // Создаём объект с аргументами
            return new $class(...$resolvedArgs);
        }

        throw new BaseException("Invalid binding type for '$abstract'");
    }


}