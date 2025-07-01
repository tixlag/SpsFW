<?php

namespace SpsFW\Core\Router;

use ReflectionClass;
use ReflectionException;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Exceptions\BaseException;

class DICacheBuilder
{
    private DIContainer $container;
    private array $compiled = [];

    public static string $DIDir = __DIR__ . '/../../../../../../var/cache/DI';

    public function __construct(DIContainer $container)
    {
        $this->container = $container;
    }

    /**
     * @throws BaseException
     * @throws ReflectionException
     */
    public function compile(array $classList): void
    {
        foreach ($classList as $class) {
            $this->compiled[$class] = $this->analyze($class);
        }

        $this->writeToFile();
    }

    /**
     * @throws ReflectionException
     * @throws BaseException
     */
    private function analyze(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        $args = [];
        $constructorParams = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $injectAttr = $param->getAttributes(Inject::class);
                if (empty($injectAttr)) {
                    // Пропускаем параметры без #[Inject]
                    continue;
                }

                $type = $param->getType();
                if (!$type || $type->isBuiltin()) {
                    throw new BaseException("Cannot inject builtin or missing type for parameter \${$param->getName()} in $class");
                }

                $resolvedClass = $this->container->resolveAbstract($type->getName());
                $args[] = $resolvedClass;

                // Сохраняем информацию о параметре для генерации фабрики
                $constructorParams[] = [
                    'name' => $param->getName(),
                    'class' => $resolvedClass,
                    'position' => $param->getPosition()
                ];
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
        $this->writeCompiledMap();
        $this->writeFactoryFile();
    }

    private function writeCompiledMap(): void
    {
        $export = var_export($this->compiled, true);
        $php = "<?php\n\nreturn $export;\n";
        file_put_contents(self::$DIDir . '/compiled_di.php', $php);
    }

    private function writeFactoryFile(): void
    {
        $factories = [];

        foreach ($this->compiled as $className => $info) {
            $shortName = $this->getShortClassName($className);
            $factoryName = "create{$shortName}";

            if ($info['has_constructor'] && !empty($info['constructor_params'])) {
                // Генерируем фабричный метод с параметрами
                $paramsList = [];
                foreach ($info['constructor_params'] as $param) {
                    $paramsList[] = "\$param{$param['position']}";
                }
                $paramsString = implode(', ', $paramsList);

                $factories[] = "    public static function {$factoryName}({$paramsString}): {$className} {\n        return new {$className}({$paramsString});\n    }";
            } else {
                // Простой конструктор без параметров
                $factories[] = "    public static function {$factoryName}(): {$className} {\n        return new {$className}();\n    }";
            }
        }

        $factoriesCode = implode("\n\n", $factories);

        $php = "<?php\n\nclass DIFactory {\n{$factoriesCode}\n}\n";
        file_put_contents(self::$DIDir . '/DIFactory.php', $php);
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
    public static function compileDI(?DIContainer $container = null): void
    {
        $allClasses = [];

        $scannerDirs = [
            __DIR__ . '/../',              // фреймворк
            // __DIR__ . '/../../../../',    // приложение
        ];
        foreach ($scannerDirs as $dir) {
            $allClasses = array_merge($allClasses, ClassScanner::getClassesFromDir($dir));
        }

        $compiler = new DICacheBuilder($container ?? new DIContainer());
        $compiler->compile($allClasses);
    }
}