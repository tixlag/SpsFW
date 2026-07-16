<?php

namespace SpsFW\Core\Router;

use ReflectionClass;
use ReflectionException;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Config;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Queue\Attributes\QueueJob;
use SpsFW\Core\Queue\Interfaces\JobInterface;


class DICacheBuilder
{
    private ?DIContainer $container;
    private array $compiled = [];
    private array $jobRegistryMap = [];
    private string $cachePath;

//    public static string $DIDir = __DIR__ . '/../../../../../../var/cache/DI';

    /**
     * @param DIContainer|null $container the production singleton is REQUIRED for the legacy {@see compile()}
     *      path (it calls setCompiledMap()); the engine's {@see compileOnly()} path passes null and never touches
     *      the production container.
     */
    public function __construct(?DIContainer $container = null, string $cachePath = '')
    {
        $this->container = $container;
        $this->cachePath = $cachePath;

    }

    /**
     * Compile-only engine API (Step 5 / plan §11.5): analyze the DI map + job registry and RETURN them — WITHOUT
     * writing to the production cache and WITHOUT calling {@see DIContainer::setCompiledMap()} on any container.
     * The Coordinator stages+publishes the returned artifacts itself; the production singleton stays untouched.
     *
     * Unlike the legacy {@see compile()}, this path is ROBUST: a single class whose constructor cannot be analyzed
     * is reported as a diagnostic (ERROR) on $diagnostics (if given) and skipped, instead of aborting the whole
     * build. With $diagnostics = null the legacy throw-on-first-error behavior is preserved.
     *
     * @param list<class-string> $classList
     * @return array{compiled: array<string, mixed>, jobs: array<string, mixed>}
     */
    public function compileOnly(array $classList, ?CompileDiagnostics $diagnostics = null): array
    {
        return $this->analyzeAll($classList, $diagnostics);
    }

    /**
     * Legacy compilation path (BC): analyze, then WRITE compiled_di.php + job_registry.php to $this->cachePath and
     * push the compiled map into the production container via setCompiledMap(). This remains the production flow
     * until the Step 6a/6b producer switch; the engine path uses {@see compileOnly()} instead.
     *
     * @throws BaseException when a class cannot be analyzed AND no $diagnostics collector absorbs it (BC behavior)
     * @throws ReflectionException
     */
    public function compile(array $classList): void
    {
        // The legacy path writes the production cache and mutates the production singleton — it needs the container.
        if ($this->container === null) {
            throw new BaseException(
                'DICacheBuilder::compile() requires a DIContainer (it writes the production cache and calls '
                . 'setCompiledMap()); the engine path uses compileOnly() which needs none.'
            );
        }

        // null diagnostics ⇒ analyzeAll rethrows the first analysis error (the legacy contract).
        $result = $this->analyzeAll($classList, null);
        $this->compiled = $result['compiled'];
        $this->jobRegistryMap = $result['jobs'];

        $this->writeToFile();
        $this->container->setCompiledMap($this->compiled);
    }

    /**
     * Shared analysis core for both {@see compile()} (legacy, throwing) and {@see compileOnly()} (engine, robust).
     * With a non-null $diagnostics, an unanalyzable class is recorded as an ERROR and skipped (one bad class does
     * not abort the build); with null, the first such error propagates as an exception (legacy BC).
     *
     * @param list<class-string> $classList
     * @return array{compiled: array<string, mixed>, jobs: array<string, mixed>}
     */
    private function analyzeAll(array $classList, ?CompileDiagnostics $diagnostics): array
    {
        // Анализируем DI-зависимости
        foreach ($classList as $class) {
            if (str_ends_with($class, 'Test.php')) {
                continue;
            }
            $binding = Config::getDIBinding($class);
            if (is_array($binding) && isset($binding['class'])) {
                continue;
            }
            try {
                $this->compiled[$class] = $this->analyze($class);
            } catch (\Throwable $e) {
                if ($diagnostics === null) {
                    throw $e;
                }
                $diagnostics->error(
                    controller: null,
                    method: null,
                    dto: $class,
                    field: 'di',
                    cause: sprintf('DI analysis failed for %s: %s', $class, $e->getMessage()),
                    fix: 'make the constructor injectable (#[Inject] / type-hinted deps) or bind the class in Config::setDIBindings()',
                );
            }
        }

        return ['compiled' => $this->compiled, 'jobs' => $this->jobRegistryMap];
    }


