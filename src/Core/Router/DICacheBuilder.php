<?php

namespace SpsFW\Core\Router;

use ReflectionClass;
use ReflectionException;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\BaseException;

class DICacheBuilder
{
    private DIContainer $container;
    private array $compiled = [];
    private string $cachePath;

//    public static string $DIDir = __DIR__ . '/../../../../../../var/cache/DI';

    public function __construct(DIContainer $container, string $cachePath)
    {
        $this->container = $container;
        $this->cachePath = $cachePath;

    }

    /**
     * @throws BaseException
     * @throws ReflectionException
     */
    public function compile(array $classList): void
    {
        foreach ($classList as $class) {
            $binding = Config::getDIBinding($class);
            if (is_array($binding) && isset($binding['class'])) {
                continue;
            }
            $this->compiled[$class] = $this->analyze($class);
        }

        $this->writeToFile();
        $this->container->setCompiledMap($this->compiled);
    }

    /**
     * @throws ReflectionException
     * @throws BaseException
     */
    private function analyze(string $class): array
    {
        $reflection = new ReflectionClass($class);
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

        if ($argsFromConfig =  Config::getDIBinding($class)) {
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
            mkdir($this->cachePath , 0777, true);
        }
        $this->writeCompiledMap();
    }

    private function writeCompiledMap(): void
    {
        $export = var_export($this->compiled, true);
        $php = "<?php\n\nreturn $export;\n";
        file_put_contents($this->cachePath  . '/compiled_di.php', $php);
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
    public static function compileDI(?DIContainer $container = null, string $cachePath = __DIR__  . "/../../../../../../.cache"): void
    {
        $allClasses = [];

        $scannerDirs = PathManager::getControllersDirs();
        foreach ($scannerDirs as $dir) {
            $allClasses = array_merge($allClasses, ClassScanner::getClassesFromDir($dir));
        }

        $compiler = new DICacheBuilder($container ?? new DIContainer($cachePath), $cachePath);
        $compiler->compile($allClasses);
    }
}