<?php

namespace SpsFW\Core\Router;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RedisException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Sps\ApplicationError;
use Sps\Auth;
use Sps\HttpError401Exception;
use Sps\HttpError403Exception;
use Sps\RedisHelper;
use Sps\UserAccess\AccessRulesEnum;
use SpsFW\Api\Metrics\Metrics;
use SpsFW\Core\AccessRule\AccessRules;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Exceptions\RouteNotFoundException;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Middleware\Infrastructure\Middleware;
use SpsFW\Core\Middleware\Infrastructure\MiddlewareInterface;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\Route;
use SpsFW\Core\Validation\Attributes\Validation;
use SpsFW\Core\Validation\Validator;

class Router
{
    /**
     * @var array<string, array{
     *     controller: class-string,
     *     method: string,
     *     pattern: string,
     *     params: array<string>,
     *     middlewares: array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>,
     *     access_rules: array<int>
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
     * @var array<mixed>
     */
    protected array $dependencies = [];

    protected ?RedisHelper $redis = null;

    /**
     * @param string $controllersDir Путь к директории с контроллерами
     * @param bool $useCache Использовать ли кеширование маршрутов
     * @param string $cacheFile Путь к файлу кеша
     * @param array<string, mixed> $dependencies Зависимости для внедрения в конструкторы классов и middleware
     */
    public function __construct(
        protected string $controllersDir,
        protected bool $useCache = true,
        protected string $cacheFile = __DIR__ . '/routes.json',
        array $dependencies = []
    ) {
        $this->request = Request::getInstance();
        $this->dependencies = $dependencies;
//        $this->dependencies[] = ['router', $this];

        try {
            $this->redis = RedisHelper::getInstance();
        } catch (RedisException $e) {
            $this->redis = null;
        }

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

    public function loadRoutes($createCache = false): void
    {
        if ($this->useCache && !$createCache) {
            try {
                $this->routes = RoutesCache::$routes;
                return;
            } catch (\Error $e) {}
            $routesFromRedis = unserialize($this->redis?->getValue("SpsFW_routes") ?? '');
            if ($routesFromRedis) {
                $this->routes = $routesFromRedis;
                return;
            } else {
                $cacheDir = dirname($this->cacheFile);
                if (!file_exists($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }
                if (file_exists($this->cacheFile)) {
                    $this->routes = unserialize(file_get_contents($this->cacheFile), [
                        'allowed_classes' => true
                    ]);
                    return;
                }
            }
        }

        $this->scanControllers();

        if ($this->useCache || $createCache) {
            $routesString = var_export($this->routes, true);
            $classCode = <<<PHP
            <?php
                
            namespace src\Core\Router;
            
            class RoutesCache {
                public static \$routes =  $routesString;
            }
            PHP;
            file_put_contents(__DIR__ . '/RoutesCache.php', $classCode);

            $routes = serialize($this->routes);
            $this->redis?->setValue("SpsFW_routes", $routes);
            file_put_contents($this->cacheFile, $routes);
        }
    }

    protected
    function scanControllers(): void
    {
//        $controllerFiles = glob($this->controllersDir . '/**/*Controller.php', GLOB_BRACE);;

        $this->routes = [];

        $dir = new RecursiveDirectoryIterator($this->controllersDir);
        $iterator = new RecursiveIteratorIterator($dir);
        $controllerFiles = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/Controller\.php$/', $file->getFilename())) {
                $controllerFiles[] = $file->getRealPath();
            }
        }


        if ($controllerFiles === false) {
            throw new \RuntimeException("Директория контроллеров не найдена: {$this->controllersDir}");
        }

        $controllerClassNames = [];
        foreach ($controllerFiles as $file) {
            require_once $file;
            $className = $this->getPathToNamespace($file);
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

    private function getPathToNamespace($filePath): ?string
    {
        // Разбиваем путь на части с учетом ОС
        $parts = explode(DIRECTORY_SEPARATOR, $filePath);
        // Находим индекс элемента 'src'
        $SpsFWIndex = array_search('src', $parts);
        if ($SpsFWIndex === false) {
            return null; // src не найден в пути
        }
        // Берем части пути от src до конца
        $pathParts = array_slice($parts, $SpsFWIndex);
        // Обрабатываем последний элемент (файл) -> получаем имя класса
        $filename = array_pop($pathParts);
        $className = substr($filename, 0, -4); // Убираем .php
        // Добавляем имя класса обратно
        $pathParts[] = $className;
        // Собираем namespace
        return implode('\\', $pathParts);
    }

    /**
     * @param ReflectionClass $reflection
     */
    protected
    function registerControllerRoutes(
        ReflectionClass $reflection
    ): array {
        $routes = [];
        $controllerAttribute = $reflection->getAttributes(Controller::class);
        if (empty($controllerAttribute)) {
            return [];
        }

//        $classRoute = $controllerAttribute[0]->newInstance();
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
                ];
            }
        }
        return $routes ?? [];
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
            $params[] = $matches[1];
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
     * @return array<AccessRulesEnum>
     */
    protected
    function collectAccessRules(
        ReflectionMethod $method
    ): array {
        $attributes = $method->getAttributes(AccessRules::class);
        if (empty($attributes)) {
            return [];
        }

        $accessRuleAttribute = $attributes[0]->newInstance();
        return $accessRuleAttribute->getRequiredRules();
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

            if (isset(Metrics::$registry)) {
                Metrics::incrementErrors(
                    $e,
                    $_SERVER['REQUEST_METHOD'],
                    $this->currentRoute['rawPath'] ?? Request::getUri(),
                    http_response_code()
                );
            }
            return Response::error($e);
        } finally {
            Metrics::createAll($this->currentRoute['rawPath'] ?? Request::getUri());
        }
    }

    /**
     * @return array{
     *     controller: class-string,
     *     method: string,
     *     pattern: string,
     *     params: array<string>,
     *     middlewares: array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>,
     *     access_rules: array<AccessRulesEnum>,
     *     match_params: array<string, string>
     * }
     * @throws RouteNotFoundException
     */
    protected
    function findRoute(): array
    {
        $method = $this->request->getMethod();
        $path = $this->request->getRequestUri();

        if ($route = $this->routes["{$method}:{$path}"]) {
            $route['match_params'] = [];
            return $route;
        }

        foreach ($this->routes as $key => $route) {
            [$routeMethod, $routePath] = explode(':', $key, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            // Извлекаем параметры маршрута
            $matchParams = [];
            array_shift($matches); // Удаляем полное совпадение
            foreach ($route['params'] as $index => $paramName) {
                $matchParams[$paramName] = $matches[$index] ?? '';
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
     *     access_rules: array<AccessRulesEnum>,
     *     match_params: array<string, string>
     * } $route
     * @return Response
     * @throws HttpError403Exception|HttpError401Exception|ApplicationError|ReflectionException
     */
    protected
    function processRoute(
        array $route
    ): Response {
        $this->checkAccess($route['access_rules']);
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
        $response = $this->executeControllerMethod($controller, $route['method'], $route['match_params']);

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
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    protected
    function createControllerInstance(
        string $className
    ): object {
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
     * @param array<string, string> $routeParams Параметры маршрута
     * @return Response
     * @throws ReflectionException
     */
    protected
    function executeControllerMethod(
        object $controller,
        string $methodName,
        array $routeParams
    ): Response {
        $reflectionMethod = new ReflectionMethod($controller, $methodName);
        $parameters = $reflectionMethod->getParameters();
        $args = [];

        foreach ($routeParams as $routeParamName => $value) {
            $parameter = array_find($parameters, fn($param) => $param->getName() === $routeParamName);
            if (!$parameter) {
                throw new \RuntimeException(
                    "Не удалось связать параметр '{$routeParamName}' для метода '{$methodName}'"
                );
            }
            $paramType = $parameter->getType();

            // Приводим к типу, если указан
            if ($paramType instanceof ReflectionNamedType) {
                $typeName = $paramType->getName();

                $value = match ($typeName) {
                    'int' => (int)$value,
                    'float' => (float)$value,
                    'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    default => $value
                };
            }

            $args[] = $value;
        }

        $validationAttributes = $reflectionMethod->getAttributes(Validation::class);

        foreach ($validationAttributes as $validationAttribute) {
            $validationInstance = $validationAttribute->newInstance();
            $args[] = Validator::validate($validationInstance->where, $validationInstance->dtoClass);
        }

        // Вызываем метод контроллера
        $result = $reflectionMethod->invokeArgs($controller, $args);

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

    /**
     * @throws HttpError403Exception|ApplicationError|HttpError401Exception
     */
    private
    function checkAccess(
        mixed $access_rules
    ): void {
        if (is_null($user = Auth::get())) {
            throw new HttpError401Exception("Требуется авторизация", 401);
        }
        foreach ($access_rules as $rule) {
            if (!$user->getAccessRulesHelper()->hasRule($rule)) {
                throw new HttpError403Exception("Доступ запрещен. Требуется право: {$rule->name}", 403);
            }
        }
    }
}