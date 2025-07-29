<?php

namespace SpsFW\Core\Router\CleanCodeRouter;

use ReflectionException;
use SpsFW\Core\Auth\Util\AccessChecker;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Middleware\MiddlewareInterface;
use SpsFW\Core\Validation\Validator;

class RouteProcessor
{
    /**
     * @param array<string, mixed> $globalMiddlewares
     */
    public function __construct(
        private DependencyResolver $dependencyResolver,
        private array $globalMiddlewares = []
    ) {
    }

    /**
     * @param array{
     *     controller: class-string,
     *     httpMethod: string,
     *     method: string,
     *     pattern: string,
     *     params: array<string>,
     *     middlewares: array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>,
     *     access_rules: array|array<array<string>>,
     *     match_params: array<string, string>
     * } $route
     * @param Request $request
     * @return Response
     * @throws ReflectionException|AuthorizationException
     */
    public function processRoute(array $route, Request $request): Response
    {
        // Проверяем доступ
        AccessChecker::checkAccess($route['access_rules']);

        // Подготавливаем middleware
        $middlewares = $this->prepareMiddlewares($route['middlewares']);

        // Устанавливаем параметры маршрута в запрос
        $request->setParams($route['match_params']);

        // Применяем middleware перед выполнением контроллера
        foreach ($middlewares as $middleware) {
            $request = $middleware->handle($request);
        }

        // Выполняем метод контроллера
        $controller = $this->dependencyResolver->createControllerInstance($route['controller']);
        $response = $this->executeControllerMethod(
            $controller,
            $route['method'],
            $route['params'],
            $route['match_params'],
            $route['dtos'] ?? []
        );

        // Применяем middleware после выполнения контроллера (в обратном порядке)
        foreach (array_reverse($middlewares) as $middleware) {
            $response = $middleware->after($response);
        }

        $response->setAllowedMethods($route['httpMethod']);

        return $response;
    }

    /**
     * @param array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}> $routeMiddlewares
     * @return array<MiddlewareInterface>
     */
    private function prepareMiddlewares(array $routeMiddlewares): array
    {
        $instances = [];

        // Сначала глобальные middleware
        foreach ($this->globalMiddlewares as $className => $params) {
            $instances[] = $this->dependencyResolver->createMiddlewareInstance($className, $params);
        }

        // Затем middleware маршрута
        foreach ($routeMiddlewares as $config) {
            $instances[] = $this->dependencyResolver->createMiddlewareInstance(
                $config['class'],
                $config['params']
            );
        }

        return $instances;
    }

    /**
     * @param object $controller
     * @param string $methodName
     * @param array<string, string> $exceptParams
     * @param array<string, string> $matchParams
     * @param array $dtoParams
     * @return Response
     * @throws ReflectionException
     */
    private function executeControllerMethod(
        object $controller,
        string $methodName,
        array $exceptParams,
        array $matchParams,
        array $dtoParams = []
    ): Response {
        $args = [];

        // Добавляем параметры маршрута
        foreach ($matchParams as $routeParamName => $value) {
            if (!key_exists($routeParamName, $exceptParams)) {
                throw new \RuntimeException(
                    "Не удалось связать параметр '{$routeParamName}' для метода '{$methodName}'"
                );
            }
            $args[] = $value;
        }

        // Добавляем валидированные DTO
        foreach ($dtoParams as $dtoParam) {
            $args[] = Validator::validate($dtoParam['in'], $dtoParam['dto'], $dtoParam['rules']);
        }

        // Выполняем метод
        $result = $controller->{$methodName}(...$args);

        return $this->createResponseFromResult($result);
    }

    /**
     * @param mixed $result
     * @return Response
     */
    private function createResponseFromResult(mixed $result): Response
    {
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
}