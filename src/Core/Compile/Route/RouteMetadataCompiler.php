<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Route;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\PhpIni;
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\FormDataBody;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\PostBody;
use SpsFW\Core\Attributes\Validation\QueryParams;
use SpsFW\Core\Attributes\Validation\ValidateAttr;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Metadata\RouteRuntimeMetadata;
use SpsFW\Core\Compile\Metadata\ValidationRuleGraph;
use SpsFW\Core\Middleware\RateLimitMiddleware;
use SpsFW\Core\Router\ClassScanner;
use SpsFW\Core\Validation\Enum\ParamsIn;

/**
 * Compile-time producer of {@see RouteRuntimeMetadata}: replicates Router's discovery + IR-building
 * (Router.php scanControllers / registerControllerRoutes / collectMiddlewares / combineMiddlewares /
 * collectAccessRules / compileRoutePattern) so the emitted cache is byte-identical to Router's, while
 * routing DTO rule-graph extraction through {@see DtoSchemaBuilder} (the unified producer, Step 2) instead
 * of Router::extractValidationRules.
 *
 * Where Router is SILENT, this compiler surfaces {@see CompileDiagnostics}: a duplicate METHOD:path key
 * (Router overwrites silently), an untyped DTO parameter, or an unresolvable DTO class. The IR still
 * mirrors Router (last-wins) so the parity contract holds; the diagnostics let managed mode fail fast.
 *
 * Class-level access rules are NOT collected (only method-level) — this is today's quirk (plan §3) and is
 * preserved verbatim, unlike middlewares which merge class + method.
 *
 * Step 3 (M3): additive; production cache still comes from Router until the M5 producer switch.
 */