    /**
     * @throws ReflectionException
     * @throws BaseException
     */
    private function analyze(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $this->analyzeJobQueue($reflection, $class); // todo требуется рефакторинг. В anylyze должен приходить понятный класс - в какую мапу потом передать

//        if ( $reflection->isInterface() || $reflection->isAbstract()) return;
        $constructor = $reflection->getConstructor();

        $args = [];
        $constructorParams = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $injectAttr = $param->getAttributes(Inject::class);
                if (empty($injectAttr) and Config::getDIBinding($class) == null) {
                    // Пропускаем параметры без #[Inject] или не описанные в DIBindings
                    continue;
                }

                $type = $param->getType();
                if (!$type || $type->isBuiltin()) {
                    throw new BaseException("Cannot inject builtin or missing type for parameter \${$param->getName()} in $class");
                }

                $resolvedClass = $type->getName();
//                $resolvedClass = $this->container->resolveAbstractForBuild($type->getName());
                $args[] = $resolvedClass;

                // Сохраняем информацию о параметре для генерации фабрики
                $constructorParams[] = [
                    'name' => $param->getName(),
                    'class' => $resolvedClass,
                    'position' => $param->getPosition()
                ];
            }
        }

        if ($argsFromConfig = Config::getDIBinding($class)) {
            if (is_array($argsFromConfig)) {
                $argsFromConfig = $argsFromConfig['args'] ?? $argsFromConfig;
                $args = array_merge($args, $argsFromConfig);
            }

        }

        return [
            'class' => $class,
            'args' => $args,
            'constructor_params' => $constructorParams,
            'has_constructor' => $constructor !== null
        ];
    }

    private function writeToFile(): void
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }
        $this->writeCompiledMap();
        $this->writeJobRegistryMap(); // ← Добавляем запись маппинга задач

    }

    private function writeCompiledMap(): void
    {
        $export = var_export($this->compiled, true);
        $php = "<?php\n\nreturn $export;\n";
        file_put_contents($this->cachePath . '/compiled_di.php', $php);
    }


    private function writeJobRegistryMap(): void
    {
        $export = var_export($this->jobRegistryMap, true);
        $php = "<?php\n\nreturn $export;\n";
        file_put_contents($this->cachePath . '/job_registry.php', $php);
    }

    /**
     * @param ReflectionClass $reflection
     * @param string $class
     * @return void
     */
    public function analyzeJobQueue(ReflectionClass $reflection, string $class): void
    {
        try {
            $attribute = $reflection->getAttributes(QueueJob::class)[0] ?? null;

//            if ($attribute && is_subclass_of($class, JobInterface::class)) {
//                /** @var QueueJob $queueJob */
//                $queueJob = $attribute->newInstance();
//                $this->jobRegistryMap[$queueJob->name] = $class;
//            }



            $attrs = $reflection->getAttributes();
            foreach ($attrs as $attr) {
                $attrName = $attr->getName();
                if ($attrName === \SpsFW\Core\Queue\Attributes\QueueJob::class) {
                    $args = $attr->getArguments();
                    $jobName = $args[0] ?? null;
                    $handlerClass = $args[1] ?? $args['handlerClass'] ?? null;
                    if ($jobName) {
                        if (!isset($this->jobRegistryMap[$jobName])) {
                            $this->jobRegistryMap[$jobName] = [];
                            $this->jobRegistryMap[$jobName]['jobClass'] = $class;
                        } else {
                            $this->jobRegistryMap[$jobName]['jobClass'] = $class;
                        }
                        if ($handlerClass) {
                            $this->jobRegistryMap[$jobName]['handlerClass'] = $handlerClass;
                        }
                    }
                }
                if ($attrName === \SpsFW\Core\Queue\Attributes\JobHandler::class) {
                    $args = $attr->getArguments();
                    $jobName = $args[0] ?? null;
                    if ($jobName) {
                        if (!isset($this->jobRegistryMap[$jobName])) {
                            $this->jobRegistryMap[$jobName] = [];
                            $this->jobRegistryMap[$jobName]['handlerClass'] = $class;
                        } else {
                            $this->jobRegistryMap[$jobName]['handlerClass'] = $class;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }


    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }


    /**
     * @return void
     * @throws BaseException
     * @throws ReflectionException
     */
    public static function compileDI(?DIContainer $container = null, ?string $cachePath = null): void
    {
        $cachePath ??= PathManager::getCachePath();
        $allClasses = [];

        $scannerDirs = PathManager::getControllersDirs();
        foreach ($scannerDirs as $dir) {
            $allClasses = array_merge($allClasses, ClassScanner::getClassesFromDir($dir));
        }

        $compiler = new DICacheBuilder($container ?? DIContainer::getInstance($cachePath), $cachePath);
        $compiler->compile($allClasses);
    }
}
