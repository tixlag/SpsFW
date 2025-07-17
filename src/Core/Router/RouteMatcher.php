<?php

namespace SpsFW\Core\Router;

use SpsFW\Core\Exceptions\RouteNotFoundException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Middleware\MiddlewareInterface;

class RouteMatcher
{
    /**
     * @param array<string, array> $routes
     */
    public function __construct(
        private array $routes
    ) {
    }

    /**
     * @param Request $request
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
    public function findRoute(Request $request): array
    {
        $method = $request->getMethod();
        $path = $request->getRequestUri();

        // Сначала проверяем точное совпадение
        if ($route = $this->routes["{$method}:{$path}"] ?? []) {
            $route['match_params'] = [];
            return $route;
        }

        // Затем проверяем совпадение с параметрами
        foreach ($this->routes as $key => $route) {
            [$routeMethod, $routePath] = explode(':', $key, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            if (empty($route['pattern']) || !preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            $route['match_params'] = $this->extractMatchParams($route, $matches);
            return $route;
        }

        throw new RouteNotFoundException($path);
    }

    /**
     * @param array $route
     * @param array<string> $matches
     * @return array<string, string>
     */
    private function extractMatchParams(array $route, array $matches): array
    {
        $matchParams = [];
        array_shift($matches); // Удаляем полное совпадение

        $counter = 0;
        foreach ($route['params'] as $paramName => $null) {
            $matchParams[$paramName] = $matches[$counter] ?? '';
            $counter++;
        }

        return $matchParams;
    }
}