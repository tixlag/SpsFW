<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Route;

use SpsFW\Core\Compile\Metadata\RouteRuntimeMetadata;
use SpsFW\Core\Compile\Metadata\ValidationRuleGraph;

/**
 * Serializes {@see RouteRuntimeMetadata}[] into the exact route-cache IR array — the value Router writes
 * via `var_export($this->routes, true)` (Router.php:1114). Byte-compatible with today's cache:
 *  - outer key = `METHOD:path` (the Router dedup key);
 *  - inner field order/names match Router.php:291–302 exactly (controller, httpMethod, method, rawPath,
 *    pattern, params, middlewares, access_rules, dtos, php_ini_settings);
 *  - dtos[].rules is the raw rule array (a {@see ValidationRuleGraph} is unwrapped); dtos[].in stays the
 *    ParamsIn enum (var_export renders it as `…\ParamsIn::Json`, as today);
 *  - duplicate METHOD:path keys collapse to last-wins, exactly like Router's `$routes[$key] = …`.
 *
 * Step 3 (M3): emitter only; the producer is {@see RouteMetadataCompiler}. Production cache still comes
 * from Router until the M5 producer switch.
 */
final class RouteCacheEmitter
{
    /**
     * @param list<RouteRuntimeMetadata> $routes
     * @return array<string, array<string, mixed>> METHOD:path => IR entry (last-wins on duplicate keys)
     */
    public function emit(array $routes): array
    {
        $ir = [];
        foreach ($routes as $route) {
            $ir[$route->routeKey()] = $this->entry($route);
        }
        return $ir;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dtos(RouteRuntimeMetadata $route): array
    {
        $out = [];
        foreach ($route->dtos as $binding) {
            $rules = $binding['rules'] ?? [];
            $out[] = [
                'in' => $binding['in'],
                'dto' => $binding['dto'],
                'rules' => $rules instanceof ValidationRuleGraph ? $rules->rules : $rules,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(RouteRuntimeMetadata $route): array
    {
        return [
            'controller' => $route->controller,
            'httpMethod' => $route->httpMethod,
            'method' => $route->method,
            'rawPath' => $route->rawPath,
            'pattern' => $route->pattern,
            'params' => $route->params,
            'middlewares' => $route->middlewares,
            'access_rules' => $route->accessRules,
            'dtos' => $this->dtos($route),
            'php_ini_settings' => $route->phpIniSettings,
        ];
    }

    /**
     * Render the PHP cache-file source identical to Router::createRoutesCache() (`<?php\n\nreturn …;\n`).
     *
     * @param list<RouteRuntimeMetadata> $routes
     */
    public function emitSource(array $routes): string
    {
        return "<?php\n\nreturn " . var_export($this->emit($routes), true) . ";\n";
    }
}