final class RouteMetadataCompiler
{
    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
        private readonly DtoSchemaBuilder $schemas = new DtoSchemaBuilder(),
    ) {
    }

    /**
     * Scan the discovery dirs (mirrors Router::scanControllers: RecursiveDirectoryIterator over each dir,
     * *Controller.php filename filter, require_once, ClassScanner::getPathToNamespace) and compile every
     * loadable controller's routes into a flat list.
     *
     * @param list<string> $discoveryPaths
     * @return list<RouteRuntimeMetadata>
     */
    public function compile(array $discoveryPaths): array
    {
        $classes = [];
        foreach ($discoveryPaths as $dir) {
            if (!is_dir($dir)) {
                continue; // Router error_logs + skips; non-fatal.
            }
            foreach ($this->discoverControllerClasses($dir) as $class) {
                if ($class !== null) {
                    $classes[] = $class;
                }
            }
        }
        return $this->compileClasses($classes);
    }

    /**
     * Compile an explicit list of controller FQCNs and assert route-key uniqueness. Split from
     * {@see compile()} so the duplicate-key diagnostic is unit-testable without a discovery dir.
     *
     * @param list<class-string> $classes
     * @return list<RouteRuntimeMetadata>
     */
    public function compileClasses(array $classes): array
    {
        $routes = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            foreach ($this->compileController(new ReflectionClass($class)) as $route) {
                $routes[] = $route;
            }
        }
        $this->assertUniqueRouteKeys($routes);
        return $routes;
    }

    /**
     * Compile one controller's public #[Route]-bearing methods into runtime metadata (mirrors
     * Router::registerControllerRoutes). Returns one entry per (method × http-method).
     *
     * @return list<RouteRuntimeMetadata>
     */
    public function compileController(ReflectionClass $reflection): array
    {
        $routes = [];
        $classMiddlewares = $this->collectMiddlewares($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttributes = $method->getAttributes(Route::class);
            if ($routeAttributes === []) {
                continue;
            }

            /** @var Route $methodRoute */
            $methodRoute = $routeAttributes[0]->newInstance();
            $fullPath = $methodRoute->getPath();
            [$pattern, $params] = $this->compileRoutePattern($fullPath);
            $middlewares = $this->combineMiddlewares($classMiddlewares, $this->collectMiddlewares($method));
            $accessRules = $this->collectAccessRules($method);

            $httpMethods = $methodRoute->getHttpMethods();
            if (empty($httpMethods)) {
                $httpMethods = ['GET'];
            }

            $dtos = $this->collectDtoBindings($reflection, $method);
            $phpIniSettings = $this->collectPhpIni($method);

            foreach ($httpMethods as $httpMethod) {
                $httpMethodString = is_string($httpMethod) ? $httpMethod : $httpMethod->value;
                $routes[] = new RouteRuntimeMetadata(
                    controller: $reflection->getName(),
                    httpMethod: $httpMethodString,
                    method: $method->getName(),
                    rawPath: $methodRoute->getPath(),
                    pattern: $pattern,
                    params: $params,
                    middlewares: $middlewares,
                    accessRules: $accessRules,
                    dtos: $dtos,
                    phpIniSettings: $phpIniSettings,
                );
            }
        }

        return $routes;
    }

    /**
     * Report any METHOD:path key shared by more than one route (Router silently overwrites; managed mode
     * halts). The IR still collapses to last-wins — this only surfaces the collision.
     *
     * @param list<RouteRuntimeMetadata> $routes
     */
    private function assertUniqueRouteKeys(array $routes): void
    {
        $byKey = [];
        foreach ($routes as $route) {
            $byKey[$route->routeKey()][] = $route;
        }
        foreach ($byKey as $key => $group) {
            if (count($group) < 2) {
                continue;
            }
            foreach ($group as $route) {
                $this->diagnostics->error(
                    controller: $route->controller,
                    method: $route->method,
                    dto: null,
                    field: 'route',
                    cause: sprintf('duplicate route key %s is registered by %d operations', $key, count($group)),
                    fix: 'disambiguate the path or HTTP method so each operation has a unique METHOD:path',
                );
            }
        }
    }

    /**
     * @return list<?class-string>
     */
    private function discoverControllerClasses(string $dir): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/Controller\.php$/', $file->getFilename())) {
                continue;
            }
            $realPath = $file->getRealPath();
            require_once $realPath;
            $classes[] = ClassScanner::getPathToNamespace($realPath);
        }
        return $classes;
    }

    /**
     * Build the DTO bindings for a method's validated parameters (mirrors Router.php:246–279):
     * each param carrying a ValidateAttr-subclass (JsonBody/QueryParams/PostBody/FormDataBody) becomes a
     * binding with its ParamsIn, the DTO FQCN, and the rule graph from DtoSchemaBuilder.
     *
     * @return list<array{in: ?ParamsIn, dto: string, rules: ValidationRuleGraph}>
     */
    private function collectDtoBindings(ReflectionClass $reflection, ReflectionMethod $method): array
    {
        $bindings = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            try {
                /** @var class-string $dtoClass */
                $dtoClass = $type->getName();
            } catch (\Throwable) {
                $this->diagnostics->error(
                    controller: $reflection->getName(),
                    method: $method->getName(),
                    dto: null,
                    field: $parameter->getName(),
                    cause: 'validated parameter has no type',
                    fix: 'add a DTO class type-hint to the parameter',
                );
                $dtoClass = 'string';
            }

            $validationAttributes = $parameter->getAttributes(ValidateAttr::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($validationAttributes === []) {
                continue;
            }

            $instance = $validationAttributes[0]->newInstance();
            $paramsIn = match (true) {
                $instance instanceof JsonBody => ParamsIn::Json,
                $instance instanceof QueryParams => ParamsIn::Query,
                $instance instanceof PostBody, $instance instanceof FormDataBody => ParamsIn::Post,
                default => null,
            };

            $bindings[] = [
                'in' => $paramsIn,
                'dto' => $dtoClass,
                'rules' => $this->buildRules($reflection->getName(), $method->getName(), $parameter->getName(), $dtoClass),
            ];
        }
        return $bindings;
    }

    private function buildRules(string $controller, string $method, string $param, string $dtoClass): ValidationRuleGraph
    {
        if (!class_exists($dtoClass)) {
            $this->diagnostics->error(
                controller: $controller,
                method: $method,
                dto: $dtoClass,
                field: $param,
                cause: 'DTO class does not exist / is not autoloadable',
                fix: 'make the type a loadable DTO class',
            );
            return ValidationRuleGraph::empty();
        }
        return $this->schemas->ruleGraph($this->schemas->build($dtoClass));
    }

    private function collectPhpIni(ReflectionMethod $method): ?array
    {
        $attributes = $method->getAttributes(PhpIni::class);
        if ($attributes === []) {
            return null;
        }
        return $attributes[0]->newInstance()->settings;
    }

    /**
     * Mirror Router::collectAccessRules (method-level only, with the AccessRulesAll-only ⇒ [] quirk).
     *
     * @return array<string|int, mixed>
     */
    private function collectAccessRules(ReflectionMethod $method): array
    {
        if ($method->getAttributes(NoAuthAccess::class) !== []) {
            return ['NO_AUTH_ACCESS'];
        }
        $attributesAny = $method->getAttributes(AccessRulesAny::class);
        if ($attributesAny === []) {
            return [];
        }

        $accessRules = [];
        foreach ($attributesAny as $attribute) {
            $accessRules['any'] = ['rules' => $attribute->newInstance()->getRequiredRules()];
        }
        foreach ($method->getAttributes(AccessRulesAll::class) as $attribute) {
            $accessRules['all'] = ['rules' => $attribute->newInstance()->getRequiredRules()];
        }
        return $accessRules;
    }

    /**
     * Mirror Router::collectMiddlewares: #[Middleware] entries then #[RateLimit] sugar → RateLimitMiddleware.
     *
     * @param ReflectionClass<object>|ReflectionMethod $reflection
     * @return list<array{class: class-string, params: array<string, mixed>}>
     */
    private function collectMiddlewares(ReflectionClass|ReflectionMethod $reflection): array
    {
        $middlewares = [];
        foreach ($reflection->getAttributes(Middleware::class) as $attribute) {
            $middlewares = array_merge($middlewares, $attribute->newInstance()->getMiddlewares());
        }

        foreach ($reflection->getAttributes(RateLimit::class) as $attribute) {
            $rl = $attribute->newInstance();
            $params = [
                'requests' => $rl->requests,
                'whitelistRequests' => $rl->whitelistRequests,
                'windowSeconds' => $rl->window,
                'keyPrefix' => $rl->prefix,
                'whitelistIps' => $rl->whitelistIps,
                'blockDuration' => $rl->blockDuration,
            ];
            $params = array_filter($params, static fn (mixed $v): bool => $v !== null && $v !== []);
            $middlewares[] = ['class' => RateLimitMiddleware::class, 'params' => $params];
        }

        return $middlewares;
    }

    /**
     * Mirror Router::combineMiddlewares: class + method others merged, RateLimit extracted from both,
     * merged, and appended last.
     *
     * @param list<array{class: class-string, params: array<string, mixed>}> $classMiddlewares
     * @param list<array{class: class-string, params: array<string, mixed>}> $methodMiddlewares
     * @return list<array{class: class-string, params: array<string, mixed>}>
     */
    private function combineMiddlewares(array $classMiddlewares, array $methodMiddlewares): array
    {
        [$classRateLimit, $classOthers] = $this->extractRateLimitMiddleware($classMiddlewares);
        [$methodRateLimit, $methodOthers] = $this->extractRateLimitMiddleware($methodMiddlewares);

        $middlewares = array_merge($classOthers, $methodOthers);
        $mergedRateLimit = null;

        if ($classRateLimit !== null) {
            $mergedRateLimit = $classRateLimit;
        }
        if ($methodRateLimit !== null) {
            $mergedRateLimit = [
                'class' => RateLimitMiddleware::class,
                'params' => $this->mergeRateLimitParams($mergedRateLimit['params'] ?? [], $methodRateLimit['params']),
            ];
        }
        if ($mergedRateLimit !== null) {
            $middlewares[] = $mergedRateLimit;
        }

        return $middlewares;
    }

    /**
     * @param list<array{class: class-string, params: array<string, mixed>}> $middlewares
     * @return array{0: ?array{class: class-string, params: array<string, mixed>}, 1: list<array{class: class-string, params: array<string, mixed>}>}
     */
    private function extractRateLimitMiddleware(array $middlewares): array
    {
        $rateLimit = null;
        $others = [];
        foreach ($middlewares as $middleware) {
            if ($middleware['class'] === RateLimitMiddleware::class) {
                $rateLimit = $middleware;
                continue;
            }
            $others[] = $middleware;
        }
        return [$rateLimit, $others];
    }

    /**
     * @param array<string, mixed> $baseParams
     * @param array<string, mixed> $overrideParams
     * @return array<string, mixed>
     */
    private function mergeRateLimitParams(array $baseParams, array $overrideParams): array
    {
        $merged = array_merge($baseParams, array_filter(
            $overrideParams,
            static fn (mixed $value): bool => $value !== null,
        ));

        $merged['requests'] = array_merge(
            is_array($baseParams['requests'] ?? null) ? $baseParams['requests'] : [],
            is_array($overrideParams['requests'] ?? null) ? $overrideParams['requests'] : [],
        );
        $merged['whitelistRequests'] = array_merge(
            is_array($baseParams['whitelistRequests'] ?? null) ? $baseParams['whitelistRequests'] : [],
            is_array($overrideParams['whitelistRequests'] ?? null) ? $overrideParams['whitelistRequests'] : [],
        );

        $mergedWhitelistIps = array_merge(
            is_array($baseParams['whitelistIps'] ?? null) ? $baseParams['whitelistIps'] : [],
            is_array($overrideParams['whitelistIps'] ?? null) ? $overrideParams['whitelistIps'] : [],
        );
        if (!empty($mergedWhitelistIps)) {
            $merged['whitelistIps'] = array_values(array_unique($mergedWhitelistIps));
        }

        $merged['blockDuration'] = array_merge(
            is_array($baseParams['blockDuration'] ?? null) ? $baseParams['blockDuration'] : [],
            is_array($overrideParams['blockDuration'] ?? null) ? $overrideParams['blockDuration'] : [],
        );
        if (empty($merged['blockDuration'])) {
            unset($merged['blockDuration']);
        }

        return $merged;
    }

    /**
     * Mirror Router::compileRoutePattern: `{name}` → `([^/]+)`; params captured as an ordered assoc
     * [name => null]; no params ⇒ pattern ''.
     *
     * @return array{0: string, 1: array<string, null>}
     */
    private function compileRoutePattern(string $path): array
    {
        $params = [];
        $pattern = preg_replace_callback('/{([a-zA-Z0-9_-]+)}/', static function (array $matches) use (&$params): string {
            $params[$matches[1]] = null;
            return '([^/]+)';
        }, $path);

        if (empty($params)) {
            return ['', []];
        }
        return ['#^' . $pattern . '$#', $params];
    }
}
