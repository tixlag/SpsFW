<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Route;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\OpenApi\Operation;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\PhpIni;
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\FormDataBody;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\PostBody;
use SpsFW\Core\Attributes\Validation\QueryParams;
use SpsFW\Core\Attributes\Validation\ValidateAttr;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\AttributeReader;
use SpsFW\Core\Compile\Introspection\DtoEligibility;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Introspection\OperationIdResolver;
use SpsFW\Core\Compile\Introspection\RequiredSource;
use SpsFW\Core\Compile\Introspection\TypeMapper;
use SpsFW\Core\Compile\Metadata\OperationMetadata;
use SpsFW\Core\Compile\Metadata\ParameterMetadata;
use SpsFW\Core\Compile\Metadata\RequestBodyMetadata;
use SpsFW\Core\Compile\Metadata\ResponseMetadata;
use SpsFW\Core\Compile\Metadata\RouteRuntimeMetadata;
use SpsFW\Core\Compile\Metadata\SchemaMetadata;
use SpsFW\Core\Compile\Metadata\SecurityMetadata;
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
    /** Built lazily so it shares the compiler's diagnostics (cycle/eligibility errors halt here). */
    private readonly DtoSchemaBuilder $schemas;

    private readonly OperationIdResolver $operationIds;

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
        ?DtoSchemaBuilder $schemas = null,
        private readonly TypeMapper $typeMapper = new TypeMapper(),
        private readonly AttributeReader $attributeReader = new AttributeReader(),
        private readonly DtoEligibility $eligibility = new DtoEligibility(),
        private readonly RequiredSource $requiredSource = RequiredSource::Oa,
        array $operationIdMap = [],
    ) {
        // Share diagnostics so a cyclic/missing DTO surfaces on the SAME collector that halts the build,
        // instead of the throwaway CompileDiagnostics a default-constructed builder would carry.
        $this->schemas = $schemas ?? new DtoSchemaBuilder($this->diagnostics);
        // The tri-state operationId lockfile (plan §19): the materialized inventory — 39 preserved ids + 306
        // nulls — so legacy ops keep their id (or stay id-less) and only NEW route-only ops get the convention.
        // An empty map (default) assigns the convention to everything — correct for unit tests / a fresh app,
        // wrong for the real `next` inventory, which must pass the resolved map.
        $this->operationIds = new OperationIdResolver($this->diagnostics, $operationIdMap);
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
     * The ValidateAttr check runs FIRST, so an ordinary untyped/union parameter that is NOT a validated input
     * never triggers a false "no type" diagnostic — only a validated parameter that lacks a DTO class type-hint
     * does.
     *
     * @return list<array{in: ?ParamsIn, dto: string, rules: ValidationRuleGraph}>
     */
    private function collectDtoBindings(ReflectionClass $reflection, ReflectionMethod $method): array
    {
        $bindings = [];
        foreach ($method->getParameters() as $parameter) {
            $validationAttributes = $parameter->getAttributes(ValidateAttr::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($validationAttributes === []) {
                continue;
            }

            $dtoClass = $this->reflectionTypeName($parameter->getType());
            if ($dtoClass === null) {
                $this->diagnostics->error(
                    controller: $reflection->getName(),
                    method: $method->getName(),
                    dto: null,
                    field: $parameter->getName(),
                    cause: 'validated parameter has no DTO class type-hint',
                    fix: 'add a single DTO class type-hint to the parameter',
                );
                $dtoClass = 'string';
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
        // ruleGraphFor (not build()) — the route-cache path wants ONLY the rule graph; a DTO's schema
        // projection (and any future schema-only diagnostics) must not leak into route-cache compilation.
        return $this->schemas->ruleGraphFor($dtoClass);
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

    // =========================================================================
    // OpenAPI projection (OperationMetadata) — the doc sibling of the route-IR walk.
    // Same reflection pass, different output; runtime routing/validation are untouched.
    // =========================================================================

    /**
     * Compile the OpenAPI documentation projection (OperationMetadata[]) for every #[Route]-bearing public
     * method of a controller. operationId / path & query params / requestBody / responses / security / tags
     * are derived from the signature + the new OpenApi attributes; the OpenApiEmitter (Step 4) consumes this.
     *
     * @return list<OperationMetadata>
     */
    public function compileOperations(ReflectionClass $reflection): array
    {
        $operations = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttributes = $method->getAttributes(Route::class);
            if ($routeAttributes === []) {
                continue;
            }
            $methodRoute = $routeAttributes[0]->newInstance();

            $operation = $this->attributeReader->firstInstance($method, Operation::class);
            if ($operation?->exclude === true) {
                continue; // #[Operation(exclude: true)] — reachable at runtime, hidden from the spec.
            }

            $httpMethods = $methodRoute->getHttpMethods();
            if (empty($httpMethods)) {
                $httpMethods = ['GET'];
            }

            foreach ($httpMethods as $httpMethod) {
                $httpMethodString = is_string($httpMethod) ? $httpMethod : $httpMethod->value;
                $operations[] = $this->buildOperation($reflection, $method, $httpMethodString, $methodRoute->getPath(), $operation);
            }
        }

        return $operations;
    }

    /**
     * Compile operations for an explicit controller set and assert global operationId uniqueness.
     *
     * @param list<class-string> $classes
     * @return list<OperationMetadata>
     */
    public function compileOperationClasses(array $classes): array
    {
        $operations = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            foreach ($this->compileOperations(new ReflectionClass($class)) as $operation) {
                $operations[] = $operation;
            }
        }
        // buildOperation resolved ids PURELY; record every operation (the legacy path has no override
        // shadowing, so all operations are effective) and assert global uniqueness.
        foreach ($operations as $operation) {
            if ($operation->controller !== null && $operation->method !== null) {
                $this->operationIds->record($operation->operationId, $operation->controller, $operation->method);
            }
        }
        $this->operationIds->assertUnique();
        return $operations;
    }

    /**
     * Discover controllers from dirs and compile their operations (mirrors {@see compile()}).
     *
     * @param list<string> $discoveryPaths
     * @return list<OperationMetadata>
     */
    public function compileAllOperations(array $discoveryPaths): array
    {
        $classes = [];
        foreach ($discoveryPaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->discoverControllerClasses($dir) as $class) {
                if ($class !== null) {
                    $classes[] = $class;
                }
            }
        }
        return $this->compileOperationClasses($classes);
    }

    /**
     * ONE discovery/reflection flow yielding the EFFECTIVE route IR + operation projection (Step 5 fix-pass,
     * plan §11.1). Duplicate METHOD:path keys declared in $routeOverrideMap keep their declared winner and
     * shadow the rest: shadowed operations are excluded from the projection (no OpenAPI, no operationId
     * uniqueness check) and shadowed routes from the IR. A duplicate key with NO override is a genuine
     * structural ERROR (last-wins in the IR, parity with Router). The winner is map-chosen — independent of
     * discovery order.
     *
     * @param list<string> $discoveryPaths
     * @param array<string, string> $routeOverrideMap METHOD:path => winner "controller::method"
     */
    public function compileEndpointSet(array $discoveryPaths, array $routeOverrideMap = []): EndpointSet
    {
        // ONE discovery pass.
        $classes = [];
        foreach ($discoveryPaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->discoverControllerClasses($dir) as $class) {
                if ($class !== null) {
                    $classes[] = $class;
                }
            }
        }

        // ONE reflection pass per controller → route IR + operation projection together.
        $allRoutes = [];
        $allOperations = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            foreach ($this->compileController($reflection) as $route) {
                $allRoutes[] = $route;
            }
            foreach ($this->compileOperations($reflection) as $operation) {
                $allOperations[] = $operation;
            }
        }

        return $this->resolveEndpointSet($allRoutes, $allOperations, $routeOverrideMap);
    }

    /**
     * Resolve duplicate METHOD:path keys via the override map and return the effective set + applied overrides.
     *
     * @param list<RouteRuntimeMetadata> $routes
     * @param list<OperationMetadata> $operations
     * @param array<string, string> $routeOverrideMap METHOD:path => winner "controller::method"
     */
    private function resolveEndpointSet(array $routes, array $operations, array $routeOverrideMap): EndpointSet
    {
        /** @var array<string, list<RouteRuntimeMetadata>> $routesByKey */
        $routesByKey = [];
        /** @var array<string, list<OperationMetadata>> $opsByKey */
        $opsByKey = [];
        foreach ($routes as $route) {
            $routesByKey[$route->routeKey()][] = $route;
        }
        foreach ($operations as $operation) {
            $opsByKey[$this->operationRouteKey($operation)][] = $operation;
        }

        // Overrides for keys no route registers at all are invalid (stale/typo) — surface as ERROR.
        foreach ($routeOverrideMap as $key => $winner) {
            if (!isset($routesByKey[$key])) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'route_override',
                    cause: sprintf('route override for %s is declared, but no discovered route registers that key', $key),
                    fix: 'remove the override or correct the METHOD:path',
                );
            }
        }

        $effectiveRoutes = [];
        $effectiveOperations = [];
        $appliedOverrides = [];

        foreach ($routesByKey as $key => $group) {
            $opGroup = $opsByKey[$key] ?? [];
            if (count($group) < 2) {
                // A unique key with a declared override is a stale no-op override (the winner already owns the key).
                if (array_key_exists($key, $routeOverrideMap)) {
                    $this->diagnostics->warning(
                        controller: null,
                        method: null,
                        dto: null,
                        field: 'route_override',
                        cause: sprintf('route override for %s is declared, but the key is not a duplicate route', $key),
                        fix: 'remove the stale override entry',
                    );
                }
                $effectiveRoutes[] = $group[0];
                if (isset($opGroup[0])) {
                    $effectiveOperations[] = $opGroup[0];
                }
                continue;
            }

            if (!array_key_exists($key, $routeOverrideMap)) {
                // Genuine duplicate (no override) — structural ERROR; last-wins in the IR for parity.
                foreach ($group as $route) {
                    $this->diagnostics->error(
                        controller: $route->controller,
                        method: $route->method,
                        dto: null,
                        field: 'route',
                        cause: sprintf('duplicate route key %s is registered by %d operations', $key, count($group)),
                        fix: 'disambiguate the path or HTTP method, or declare an explicit route override if one shadows the other',
                    );
                }
                $effectiveRoutes[] = $group[count($group) - 1];
                if ($opGroup !== []) {
                    $effectiveOperations[] = $opGroup[count($opGroup) - 1];
                }
                continue;
            }

            // Declared override: pick the winner by signature, shadow the rest.
            $winnerSig = $routeOverrideMap[$key];
            $winnerRoute = null;
            foreach ($group as $route) {
                if ($route->controller . '::' . $route->method === $winnerSig) {
                    $winnerRoute = $route;
                    break;
                }
            }
            if ($winnerRoute === null) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'route_override',
                    cause: sprintf('route override for %s names winner %s, but no discovered route matches that controller::method', $key, $winnerSig),
                    fix: 'point the override winner at the controller::method that should own this route',
                );
                $effectiveRoutes[] = $group[count($group) - 1];
                if ($opGroup !== []) {
                    $effectiveOperations[] = $opGroup[count($opGroup) - 1];
                }
                continue;
            }

            $effectiveRoutes[] = $winnerRoute;
            $shadowed = [];
            foreach ($group as $route) {
                if ($route === $winnerRoute) {
                    continue;
                }
                $shadowed[] = $route->controller . '::' . $route->method;
            }
            // Keep only the winner's operation; shadowed operations are dropped (no OpenAPI, no id check).
            foreach ($opGroup as $operation) {
                if ($operation->controller !== null && $operation->method !== null
                    && $operation->controller . '::' . $operation->method === $winnerSig) {
                    $effectiveOperations[] = $operation;
                }
            }
            $appliedOverrides[] = ['key' => $key, 'winner' => $winnerSig, 'shadowed' => $shadowed];
        }

        // operationId uniqueness over EFFECTIVE operations only — a shadowed override never participates.
        foreach ($effectiveOperations as $operation) {
            if ($operation->controller !== null && $operation->method !== null) {
                $this->operationIds->record($operation->operationId, $operation->controller, $operation->method);
            }
        }
        $this->operationIds->assertUnique();

        return new EndpointSet($effectiveRoutes, $effectiveOperations, $appliedOverrides);
    }

    private function operationRouteKey(OperationMetadata $operation): string
    {
        return strtoupper($operation->httpMethod) . ':' . $operation->path;
    }

    /**
     * Assemble one OperationMetadata from the method reflection + the declared #[Operation] override.
     */
    private function buildOperation(
        ReflectionClass $reflection,
        ReflectionMethod $method,
        string $httpMethod,
        string $path,
        ?Operation $operation,
    ): OperationMetadata {
        $controller = $reflection->getName();

        $pathParams = $this->collectPathParams($reflection, $method, $path);
        [$requestBody, $queryParams] = $this->collectRequestProjection($reflection, $method);
        $responses = $this->collectResponses($reflection, $method);
        $security = $this->collectSecurity($method);

        // resolveId (PURE) — the id is stored on the VO, but NOT recorded for the uniqueness check yet. The
        // caller (compileOperationClasses for the legacy path, compileEndpointSet for the unified path) records
        // exactly the operations it wants in the uniqueness check — so a shadowed override never collides.
        $operationId = $this->operationIds->resolveId($controller, $method->getName(), $operation?->id);
        $tags = $operation !== null && $operation->tags !== []
            ? $operation->tags
            : [$this->operationIds->controllerShort($controller)];

        return new OperationMetadata(
            httpMethod: $httpMethod,
            path: $path,
            operationId: $operationId,
            pathParams: $pathParams,
            queryParams: $queryParams,
            requestBody: $requestBody,
            responses: $responses,
            security: $security,
            tags: $tags,
            summary: $operation?->summary,
            description: $operation?->description,
            deprecated: $operation?->deprecated ?? false,
            controller: $controller,
            method: $method->getName(),
            rateLimited: $this->isRateLimited($method),
            accessGated: $this->isAccessGated($method),
        );
    }

    /**
     * Path parameters: each {name} placeholder crossed with the method signature. The PHP type wins over the
     * historical swagger-php `string` (plan §7). A placeholder with no name-matching parameter is a compile
     * error — its type cannot be inferred and OpenAPI requires a schema for path params.
     *
     * @return list<ParameterMetadata>
     */
    private function collectPathParams(ReflectionClass $reflection, ReflectionMethod $method, string $path): array
    {
        $params = [];
        preg_match_all('/{([a-zA-Z0-9_-]+)}/', $path, $matches);
        $signature = [];
        foreach ($method->getParameters() as $parameter) {
            $signature[$parameter->getName()] = $parameter;
        }
        foreach ($matches[1] as $rawName) {
            // Router converts the placeholder kebab→camel before binding; match against that.
            $boundName = $this->convertKebabToCamelCase($rawName);
            if (!isset($signature[$boundName])) {
                // A path placeholder without a matching signature arg is a MIGRATION gap, not a structural
                // break: the runtime route still resolves (Router binds nothing), and the spec can emit a
                // placeholder string path-param. It blocks only in strict/managed mode.
                $this->diagnostics->warning(
                    controller: $reflection->getName(),
                    method: $method->getName(),
                    dto: null,
                    field: $rawName,
                    cause: sprintf('path parameter {%s} has no matching method parameter $%s', $rawName, $boundName),
                    fix: 'add a parameter whose name matches the placeholder (kebab-case is converted to camelCase), or fix the route path',
                );
                $params[] = new ParameterMetadata($rawName, ParameterMetadata::IN_PATH, required: true);
                continue;
            }
            $mapped = $this->typeMapper->map($signature[$boundName]->getType());
            $params[] = new ParameterMetadata(
                name: $rawName,
                in: ParameterMetadata::IN_PATH,
                required: true,
                type: $mapped['type'],
                format: $mapped['format'],
            );
        }
        return $params;
    }

    /**
     * Request body + query params projected from the bound DTO markers: JsonBody/PostBody/FormDataBody →
     * requestBody (contentType per marker); QueryParams → the DTO's properties projected to query params.
     *
     * @return array{0: ?RequestBodyMetadata, 1: list<ParameterMetadata>}
     */
    private function collectRequestProjection(ReflectionClass $reflection, ReflectionMethod $method): array
    {
        $requestBody = null;
        $queryParams = [];
        foreach ($method->getParameters() as $parameter) {
            $validationAttributes = $parameter->getAttributes(ValidateAttr::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($validationAttributes === []) {
                continue;
            }
            $instance = $validationAttributes[0]->newInstance();
            $dtoClass = $this->reflectionTypeName($parameter->getType());

            if ($instance instanceof QueryParams) {
                foreach ($this->queryParametersOf($dtoClass) as $queryParam) {
                    $queryParams[] = $queryParam;
                }
                continue;
            }

            if ($instance instanceof JsonBody || $instance instanceof PostBody || $instance instanceof FormDataBody) {
                $requestBody = $this->requestBodyOf($parameter, $instance, $dtoClass);
            }
        }
        return [$requestBody, $queryParams];
    }

    /**
     * Project a QueryParams DTO's properties to query parameters. Type from the PHP type; required is resolved
     * through the active {@see RequiredSource} — {@see RequiredSource::Oa} (the parity default) reads the
     * legacy OA `required:[true]` flag; {@see RequiredSource::PhpType} derives it from non-nullability + no
     * default. The two sources are never blended (plan §7).
     *
     * @return list<ParameterMetadata>
     */
    private function queryParametersOf(?string $dtoClass): array
    {
        if ($dtoClass === null || !class_exists($dtoClass)) {
            return [];
        }
        $params = [];
        foreach ($this->schemas->build($dtoClass)->properties as $property) {
            $type = null;
            $format = null;
            if ($property->refClass !== null) {
                $mapped = $this->typeMapper->mapClass($property->refClass, nullable: $property->nullable);
                $type = $mapped['type'];
                $format = $mapped['format'];
            } else {
                $type = $this->typeMapper->mapScalar($property->phpType ?? 'string');
            }
            $params[] = new ParameterMetadata(
                name: $property->serialName(),
                in: ParameterMetadata::IN_QUERY,
                required: $property->isRequired($this->requiredSource),
                type: $type,
                format: $format,
            );
        }
        return $params;
    }

    /**
     * Build the requestBody from a body marker: contentType per marker, schema from DtoSchemaBuilder, required
     * unless the parameter is optional/nullable.
     *
     * @param object $instance JsonBody|PostBody|FormDataBody
     */
    private function requestBodyOf(
        ReflectionParameter $parameter,
        object $instance,
        ?string $dtoClass,
    ): RequestBodyMetadata {
        $contentType = match (true) {
            $instance instanceof JsonBody => RequestBodyMetadata::CT_JSON,
            $instance instanceof PostBody => RequestBodyMetadata::CT_FORM_URL,
            $instance instanceof FormDataBody => RequestBodyMetadata::CT_MULTIPART,
            default => RequestBodyMetadata::CT_JSON,
        };
        $schema = $dtoClass !== null && class_exists($dtoClass) ? $this->schemas->build($dtoClass) : null;
        $type = $parameter->getType();
        $required = !$parameter->isOptional() && ($type === null || !$type->allowsNull());
        return new RequestBodyMetadata(schema: $schema, contentType: $contentType, required: $required);
    }

    /**
     * Responses: declared #[Response] entries take over entirely; otherwise infer the 200 success response
     * from the return type (DTO-eligible class / enum) — or surface a diagnostic when the return is opaque,
     * a bare array, or a non-eligible class (plan §7).
     *
     * @return list<ResponseMetadata>
     */
    private function collectResponses(ReflectionClass $reflection, ReflectionMethod $method): array
    {
        $declared = $this->attributeReader->getInstances($method, ApiResponse::class);
        if ($declared !== []) {
            $responses = [];
            foreach ($declared as $response) {
                $responses[] = $this->responseFromAttribute($method, $response);
            }
            return $responses;
        }
        return $this->inferSuccessResponse($reflection, $method);
    }

    private function responseFromAttribute(
        ReflectionMethod $method,
        ApiResponse $response,
    ): ResponseMetadata {
        $itemSchema = null;
        if ($response->schema !== null) {
            if (!class_exists($response->schema)) {
                $this->diagnostics->error(
                    controller: $method->getDeclaringClass()->getName(),
                    method: $method->getName(),
                    dto: $response->schema,
                    field: 'return',
                    cause: sprintf('#[Response] schema class %s does not exist / is not autoloadable', $response->schema),
                    fix: 'point #[Response(schema:)] at a loadable class',
                );
            } else {
                $itemSchema = $this->schemas->build($response->schema);
            }
        }
        // #[Response(collection: true)] disambiguates an array body: the schema is the per-ITEM shape and the
        // response projects type:array, items:{schema}. Without it the schema is a single object body.
        if ($response->collection) {
            if ($itemSchema === null) {
                // A collection flag with no item schema is an M7 migration gap (the array shape is unknown);
                // the spec can still emit type:array, just without an items schema. Fatal only in strict mode.
                $this->diagnostics->warning(
                    controller: $method->getDeclaringClass()->getName(),
                    method: $method->getName(),
                    dto: null,
                    field: 'return',
                    cause: '#[Response(collection: true)] declares no item schema (schema: …); the array element shape is not derivable',
                    fix: 'declare the item shape: #[Response(schema: ItemDto::class, collection: true)]',
                );
            }
            return new ResponseMetadata(
                status: $response->status,
                schema: null,
                arrayItem: $itemSchema,
                contentType: $response->contentType,
                description: $response->description ?? '',
                headers: $response->headers,
            );
        }
        return new ResponseMetadata(
            status: $response->status,
            schema: $itemSchema,
            contentType: $response->contentType,
            description: $response->description ?? '',
            headers: $response->headers,
        );
    }

    /**
     * Infer the success (200) response from the return type, emitting a diagnostic when it cannot be derived.
     *
     *  - void/null/never              : empty body (no schema) — intentional, no diagnostic.
     *  - missing return type / mixed  : opaque — diagnostic (cannot infer a schema).
     *  - bare array                   : no derivable item type — diagnostic (use #[Response(collection: true, schema: …)]).
     *  - union / intersection         : unsupported — diagnostic.
     *  - non-eligible class           : entity/framework type — diagnostic (needs explicit #[Response]).
     *  - JsonSerializable class       : custom serialization shape — diagnostic (needs explicit #[Response] contract).
     *  - DTO-eligible class           : object schema (inferred).
     *  - enum                         : enum schema fragment.
     *  - scalar / DateTime / Uuid     : inline schema fragment {type, format}.
     *
     * @return list<ResponseMetadata>
     */
    private function inferSuccessResponse(ReflectionClass $reflection, ReflectionMethod $method): array
    {
        $returnType = $method->getReturnType();

        if ($returnType === null) {
            $this->diagnostics->warning(
                controller: $reflection->getName(),
                method: $method->getName(),
                dto: null,
                field: 'return',
                cause: 'method declares no return type; the success response schema cannot be inferred',
                fix: 'add a return type (a *Dto, an enum, a scalar), or declare the response explicitly with #[Response(schema: …)]',
            );
            return [new ResponseMetadata(200, schema: null, description: 'OK')];
        }

        [$inner] = $this->unwrapNullable($returnType);

        if ($this->isVoidType($inner)) {
            return [new ResponseMetadata(200, schema: null, description: 'OK')];
        }

        if ($this->isMixedType($inner)) {
            $this->diagnostics->warning(
                controller: $reflection->getName(),
                method: $method->getName(),
                dto: null,
                field: 'return',
                cause: 'mixed return type is not auto-derivable',
                fix: 'narrow the return type, or declare the response explicitly with #[Response(schema: …)]',
            );
            return [new ResponseMetadata(200, schema: null, description: 'OK')];
        }

        if ($this->isArrayType($inner)) {
            $this->diagnostics->warning(
                controller: $reflection->getName(),
                method: $method->getName(),
                dto: null,
                field: 'return',
                cause: 'array return type has no derivable item type',
                fix: 'declare the response explicitly with #[Response(schema: ItemDto::class, collection: true)] (PHP arrays carry no element type)',
            );
            return [new ResponseMetadata(200, schema: null, description: 'OK')];
        }

        $mapped = $this->typeMapper->map($inner);
        if ($this->typeMapper->isUnsupported($mapped)) {
            $this->diagnostics->warning(
                controller: $reflection->getName(),
                method: $method->getName(),
                dto: null,
                field: 'return',
                cause: 'return type ' . $this->typeLabel($inner) . ' is not auto-derivable: ' . ($mapped['reason'] ?? 'unsupported'),
                fix: 'declare the response explicitly with #[Response(schema: …)] or simplify the return type',
            );
            return [new ResponseMetadata(200, schema: null, description: 'OK')];
        }

        // A referenced class is auto-derived ONLY when it is DTO-eligible (plan §6); entities/Response need
        // an explicit #[Response]. A class that customizes JSON via JsonSerializable likewise needs an explicit
        // contract — its public properties are not its real JSON shape.
        if ($mapped['ref'] !== null) {
            if (!$this->eligibility->isEligible($mapped['ref'])) {
                $this->diagnostics->warning(
                    controller: $reflection->getName(),
                    method: $method->getName(),
                    dto: $mapped['ref'],
                    field: 'return',
                    cause: sprintf('return type %s is not a DTO-eligible class (entity/framework type)', $mapped['ref']),
                    fix: 'declare the response explicitly with #[Response(schema: ' . $mapped['ref'] . '::class)] or return a *Dto',
                );
                return [new ResponseMetadata(200, schema: null, description: 'OK')];
            }
            if ($this->declaresJsonSerializable($mapped['ref'])) {
                $this->diagnostics->warning(
                    controller: $reflection->getName(),
                    method: $method->getName(),
                    dto: $mapped['ref'],
                    field: 'return',
                    cause: sprintf('return type %s implements JsonSerializable; its JSON shape is custom and not derivable from public properties', $mapped['ref']),
                    fix: 'declare the response explicitly with #[Response(schema: ' . $mapped['ref'] . '::class)] (the explicit contract), or drop JsonSerializable',
                );
                return [new ResponseMetadata(200, schema: null, description: 'OK')];
            }
            $schema = class_exists($mapped['ref']) ? $this->schemas->build($mapped['ref']) : null;
            return [new ResponseMetadata(200, schema: $schema)];
        }

        // Scalar / enum / DateTime / Uuid → inline schema fragment {type, format, enum?}.
        return [new ResponseMetadata(200, schema: $this->inlineSchema($mapped))];
    }

    /**
     * An inline (non-object) schema fragment: an enum ({type, enum}) or a scalar/DateTime ({type, format}).
     *
     * @param array{type: ?string, format: ?string, enum: ?array} $mapped
     */
    private function inlineSchema(array $mapped): SchemaMetadata
    {
        if ($mapped['enum'] !== null) {
            return new SchemaMetadata(
                name: '',
                isEnum: true,
                enumType: $mapped['type'],
                enumCases: $mapped['enum'],
            );
        }
        return new SchemaMetadata(
            name: '',
            type: $mapped['type'],
            format: $mapped['format'],
        );
    }

    /**
     * Security projection: anonymous when #[NoAuthAccess]; otherwise bearerAuth + the method-level
     * required-rules (any/all) for the x-required-rules extension. Unlike the runtime access_rules IR, this
     * projection keeps `any` and `all` INDEPENDENT — an AccessRulesAll-only action reports all=[…] here even
     * though the runtime collapses it to [] (plan §3/§6C).
     */
    private function collectSecurity(ReflectionMethod $method): SecurityMetadata
    {
        if ($method->getAttributes(NoAuthAccess::class) !== []) {
            return new SecurityMetadata(scheme: null);
        }
        $any = [];
        $all = [];
        foreach ($method->getAttributes(AccessRulesAny::class) as $attribute) {
            $any = $attribute->newInstance()->getRequiredRules();
        }
        foreach ($method->getAttributes(AccessRulesAll::class) as $attribute) {
            $all = $attribute->newInstance()->getRequiredRules();
        }
        return new SecurityMetadata(
            scheme: 'bearerAuth',
            requiredRules: ['any' => $any, 'all' => $all],
        );
    }

    /**
     * Whether the method carries a #[RateLimit] (class OR method level — rate limiting merges across both like
     * middlewares). Drives the 429 standard error response (StandardErrorPolicy, Step 4).
     */
    private function isRateLimited(ReflectionMethod $method): bool
    {
        if ($method->getAttributes(RateLimit::class) !== []) {
            return true;
        }
        $declaring = $method->getDeclaringClass();
        return $declaring->getAttributes(RateLimit::class) !== [];
    }

    /**
     * Whether the RUNTIME access pipeline actually enforces rules on this action (would throw 403 on denial).
     * Mirrors {@see collectAccessRules()}: effective only when #[AccessRulesAny] is present (the All-only ⇒ []
     * quirk means #[AccessRulesAll] alone enforces NOTHING at runtime) and the action is not anonymous. This is
     * the 403 signal for {@see StandardErrorPolicy} — deliberately the EFFECTIVE runtime pipeline, not the
     * documentation projection (which keeps any/all independent for x-required-rules).
     */
    private function isAccessGated(ReflectionMethod $method): bool
    {
        $rules = $this->collectAccessRules($method);
        if ($rules === []) {
            return false;
        }
        // collectAccessRules returns ['NO_AUTH_ACCESS'] for anonymous actions.
        return ($rules[0] ?? null) !== 'NO_AUTH_ACCESS';
    }

    // --- small reflection/type helpers (TypeMapper covers the actual mapping) ---

    private function convertKebabToCamelCase(string $str): string
    {
        return lcfirst(str_replace('-', '', ucwords($str, '-')));
    }

    /**
     * First non-builtin class name of a (possibly union/nullable) type, mirroring DtoSchemaBuilder's
     * reflectionClassName so a body/query marker resolves the same DTO the runtime Validator binds.
     */
    private function reflectionTypeName(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $nested) {
                $name = $this->reflectionTypeName($nested);
                if ($name !== null) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * Unwrap nullable: T|null (and ?T) collapse to T. Genuine unions/intersections are returned as-is and
     * later flagged unsupported by TypeMapper.
     *
     * @return array{0: ?ReflectionType, 1: bool}
     */
    private function unwrapNullable(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type, $type->allowsNull()];
        }
        if ($type instanceof ReflectionUnionType) {
            $nonNull = [];
            foreach ($type->getTypes() as $nested) {
                if ($nested instanceof ReflectionNamedType && $nested->getName() === 'null') {
                    continue;
                }
                $nonNull[] = $nested;
            }
            if (count($nonNull) === 1 && $nonNull[0] instanceof ReflectionNamedType) {
                return [$nonNull[0], true];
            }
        }
        return [$type, false];
    }

    private function isVoidType(?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType
            && in_array($type->getName(), ['void', 'never', 'null'], true);
    }

    private function isMixedType(?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType && $type->getName() === 'mixed';
    }

    private function isArrayType(?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType && in_array($type->getName(), ['array', 'iterable'], true);
    }

    /**
     * Whether a class customizes its JSON output via JsonSerializable (directly or inherited). Such a class's
     * public properties are NOT its real JSON shape, so an inferred response schema would lie — it needs an
     * explicit #[Response] contract (plan §6).
     */
    private function declaresJsonSerializable(string $fqcn): bool
    {
        return is_a($fqcn, \JsonSerializable::class, true);
    }

    private function typeLabel(?ReflectionType $type): string
    {
        return $type === null ? '(none)' : (string) $type;
    }
}
