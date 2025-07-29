<?php

namespace SpsFW\Core\Router;

use Error;
use OpenApi\Attributes\Property;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\FormDataBody;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\PostBody;
use SpsFW\Core\Attributes\Validation\QueryParams;
use SpsFW\Core\Attributes\Validation\ValidateAttr;
use SpsFW\Core\Auth\Util\AccessChecker;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Exceptions\RouteNotFoundException;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Middleware\MiddlewareInterface;
use SpsFW\Core\Validation\Enum\ParamsIn;
use SpsFW\Core\Validation\Validator;

//use SpsFW\Api\Metrics\Metrics;

class Router
{

    private(set) DIContainer $container;
    /**
     * @var array<string, array{
     *     controller: class-string,
     *     method: string,
     *     pattern: string,
     *     params: array<string>,
     *     middlewares: array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>,
     *     access_rules: array<array<string>|array,
     * }>
     */
    protected array $routes = [];

    protected Request $request;

    protected array $currentRoute = [];

    /**
     * @var array<string<MiddlewareInterface>, array<string, mixed>>
     */
    protected array $globalMiddlewares = [];

    /**
     * DI для middlewares
     * @var array
     */
    protected array $dependencies = [];

    protected array $controllersDirs = [
        __DIR__ . '/../',
        __DIR__ . '/../../../../../../src',
    ];


//    protected ?RedisHelper $redis = null;

    /**
     * @param string|null $controllersDir Путь к директории с контроллерами
     * @param bool $useCache Использовать ли кеширование маршрутов
     * @param string $cacheDir Путь к директории с кешем
     * @param array<string, mixed> $dependencies Зависимости для внедрения в конструкторы классов и middleware
     * @throws BaseException
     * @throws ReflectionException
     */
    public function __construct(
        ?string $controllersDir = null,
        protected bool $useCache = true,
        protected string $cacheDir = __DIR__ . '/../../../../../../.cache/',
        array $dependencies = []
    ) {
//        $this->cacheDir =  PathManager::getCachePath();
//        $this->controllersDirs = PathManager::getControllersDirs();
        PathManager::ensureDirectoryExists($this->cacheDir);
//        $scannerDirs = [
//            __DIR__ . '/../../',              // фреймворк
//            // __DIR__ . '/../../../../',    // приложение
//        ];
//        $allClasses = [];
//        foreach ($scannerDirs as $dir) {
//            $allClasses = array_merge($allClasses, ClassScanner::getClassesFromDir($dir));
//        }
//
//        $compiler = new DICacheBuilder(new DIContainer());
//        $compiler->compile($allClasses);
//        return 'ok';

//        Metrics::init();
        if ($controllersDir !== null) {
            $this->controllersDirs[] = $controllersDir;
        }
        $this->request = Request::getInstance();
        $this->dependencies = $dependencies;
//        $this->dependencies[] = ['router', $this];

//        try {
//            $this->redis = RedisHelper::getInstance();
//        } catch (RedisException $e) {
//            $this->redis = null;
//        }
        $this->container = new DIContainer($this->cacheDir);

        $this->loadRoutes();
    }

    /**
     * Добавление глобального middleware
     *
     * @param class-string<MiddlewareInterface> $middlewareClass
     * @param array<string, mixed> $params
     * @return self
     */
    public function addGlobalMiddleware(string $middlewareClass, array $params = []): self
    {
        $this->globalMiddlewares[$middlewareClass] = $params;
        return $this;
    }

    public function loadRoutes($createCache = false, $redis = false): void
    {
        if ($this->useCache && !$createCache) {
            try {
                $compiledRoutesFile = $this->cacheDir . '/compiled_routes.php';
                if (file_exists($compiledRoutesFile)) {
                    $this->routes = require $compiledRoutesFile;
                    return;
                }
                throw new Error();
            } catch (\Error $e) {
                $this->scanControllers();
                $this->createRoutesCache();
                return;
            }
        }

        $this->scanControllers();

        if ($this->useCache || $createCache) {
            $this->createRoutesCache();
        }
    }

