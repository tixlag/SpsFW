<?php

namespace SpsFW\Core\DI;

use ReflectionClass;
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

    private static ?self $instance = null;

    private function __construct(string $cachePath)
    {
        $cacheDIPath = $cachePath . '/compiled_di.php';
        if (file_exists($cacheDIPath)) {
            $this->compiledMap = require $cacheDIPath;
        }
    }

    public static function getInstance(string $cachePath = ''): self
    {
        if (self::$instance === null) {
            if ($cachePath === '') {
                // Путь по умолчанию — можно вынести в константу или Config
                $cachePath = __DIR__ . '/../../../../.cache/';
                if (!is_dir($cachePath)) {
                    mkdir($cachePath, 0777, true);
                }
            }
            self::$instance = new self($cachePath);
        }
        return self::$instance;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws BaseException
     */
    public function get(string $class): ?object
    {
        // Уже создан — вернуть singleton
        if (isset($this->singletons[$class])) {
            return $this->singletons[$class];
        }

        // Разрешаем абстракцию сразу на входе
        $resolvedClass = $this->resolveAbstract($class);

        // Если вернулся готовый инстанс — кэшируем и возвращаем
        if (is_object($resolvedClass)) {
            $this->singletons[$class] = $resolvedClass;
            return $resolvedClass;
        }

        // Если не строка — ошибка
        if (!is_string($resolvedClass)) {
            throw new BaseException("Invalid resolved class type for '$class'");
        }

        // Теперь мы точно знаем: нужно создать объект класса $resolvedClass
        // Но сохраняем исходный $class как ключ в singletons — для совместимости
        if (!isset($this->compiledMap[$resolvedClass])) {
            return null;
        }

        return $this->createWithDependencies($resolvedClass, $class);
    }

    /**
     * Создаёт объект с зависимостями.
     * $targetClass — класс, который нужно создать (например, GuzzleHttpClientAdapter)
     * $originalKey — ключ, по которому запросили (например, HttpClientInterface::class)
     */
    private function createWithDependencies(string $targetClass, string $originalKey): ?object
    {
        if (isset($this->singletons[$originalKey])) {
            return $this->singletons[$originalKey];
        }

        if (!isset($this->compiledMap[$targetClass])) {
            return null;
        }

        $info = $this->compiledMap[$targetClass];

        $tempObjects = [];
        $this->createAllDependencies([$targetClass], $tempObjects);

        $this->initializeObjects($tempObjects);

        // Сохраняем singleton по ОРИГИНАЛЬНОМУ ключу (интерфейсу или реальному классу)
        $this->singletons[$originalKey] = $this->singletons[$targetClass] ?? null;

        return $this->singletons[$originalKey];
    }

    /**
     * Рекурсивно создает все зависимости, но не меняет ключи
     */
    private function createAllDependencies(array $classes, array &$tempObjects): void
    {
        foreach ($classes as $class) {
            if (isset($tempObjects[$class]) || isset($this->singletons[$class])) {
                continue;
            }
            try {
                $resolvedClass = $this->resolveAbstract($class);
            } catch (\Exception $e) {
                //todo логгировать, если при создании одного из объектов все сломалось
                // не получилось создать объект, вставили заглушку, чтобы работать дальше
                $resolvedClass = $this->createEmptyObject(Config::getDIBinding($class)['class']);
            }

            if (is_object($resolvedClass)) {
                $this->singletons[$class] = $resolvedClass;
                continue;
            }

            if (!is_string($resolvedClass)) {
                throw new BaseException("Invalid resolved class type for '$class'");
            }

            // 👇 НОВАЯ ЗАЩИТА: НЕ СОЗДАЁМ ИНТЕРФЕЙСЫ И АБСТРАКТНЫЕ КЛАССЫ
            if (interface_exists($resolvedClass) || $this->abstract_class_exists($resolvedClass)) {
                // Пропускаем — это должна быть реализация, а не интерфейс
                // Должна быть привязка в Config, иначе — ошибка
                throw new BaseException(
                    "Cannot instantiate interface or abstract class: $resolvedClass. " .
                    "Did you forget to bind it in Config::setDIBindings()?"
                );
            }

            if (!class_exists($resolvedClass)) {
                throw new BaseException("Class does not exist: $resolvedClass");
            }

            if (!isset($this->compiledMap[$resolvedClass])) {
                // Не компилировался — возможно, это внешний класс без #[Inject]
                // Пропускаем — он не будет использоваться как зависимость в DI-цепочке
                continue;
            }

            $info = $this->compiledMap[$resolvedClass];

            // 👇 СОЗДАЁМ ПУСТОЙ ОБЪЕКТ — ЭТО НАША ЗАГЛУШКА ДЛЯ ЦИКЛОВ
            $tempObjects[$resolvedClass] = $this->createEmptyObject($info['class']);
            $tempObjects[$class] = $tempObjects[$resolvedClass];

            // 👇 РЕКУРСИВНО СОЗДАЁМ ЗАВИСИМОСТИ — ДАЖЕ ЕСЛИ ОНИ ПОЗЖЕ УПОМИНАЮТСЯ В ЦИКЛЕ
            if (!empty($info['args'])) {
                $this->createAllDependencies($info['args'], $tempObjects);
            }
        }
    }

    private function createEmptyObject(string $className): object
    {
        return unserialize(sprintf('O:%d:"%s":0:{}', strlen($className), $className));
    }

    private function initializeObjects(array $tempObjects): void
    {
        foreach ($tempObjects as $class => $object) {
            if (isset($this->singletons[$class]) or !isset($this->compiledMap[$class])) {
                continue;
            }
            $info = $this->compiledMap[$class];

            $args = [];
            foreach ($info['args'] as $depClass) {
                // Сначала смотрим в singletons — там могут быть инстансы от resolveAbstract()
                if (isset($this->singletons[$depClass])) {
                    $args[] = $this->singletons[$depClass];
                } elseif (isset($tempObjects[$depClass])) {
                    $args[] = $tempObjects[$depClass]; // ← Это может быть пустой объект!
                } else {
                    throw new \RuntimeException("Cannot resolve dependency: $depClass");
                }
            }

            if (!empty($args)) {
                $object->__construct(...$args); // ← ВЫЗЫВАЕМ КОНСТРУКТОР — ДАЖЕ ЕСЛИ ОБЪЕКТ БЫЛ "ПУСТЫМ"
            }

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

            $resolvedArgs = [];
            foreach ($args as $arg) {
                if (is_string($arg) && (class_exists($arg) || interface_exists($arg))) {
                    // Это класс/интерфейс — разрешаем рекурсивно через DI
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

    public function resolveAbstractForBuild(string $abstract): object|string
    {
        $binding = Config::getDIBinding($abstract);

        if ($binding === null) {
            return $abstract; // Нет привязки — используем сам класс
        }

        // Случай 1: Это уже готовый объект
        if (is_object($binding)) {
            return $binding::class;
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
//            $args = $binding['args'] ?? [];
//
//            $resolvedArgs = [];
//            foreach ($args as $arg) {
//                if (is_string($arg) && (class_exists($arg) || interface_exists($arg))) {
//                    // Это класс/интерфейс — разрешаем рекурсивно через DI
//                    $resolvedArgs[] = $this->resolveAbstractForBuild($arg);
//                } elseif (is_object($arg)) {
//                    // Это уже инстанс — просто передаём
//                    $resolvedArgs[] = $arg::class;
//                } else {
//                    // Это скаляр — например, строка URL или int timeout
//                    $resolvedArgs[] = $arg;
//                }
//            }

            // Создаём объект с аргументами
            return $class;
        }

        throw new BaseException("Invalid binding type for '$abstract'");
    }

    private function abstract_class_exists(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $reflection = new ReflectionClass($class);
        return $reflection->isAbstract();
    }
}