    protected function scanControllers(): void
    {
//        $controllerFiles = glob($this->controllersDir . '/**/*Controller.php', GLOB_BRACE);;

        $this->routes = [];

        foreach ($this->controllersDirs as $dir) {
            if (!is_dir($dir)) {
                error_log("Router: Директория контроллеров не найдена: $dir");
                continue; // Пропускаем несуществующие директории
            }
            $dir = new RecursiveDirectoryIterator($dir);
            $iterator = new RecursiveIteratorIterator($dir);
            $controllerFiles = [];

            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/Controller\.php$/', $file->getFilename())) {
                    $controllerFiles[] = $file->getRealPath();
                }
            }


            if ($controllerFiles === false) {
                throw new \RuntimeException("Директория контроллеров не найдена: {$this->controllersDirs}");
            }

            $controllerClassNames = [];
            foreach ($controllerFiles as $file) {
                require_once $file;
                $className = ClassScanner::getPathToNamespace($file);
                $controllerClassNames[] = $className;
            }

            foreach ($controllerClassNames as $className) {
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);
                $routes = $this->registerControllerRoutes($reflection);
                $this->routes = array_merge($this->routes, $routes);
            }
        }
    }

    /**
     * @param ReflectionClass $reflection
     * @throws ReflectionException
     */
    protected function registerControllerRoutes(
        ReflectionClass $reflection
    ): array {
        $routes = [];
        $classMiddlewares = $this->collectMiddlewares($reflection);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodRouteAttributes = $method->getAttributes(Route::class);
            if (empty($methodRouteAttributes)) {
                continue;
            }
            $methodRoute = $methodRouteAttributes[0]->newInstance();
            $fullPath = $methodRoute->getPath();
            $compiled = $this->compileRoutePattern($fullPath);
            $middlewares = array_merge(
                $classMiddlewares,
                $this->collectMiddlewares($method)
            );
            $accessRules = $this->collectAccessRules($method);
            $httpMethods = $methodRoute->getHttpMethods();
            if (empty($httpMethods)) {
                $httpMethods[] = "GET";
            }
            $methodParameters = $method->getParameters();
            $validationParams = [];
            foreach ($methodParameters as $methodParameter) {
                $dtoClass = $methodParameter->getType()->getName();
                $validationAttributes = $methodParameter->getAttributes(ValidateAttr::class, ReflectionAttribute::IS_INSTANCEOF);
                if (empty($validationAttributes)) continue;
                $validationAttribute = $validationAttributes[0];
                $validationAttributeInstance = $validationAttribute->newInstance();

                $paramsIn = null;
                if ($validationAttributeInstance instanceof JsonBody) {
                    $paramsIn = ParamsIn::Json;
                }
                if ($validationAttributeInstance instanceof QueryParams) {
                    $paramsIn = ParamsIn::Query;
                }
                if ($validationAttributeInstance instanceof PostBody) {
                    $paramsIn = ParamsIn::Post;
                }
                if ($validationAttributeInstance instanceof FormDataBody) {
                    $paramsIn = ParamsIn::Post;
                }

                $validationParams[] = [
                    'in' => $paramsIn,
                    'dto' => $dtoClass,
                    'rules' => $this->extractValidationRules($dtoClass), // Извлекаем правила
                ];
            }
            foreach ($httpMethods as $httpMethod) {
                $httpMethodString = is_string($httpMethod) ? $httpMethod : $httpMethod->value;
                $key = ($httpMethodString) . ':' . $fullPath;
                $routes[$key] = [
                    'controller' => $reflection->getName(),
                    'httpMethod' => $httpMethodString,
                    'method' => $method->getName(),
                    'rawPath' => $methodRoute->getPath(),
                    'pattern' => $compiled['pattern'],
                    'params' => $compiled['params'],
                    'middlewares' => $middlewares,
                    'access_rules' => $accessRules,
                    'dtos' => $validationParams,
                ];
            }
        }
        return $routes ?? [];
    }

    /**
     * @throws ReflectionException
     */
    private function extractValidationRules(string $dtoClass): array
    {
        $rules = [];
        $reflection = new ReflectionClass($dtoClass);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyAttributes = $property->getAttributes(Property::class);
            $realPropertyName = $property->getName();
            foreach ($propertyAttributes as $propertyAttribute) {
                $attributesOpenApi = $propertyAttribute->getArguments();
                $propertyName = $attributesOpenApi['property'] ?? $property->getName();

                $propertyRules = [];

                foreach ($attributesOpenApi as $attributeKey => $attributeValue) {
                    $propertyRules['real_name'] = $realPropertyName;
                    if ($attributeKey === 'ref') {
                        if (isset($attributesOpenApi['type']) && $attributesOpenApi['type'] == 'array') {
                            $propertyRules['ref'] = $attributeValue;
                            $propertyRules['type'] = 'array';
                            $propertyRules['nested_rules'] = $this->extractValidationRules($attributeValue);
                        } else {
                            $propertyRules['ref'] = $attributeValue;
                            $propertyRules['nested_rules'] = $this->extractValidationRules($attributeValue);
                        }
                        break;
                    }

                    // Сохраняем только валидационные атрибуты
                    if (isset(Validator::$attributesOpenApi[$attributeKey])) {
                        $propertyRules[$attributeKey] = $attributeValue;
                    }
                }

                if (!empty($propertyRules)) {
                    $rules[$propertyName] = $propertyRules;
                }
            }
        }

        return $rules;
    }

    /**
     * @param string $path
     * @return array{pattern: string, params: array<string>}
     */
    protected
    function compileRoutePattern(
        string $path
    ): array {
        $params = [];
        $pattern = preg_replace_callback('/{([a-zA-Z0-9_-]+)}/', function ($matches) use (&$params) {
            $params[$matches[1]] = null;
            return '([^/]+)';
        }, $path);

        if (empty($params)) {
            return [
                'pattern' => '',
                'params' => [],
            ];
        }
        return [
            'pattern' => '#^' . $pattern . '$#',
            'params' => $params,
        ];
    }

    /**
     * @param ReflectionClass|ReflectionMethod $reflection
     * @return array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>
     */
    protected
    function collectMiddlewares(
        $reflection
    ): array {
        $middlewares = [];
        $attributes = $reflection->getAttributes(Middleware::class);

        foreach ($attributes as $attribute) {
            /** @var Middleware $middlewareAttr */
            $middlewareAttr = $attribute->newInstance();
            $middlewares = array_merge($middlewares, $middlewareAttr->getMiddlewares());
        }

        return $middlewares;
    }

    /**
     * @param ReflectionMethod $method
     * @return array<array<string>>
     */
    protected
    function collectAccessRules(
        ReflectionMethod $method
    ): array {
        $attributesAny = $method->getAttributes(AccessRulesAny::class);
        $attributesAll = $method->getAttributes(AccessRulesAll::class);
        $noAuthAccess = $method->getAttributes(NoAuthAccess::class);
        if (!empty($noAuthAccess)) {
            return ['NO_AUTH_ACCESS'];
        }
        if (empty($attributesAny)) {
            return [];
        }

        $accessRules = [];

        foreach ($attributesAny as $attribute) {
            $accessRuleAttribute = $attribute->newInstance();
            $accessRules['any'] = [
                'rules' => $accessRuleAttribute->getRequiredRules(),
            ];
        }
        foreach ($attributesAll as $attribute) {
            $accessRuleAttribute = $attribute->newInstance();
            $accessRules['all'] = [
                'rules' => $accessRuleAttribute->getRequiredRules(),
            ];
        }
        return $accessRules;
    }

    public
    function dispatch(): Response
    {
        try {
            $this->currentRoute = $this->findRoute();
            return $this->processRoute($this->currentRoute);
        } catch (\Throwable $e) {
            if (!($e instanceof BaseException)) {
                error_log(
                    sprintf(
                        "ApplicationError exception: %s on %u in %s\nTrace: %s\n--- End of trace",
                        $e->getMessage(),
                        $e->getLine(),
                        $e->getFile(),
                        $e->getTraceAsString()
                    )
                );
            }

//            if (isset(Metrics::$registry)) {
//                Metrics::incrementErrors(
//                    $e,
//                    $_SERVER['REQUEST_METHOD'],
//                    $this->currentRoute['rawPath'] ?? Request::getUri(),
//                    http_response_code()
//                );
//            }
            return Response::error($e);
        } finally {
            // todo вернуть поддержку Prometheus
//            if (isset(Metrics::$registry)) {
//                Metrics::createAll($this->currentRoute['rawPath'] ?? Request::getUri());
//            }
        }
    }

    /**
     * @return array{
     *     controller: class-string,
     *     method: string,
     *     pattern: string,
     *     params: array<string>,
     *     middlewares: array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>,
     *     access_rules: array<array<string>>,
     *     match_params: array<string, string>
     * }
     * @throws RouteNotFoundException
     */
    protected
    function findRoute(): array
    {
        $method = $this->request->getMethod();
        $path = $this->request->getRequestUri();

        if ($route = $this->routes["{$method}:{$path}"] ?? []) {
            $route['match_params'] = [];
            return $route;
        }

        foreach ($this->routes as $key => $route) {
            [$routeMethod, $routePath] = explode(':', $key, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            if (empty($route['pattern']) || !preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            // Извлекаем параметры маршрута
            $matchParams = [];
            array_shift($matches); // Удаляем полное совпадение
            $counter = 0;
            foreach ($route['params'] as $paramName => $null) {
                $matchParams[$paramName] =  $matches[$counter] ?? '';
                $counter++;
            }

            $route['match_params'] = $matchParams;
            return $route;
        }

        throw new RouteNotFoundException($path);
    }

    /**
     * @param array{
     *     controller: class-string,
     *     httpMethod: HttpMethod|string,
     *     method: string,
     *     pattern: string,
     *     params: array<string>,
     *     middlewares: array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>,
     *     access_rules: array|array<array<string>>,
     *     match_params: array<string, string>
     * } $route
     * @return Response
     * @throws ReflectionException|AuthorizationException
     */
    protected
    function processRoute(
        array $route
    ): Response {
        AccessChecker::checkAccess($route['access_rules']);
        // Применяем глобальные middleware
        $middlewares = $this->prepareMiddlewares(
            array_merge(
                array_map(fn($class, $params) => [
                    'class' => $class,
                    'params' => $params
                ], array_keys($this->globalMiddlewares), array_values($this->globalMiddlewares)),
                $route['middlewares']
            )
        );

        // Устанавливаем параметры маршрута в запрос
        $this->request->setParams($route['match_params']);

        // Применяем middleware перед выполнением контроллера
        foreach ($middlewares as $middleware) {
            $this->request = $middleware->handle($this->request);
        }

        // Выполняем метод контроллера
        $controller = $this->createControllerInstance($route['controller']);
        $response = $this->executeControllerMethod($controller, $route['method'], $route['params'], $route['match_params'], $route['dtos']);

        // Применяем middleware после выполнения контроллера (в обратном порядке)
        foreach (array_reverse($middlewares) as $middleware) {
            $response = $middleware->after($response);
        }

        $response->setAllowedMethods($route['httpMethod']);

        return $response;
    }

    /**
     * @param array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}> $middlewareConfigs
     * @return array<MiddlewareInterface>
     */
    protected
    function prepareMiddlewares(
        array $middlewareConfigs
    ): array {
        $instances = [];

        foreach ($middlewareConfigs as $config) {
            $className = $config['class'];
            $params = $config['params'];

            // Создаем экземпляры middleware с параметрами
            $middlewareInstance = $this->createMiddlewareInstance($className, $params);
            $instances[] = $middlewareInstance;
        }

        return $instances;
    }

    /**
     * @template T of object|null
     * @param class-string<T> $className
     * @return T
     * @throws ReflectionException
     * @throws BaseException
     */
    protected
    function createControllerInstance(
        string $className
    ): object|null {
        // тестируем DI
//        $globalStartTime = hrtime(true);
        if (!file_exists($this->cacheDir . '/compiled_di.php')) {
            DICacheBuilder::compileDI($this->container);
        }
        $res = $this->container->get($className);
//        $endTime = hrtime(true);
//        $duration = ($endTime - $globalStartTime) / 1e6; // В миллисекундах
//        if (!headers_sent()) {
//            header(sprintf('X-DI-speed: %s', number_format($duration, 2)));
//        }
        return $res;

        //todo Вернуть поодержу Middlewares
        // Если нет мидлвар, вызывает констурктор напрямую
        if (empty($this->currentRoute['middlewares'])) {
            return new $className();
        }


        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $className();
        }

        return $this->createInstanceWithDependencies($reflection);
    }

    /**
     * @param class-string<MiddlewareInterface> $className
     * @param array<string, mixed> $params
     * @return MiddlewareInterface
     */
    protected
    function createMiddlewareInstance(
        string $className,
        array $params = []
    ): MiddlewareInterface {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $className();
        }

        // Объединяем параметры middleware с глобальными зависимостями
        $dependencies = array_merge($this->dependencies, $params);

        return $this->createInstanceWithDependencies($reflection, $dependencies);
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     * @param array<string, mixed> $extraDependencies
     * @return T
     */
    protected
    function createInstanceWithDependencies(
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

            // Есть ли параметр в переданных дополнительных зависимостях
            if (isset($extraDependencies[$paramName])) {
                $args[] = $extraDependencies[$paramName];
                continue;
            }

            // Проверяем тип параметра
            if ($paramType instanceof ReflectionNamedType) {
                $typeName = $paramType->getName();

                // Есть ли зависимость в контейнере по типу
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

                // Если параметр - класс, пытаемся создать экземпляр
                if (!$paramType->isBuiltin() && class_exists($typeName)) {
                    $args[] = $this->createInstanceWithDependencies(new ReflectionClass($typeName));
                    continue;
                }
            }

            // Если параметр опциональный, используем значение по умолчанию
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

    /**
     * @param object $controller Экземпляр контроллера
     * @param string $methodName Имя метода
     * @param array<string, string> $matchParams Параметры маршрута
     * @return Response
     * @throws ReflectionException
     */
    protected
    function executeControllerMethod(
        object $controller,
        string $methodName,
        array $exceptParams,
        array $matchParams,
        ?array $dtoParams = [],
    ): Response {
        $args = [];

        foreach ($matchParams as $routeParamName => $value) {
            if (!key_exists($routeParamName, $exceptParams)) {
                throw new \RuntimeException(
                    "Не удалось связать параметр '{$routeParamName}' для метода '{$methodName}'"
                );
            }
            $args[] = $value;
        }
        foreach ($dtoParams as $dtoParam) {
            $args[] = Validator::validate($dtoParam['in'], $dtoParam['dto'], $dtoParam['rules']);
        }

        $result = $controller->{$methodName}(...$args);
//        $reflectionMethod = new ReflectionMethod($controller, $methodName);
//        $parameters = $reflectionMethod->getParameters();
//        $args = [];
//

//
////        $validationAttributes = $reflectionMethod->getAttributes(Validate::class);
////
////        foreach ($validationAttributes as $validationAttribute) {
////            $validationInstance = $validationAttribute->newInstance();
////            $args[] = Validator::validate($validationInstance->where, $validationInstance->dtoClass);
////        }
//        if (!empty($dtos)) {
//            foreach ($dtos as $dtoConfig) {
//                $args[] = Validator::validate($dtoConfig['in'], $dtoConfig['dto']);
//            }
//        }
//
//        // Вызываем метод контроллера
//        $result = $reflectionMethod->invokeArgs($controller, $args);

        // Если метод вернул Response, используем его
        if ($result instanceof Response) {
            return $result;
        }

        // Если метод вернул строку, создаем Response с этой строкой
        if (is_string($result)) {
            $response = new Response();
            return $response->setBody($result);
        }

        // Если метод вернул массив или объект, создаем JSON Response
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        // По умолчанию возвращаем успешный пустой ответ
        return new Response();
    }


    /**
     * @return void
     */
    public
    function createRoutesCache(): void
    {
        $routesString = var_export($this->routes, true);
        $php = "<?php\n\nreturn $routesString;\n";

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        file_put_contents($this->cacheDir . '/compiled_routes.php', $php);
    }

    /**
     * @param int $status HTTP статус ошибки
     * @param string $message Сообщение об ошибке
     * @return Response
     */
    protected
    function createErrorResponse(
        int $status,
        string $message
    ): Response {
        return Response::json([
            'error' => true,
            'message' => $message,
            'status' => $status,
        ], $status);
    }


    private function getControllersDirs(): array
    {
        $libraryDir = __DIR__ . '/../';

        // Определяем корень проекта через composer.json
        $currentDir = __DIR__;
        while ($currentDir !== '/' && !file_exists($currentDir . '/composer.json')) {
            $currentDir = dirname($currentDir);
        }

        $srcDir = $currentDir . '/src';

        return [$libraryDir, $srcDir];
    }



}