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
use SpsFW\Core\Compile\RuleSource;
use SpsFW\Core\Compile\Introspection\AttributeReader;
use SpsFW\Core\Compile\Introspection\DtoEligibility;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Introspection\OperationIdResolver;
use SpsFW\Core\Compile\Introspection\RequiredSource;
use SpsFW\Core\Compile\Introspection\ResponseAstAnalyzer;
use SpsFW\Core\Compile\Introspection\SuccessInference;
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
use SpsFW\Core\Router\Router;
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

    /**
     * Conservative AST inference of `Response::json(...)` success bodies (M8b + fix-pass). The error-status
     * inference was removed — endpoint-specific codes now come only from `Route::errors` and explicit
     * `#[ApiResponse]`s. Injected for testability; defaults to a stateless analyzer. File-level parse + resolve
     * cache ⇒ cheap.
     */
    private readonly ResponseAstAnalyzer $ast;

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
        ?DtoSchemaBuilder $schemas = null,
        private readonly TypeMapper $typeMapper = new TypeMapper(),
        private readonly AttributeReader $attributeReader = new AttributeReader(),
        private readonly DtoEligibility $eligibility = new DtoEligibility(),
        private readonly RequiredSource $requiredSource = RequiredSource::Oa,
        array $operationIdMap = [],
        private readonly RuleSource $ruleSource = RuleSource::Legacy,
        private readonly ?\Closure $legacyRuleSource = null,
        ?ResponseAstAnalyzer $astAnalyzer = null,
    ) {
        // Share diagnostics so a cyclic/missing DTO surfaces on the SAME collector that halts the build,
        // instead of the throwaway CompileDiagnostics a default-constructed builder would carry.
        $this->schemas = $schemas ?? new DtoSchemaBuilder($this->diagnostics);
        $this->ast = $astAnalyzer ?? new ResponseAstAnalyzer();
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

    /**
     * Produce the DTO rule graph for the route cache (Step 7 / M5 producer switch, plan §15).
     *
     *  - {@see RuleSource::Legacy} (default, byte-compat): the graph comes from the legacy OA source
     *    {@see Router::extractValidationRules()} verbatim — pure rollback, NO gate, byte-identical to what Router
     *    always wrote. The M5 switch stays BEHIND a flag until parity is proven.
     *  - {@see RuleSource::Metadata} (opt-in): the graph comes from {@see DtoSchemaBuilder} (the unified producer),
     *    gated strict-=== against the legacy source via {@see enforceParity()}. A divergence is a FATAL error that
     *    blocks publication; the IR is still assembled with the metadata graph, but the Coordinator never publishes
     *    on an error, so the old set stays byte-identical.
     *
     * ruleGraphFor (not build()) — the route-cache path wants ONLY the rule graph; a DTO's schema projection (and any
     * future schema-only diagnostics) must not leak into route-cache compilation.
     */
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

        $legacy = $this->legacyRulesFor($dtoClass);

        // LEGACY: emit the OA-sourced graph verbatim (pure rollback, no gate).
        if ($this->ruleSource->isLegacy()) {
            return new ValidationRuleGraph($legacy);
        }

        // METADATA: emit the DtoSchemaBuilder graph, after proving it equals the legacy OA source exactly.
        $metadata = $this->schemas->ruleGraphFor($dtoClass)->rules;
        $this->enforceParity($controller, $method, $param, $dtoClass, $legacy, $metadata);
        return new ValidationRuleGraph($metadata);
    }

    /**
     * The legacy OA-sourced rule graph for a DTO — the byte-compat EMIT in Legacy mode and the strict-parity ORACLE
     * in Metadata mode. Defaults to {@see Router::extractValidationRules()}; a caller may inject a different source
     * (constructor $legacyRuleSource) so the parity gate is exercisable in tests without contriving a divergence in
     * production DTOs (DtoSchemaBuilder is a faithful replay, so the two never diverge on real classes).
     *
     * @return array<string, array<string, mixed>>
     */
    private function legacyRulesFor(string $dtoClass): array
    {
        return ($this->legacyRuleSource ?? Router::extractValidationRules(...))($dtoClass);
    }

    /**
     * Strict parity gate: the metadata rule graph must equal the legacy OA source exactly (=== — keys, key order,
     * value types, recursively) or the build fails with a FATAL diagnostic naming the diverging property path and
     * both sides. Runs ONLY in Metadata mode; Legacy mode IS the legacy source, so it needs no gate. Publication is
     * blocked downstream by the Coordinator's error gate, leaving the old set byte-identical.
     *
     * @param array<string, array<string, mixed>> $legacy
     * @param array<string, array<string, mixed>> $metadata
     */
    private function enforceParity(string $controller, string $method, string $param, string $dtoClass, array $legacy, array $metadata): void
    {
        $diff = RuleGraphParity::compare($legacy, $metadata);
        if ($diff === null) {
            return;
        }
        $this->diagnostics->error(
            controller: $controller,
            method: $method,
            dto: $dtoClass,
            field: $param,
            cause: sprintf(
                'rule-source parity violation: the metadata rule graph (DtoSchemaBuilder) diverges from the legacy OA source (Router::extractValidationRules) at %s — %s; legacy=%s, metadata=%s',
                $diff['path'],
                $diff['kind'],
                self::exportTruncated($diff['legacy']),
                self::exportTruncated($diff['metadata']),
            ),
            fix: 'the metadata source must match the legacy OA source byte-for-byte before it can publish; fix the divergence in DtoSchemaBuilder, or keep RuleSource::Legacy until parity holds',
        );
    }

    private static function exportTruncated(mixed $value): string
    {
        $text = var_export($value, true);
        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
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
            $explicit = $this->routeExplicitFields($routeAttributes[0]);

            // Effective exclude (M8b + fix-pass): `documented` on #[Route] is CANONICAL — an explicitly passed
            // value wins, so `documented:false` hides the operation regardless of #[Operation(exclude:true)], and
            // an explicit `documented:true` keeps it documented even if Operation says exclude. Only when
            // `documented` is NOT passed does the legacy #[Operation(exclude:true)] act as a BC fallback. An
            // explicit `documented:true` clashing with `Operation(exclude:true)` is surfaced (Route wins). Bool
            // fields need this getArguments distinction because `false` cannot be told apart from the default.
            if (array_key_exists('documented', $explicit)) {
                if ($methodRoute->documented === false) {
                    continue; // canonical exclude
                }
                if ($operation?->exclude === true) {
                    $this->diagnostics->warning(
                        controller: $reflection->getName(),
                        method: $method->getName(),
                        dto: null,
                        field: 'documented',
                        cause: '#[Route(documented: true)] conflicts with #[Operation(exclude: true)]; Route documented wins (operation stays documented)',
                        fix: 'declare exclusion on only one attribute (prefer #[Route(documented: false)])',
                    );
                }
            } elseif ($operation?->exclude === true) {
                continue; // legacy BC fallback
            }

            $httpMethods = $methodRoute->getHttpMethods();
            if (empty($httpMethods)) {
                $httpMethods = ['GET'];
            }

            foreach ($httpMethods as $httpMethod) {
                $httpMethodString = is_string($httpMethod) ? $httpMethod : $httpMethod->value;
                $operations[] = $this->buildOperation($reflection, $method, $httpMethodString, $methodRoute, $operation, $explicit);
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
     * Assemble one OperationMetadata from the method reflection + the canonical #[Route] (+ BC #[Operation]).
     *
     * Route is the canonical operation source (M8b); #[Operation] is a BC fallback read ONLY where the matching
     * Route field is unset. A field set on BOTH that disagrees is a warning diagnostic (Route wins) — surfacing
     * the duplication so the codemod can collapse Operation into Route. The two never coexist in migrated code.
     */
    private function buildOperation(
        ReflectionClass $reflection,
        ReflectionMethod $method,
        string $httpMethod,
        Route $route,
        ?Operation $operation,
        array $explicit,
    ): OperationMetadata {
        $controller = $reflection->getName();
        $path = $route->getPath();

        $pathParams = $this->collectPathParams($method, $path);
        [$requestBody, $queryParams] = $this->collectRequestProjection($reflection, $method);
        $responses = $this->collectResponses($reflection, $method, $route, $explicit);
        $security = $this->collectSecurity($method);
        $routeErrors = $this->collectErrorProjection($route, $method);

        // resolveId (PURE) — the id is stored on the VO, but NOT recorded for the uniqueness check yet. The
        // caller (compileOperationClasses for the legacy path, compileEndpointSet for the unified path) records
        // exactly the operations it wants in the uniqueness check — so a shadowed override never collides.
        // Explicit operationId = Route override, falling back to the legacy #[Operation] id; otherwise the
        // lockfile/convention resolves it (operationId stays lockfile-derived by default — M8b decision).
        if ($route->operationId !== null && $operation?->id !== null && $route->operationId !== $operation->id) {
            $this->diagnostics->warning(
                controller: $controller,
                method: $method->getName(),
                dto: null,
                field: 'operationId',
                cause: sprintf('#[Route(operationId:)] %s and #[Operation(id:)] %s disagree; Route wins', $route->operationId, $operation->id),
                fix: 'declare operationId on only one attribute (prefer #[Route])',
            );
        }
        $operationId = $this->operationIds->resolveId($controller, $method->getName(), $route->operationId ?? $operation?->id);
        $tags = $this->mergedTags($controller, $route, $operation);
        [$summary, $description, $deprecated] = $this->mergedScalarFields($controller, $method, $route, $operation, $explicit);

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
            summary: $summary,
            description: $description,
            deprecated: $deprecated,
            controller: $controller,
            method: $method->getName(),
            rateLimited: $this->isRateLimited($method),
            accessGated: $this->isAccessGated($method),
            routeErrors: $routeErrors,
        );
    }

    /**
     * tags: Route wins; #[Operation] is the fallback; the controller short name is the default.
     *
     * @return list<string>
     */
    private function mergedTags(string $controller, Route $route, ?Operation $operation): array
    {
        if ($route->tags !== []) {
            if ($operation !== null && $operation->tags !== [] && $operation->tags !== $route->tags) {
                $this->diagnostics->warning(
                    controller: $controller,
                    method: null,
                    dto: null,
                    field: 'tags',
                    cause: sprintf('#[Route] tags and #[Operation] tags disagree (Route=[%s], Operation=[%s]); Route wins', implode(',', $route->tags), implode(',', $operation->tags)),
                    fix: 'declare tags only on #[Route] (Operation is a BC fallback)',
                );
            }
            return $route->tags;
        }
        return $operation !== null && $operation->tags !== []
            ? $operation->tags
            : [$this->operationIds->controllerShort($controller)];
    }

    /**
     * @param array<string, true> $explicit explicit #[Route] field names (named OR positional)
     * @return array{0: ?string, 1: ?string, 2: bool} [summary, description, deprecated]
     */
    private function mergedScalarFields(string $controller, ReflectionMethod $method, Route $route, ?Operation $operation, array $explicit): array
    {
        return [
            $this->mergeStringField($controller, $method, 'summary', $route->summary, $operation?->summary),
            $this->mergeStringField($controller, $method, 'description', $route->description, $operation?->description),
            $this->mergedDeprecated($controller, $method, $route, $operation, $explicit),
        ];
    }

    /**
     * deprecated (D1): an EXPLICITLY passed #[Route(deprecated: …)] is canonical — including `false`, which the
     * old `||`-merge could not tell apart from the default. Route wins; #[Operation(deprecated:true)] is a BC
     * fallback used only when `deprecated` is not passed on Route. An explicit Route value clashing with
     * Operation is surfaced (Route wins).
     *
     * @param array<string, true> $explicit
     */
    private function mergedDeprecated(string $controller, ReflectionMethod $method, Route $route, ?Operation $operation, array $explicit): bool
    {
        if (array_key_exists('deprecated', $explicit)) {
            if ($operation !== null && $operation->deprecated && !$route->deprecated) {
                $this->diagnostics->warning(
                    controller: $controller,
                    method: $method->getName(),
                    dto: null,
                    field: 'deprecated',
                    cause: sprintf('#[Route(deprecated: %s]) conflicts with #[Operation(deprecated: true)]; Route wins', $route->deprecated ? 'true' : 'false'),
                    fix: 'declare deprecated on only one attribute (prefer #[Route])',
                );
            }
            return $route->deprecated;
        }
        return $route->deprecated || ($operation?->deprecated ?? false);
    }

    /**
     * The set of #[Route] constructor arguments the caller passed EXPLICITLY (named OR positional), via
     * ReflectionAttribute::getArguments(). Used to distinguish an explicit `false`/default-int value (documented,
     * deprecated, successStatus) from the constructor default — the Route instance alone cannot tell them apart.
     *
     * @return array<string, true>
     */
    private function routeExplicitFields(ReflectionAttribute $routeAttribute): array
    {
        $arguments = $routeAttribute->getArguments();
        $names = [];
        try {
            foreach ((new \ReflectionMethod(Route::class, '__construct'))->getParameters() as $index => $parameter) {
                $names[$index] = $parameter->getName();
            }
        } catch (\ReflectionException) {
            return []; // defensive — treat nothing as explicit
        }
        $explicit = [];
        foreach ($arguments as $key => $_) {
            if (is_int($key)) {
                if (isset($names[$key])) {
                    $explicit[$names[$key]] = true;
                }
            } else {
                $explicit[$key] = true;
            }
        }
        return $explicit;
    }

    private function mergeStringField(string $controller, ReflectionMethod $method, string $field, ?string $routeValue, ?string $operationValue): ?string
    {
        if ($routeValue !== null) {
            if ($operationValue !== null && $operationValue !== $routeValue) {
                $this->diagnostics->warning(
                    controller: $controller,
                    method: $method->getName(),
                    dto: null,
                    field: $field,
                    cause: sprintf('#[Route] %s and #[Operation] %s disagree (%s vs %s); Route wins', $field, $field, $routeValue, $operationValue),
                    fix: 'declare ' . $field . ' only on #[Route] (Operation is a BC fallback)',
                );
            }
            return $routeValue;
        }
        return $operationValue;
    }

    /**
     * Path parameters: each {name} placeholder mapped to the method signature POSITIONALLY, mirroring the
     * runtime binding. Router::executeControllerMethod builds the action args as `matchParams` (the path
     * values in placeholder order) ++ `dtoParams` (the ValidateAttr DTO params in declaration order) and
     * invokes with `...$args`; it injects NO class-typed params (deps arrive via constructor DI /
     * $this->request, and PHP silently ignores extra positional args). So the leading #placeholder params
     * must be scalar path receivers in placeholder order; ValidateAttr DTO params follow and consume the
     * post-placeholder slots.
     *
     *  - OpenAPI parameter.name is always the RAW placeholder (it need not match the PHP param name — kebab
     *    →camel is a Router-internal concern, irrelevant to positional binding).
     *  - A scalar path receiver ⇒ the PHP type (int/string/…) of the positionally-corresponding param.
     *  - A ValidateAttr DTO param, or any class-typed param, that occupies a path-receiver slot is an
     *    INCOMPATIBLE runtime contract: the Router would feed a raw path string into it (class-typed params
     *    are not injected). Diagnosed explicitly — NOT masked by filtering the param away.
     *  - A placeholder with no scalar receiver (consumed via Request, or unused) is still a valid required
     *    string path-param. No diagnostic: the route resolves and reading a path value via Request is a
     *    legitimate pattern (PHP ignores the extra positional arg).
     *
     * @return list<ParameterMetadata>
     */
    private function collectPathParams(ReflectionMethod $method, string $path): array
    {
        preg_match_all('/{([a-zA-Z0-9_-]+)}/', $path, $matches);
        $placeholders = $matches[1];
        $placeholderCount = count($placeholders);
        $controller = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();

        $params = [];
        $cursor = 0;
        foreach ($method->getParameters() as $parameter) {
            if ($cursor >= $placeholderCount) {
                break; // remaining params are post-placeholder (DTOs / optional deps) — not path receivers
            }
            $rawName = $placeholders[$cursor];
            $isDtoParam = $parameter->getAttributes(ValidateAttr::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
            $type = $parameter->getType();
            $isClassTyped = $type instanceof ReflectionNamedType && !$type->isBuiltin();

            if ($isDtoParam) {
                // DTO in a path-receiver slot: the runtime binds matchParams BEFORE dtoParams, so a path
                // string would land on the DTO. Diagnose (do not mask); still document the slot as a string.
                $this->diagnostics->warning(
                    controller: $controller,
                    method: $methodName,
                    dto: null,
                    field: $rawName,
                    cause: sprintf(
                        'DTO parameter $%s occupies path-receiver slot {%s}; the Router binds matchParams positionally before dtoParams, so the path value would be passed into the DTO',
                        $parameter->getName(),
                        $rawName,
                    ),
                    fix: 'move scalar path parameters before any DTO parameter in the method signature',
                );
                $params[] = new ParameterMetadata($rawName, ParameterMetadata::IN_PATH, required: true);
                $cursor++;
                continue;
            }
            if ($isClassTyped) {
                // The Router injects NO class-typed action params — a class-typed param in a path-receiver
                // slot would receive a raw path string. Diagnose; still document the slot as a string.
                $this->diagnostics->warning(
                    controller: $controller,
                    method: $methodName,
                    dto: null,
                    field: $rawName,
                    cause: sprintf(
                        'class-typed parameter $%s (%s) occupies path-receiver slot {%s}; the Router does not inject class-typed action parameters — a raw path string would be passed into it',
                        $parameter->getName(),
                        $type instanceof ReflectionNamedType ? $type->getName() : (string) $type,
                        $rawName,
                    ),
                    fix: 'read the path value via Request, or declare a scalar (string/int) parameter',
                );
                $params[] = new ParameterMetadata($rawName, ParameterMetadata::IN_PATH, required: true);
                $cursor++;
                continue;
            }

            // Scalar path receiver — maps to this placeholder by positional order (name match is irrelevant).
            $mapped = $this->typeMapper->map($type);
            $params[] = new ParameterMetadata(
                name: $rawName,
                in: ParameterMetadata::IN_PATH,
                required: true,
                type: $mapped['type'],
                format: $mapped['format'],
            );
            $cursor++;
        }

        // Placeholders beyond the scalar receivers (consumed via Request, or unused) remain valid required
        // string path-params — no diagnostic (the runtime route resolves; PHP ignores the extra positional arg).
        for (; $cursor < $placeholderCount; $cursor++) {
            $params[] = new ParameterMetadata($placeholders[$cursor], ParameterMetadata::IN_PATH, required: true);
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
     * Responses (M8b: Route-first / inference-first). The SUCCESS response is resolved by priority:
     *   1. `Route::returns` (validated shape; a definite native/AST inference that CONTRADICTS it is an ERROR);
     *   2. a single 2xx `#[ApiResponse]` (the legacy/transitional success declaration);
     *   3. the native return type (DTO/enum/scalar), then the conservative `Response::json()` AST;
     *   4. a diagnostic suggesting `returns`, yielding an empty success body.
     *
     * Remaining declared `#[ApiResponse]`s now SUPPLEMENT the success (they no longer replace inference
     * wholesale — §6): error-status responses, complex bodies (headers / non-JSON), and the rare
     * multi-success-schema cases stay. An `#[ApiResponse]` redeclaring the success status with a DIFFERENT
     * schema is an ERROR. `Route::errors` codes are projected separately (collectErrorProjection).
     *
     * SCHEMA and STATUS are resolved by INDEPENDENT priority chains (D3 fix-pass), so a definite schema never
     * drags a wrong status and a known status never forces a schema:
     *   schema  : returns → single 2xx ApiResponse → native return type → AST → diagnostic;
     *   status  : explicit successStatus → single 2xx ApiResponse → unambiguous AST status → 200.
     * A success body resolved at status 204 is a compile ERROR regardless of the body's source (D4 universal).
     *
     * @param array<string, true> $explicit explicit #[Route] field names (named OR positional)
     * @return list<ResponseMetadata>
     */
    private function collectResponses(ReflectionClass $reflection, ReflectionMethod $method, Route $route, array $explicit): array
    {
        $declared = $this->attributeReader->getInstances($method, ApiResponse::class);
        $successApiResponses = array_values(array_filter(
            $declared,
            static fn (ApiResponse $r): bool => $r->status >= 200 && $r->status < 300,
        ));

        // The AST success inference feeds BOTH the schema fallback (priority 4) and the status fallback (priority
        // 3 of the status chain). Computed once; never throws.
        $astInf = $this->ast->inferSuccess($method);

        // --- STATUS (independent of schema) ---
        $status = $this->resolveSuccessStatus($reflection, $method, $route, $explicit, $successApiResponses, $astInf);

        // --- SCHEMA + the success ResponseMetadata at the resolved status ---
        if ($route->returns === null && count($successApiResponses) > 1) {
            // Multiple success schemas/statuses declared (the rare escape-hatch case, §6): keep EVERY declared
            // response verbatim — do not collapse, infer, or re-statu.
            $responses = [];
            foreach ($declared as $response) {
                $responses[] = $this->responseFromAttribute($method, $response);
            }
            return $responses;
        }

        if ($route->returns !== null) {
            $success = $this->returnsResponseMetadata($route, $method, $status);
            $this->assertReturnsNotContradicted($method, $success, $astInf);
        } elseif (count($successApiResponses) === 1) {
            $success = $this->responseFromAttribute($method, $successApiResponses[0])->withStatus($status);
        } else {
            $success = $this->inferSuccessResponseMetadata($reflection, $method, $astInf, $status);
        }

        // --- D4: a body at status 204 is an ERROR regardless of the body's source (returns/ApiResponse/native/AST) ---
        $this->assertSuccessBodyAllowedForStatus($success, $method);

        // --- success + supplementary declared responses ---
        $successBodySig = $this->responseBodySignature($success);
        $responses = [$success];
        foreach ($declared as $response) {
            $rm = $this->responseFromAttribute($method, $response);
            if ($rm->status === $success->status) {
                if ($this->responseBodySignature($rm) === $successBodySig) {
                    continue; // a redeclaration of the same success body — drop the duplicate.
                }
                $this->diagnostics->error(
                    controller: $method->getDeclaringClass()->getName(),
                    method: $method->getName(),
                    dto: null,
                    field: 'return',
                    cause: sprintf('#[Response] redeclares the success status %d with a different schema than the resolved success response', $rm->status),
                    fix: 'declare the success body once (prefer #[Route(returns: …)]); use a different status for an alternate response',
                );
                continue;
            }
            $responses[] = $rm;
        }
        return $responses;
    }

    /**
     * Resolve the success STATUS by its own priority chain (D3): explicit `Route::successStatus` (canonical —
     * D1) → a single declared 2xx ApiResponse's status → an UNAMBIGUOUS AST-inferred status → 200. Branches that
     * DISAGREE on the status (statusConflict) cannot collapse to a single status: diagnose and fall back to 200.
     *
     * @param array<string, true> $explicit
     * @param list<ApiResponse> $successApiResponses
     */
    private function resolveSuccessStatus(ReflectionClass $reflection, ReflectionMethod $method, Route $route, array $explicit, array $successApiResponses, SuccessInference $astInf): int
    {
        if (array_key_exists('successStatus', $explicit)) {
            return $route->successStatus; // explicitly passed ⇒ canonical
        }
        if (count($successApiResponses) === 1) {
            return $successApiResponses[0]->status; // an explicit declared success status
        }
        if ($astInf->status !== null) {
            return $astInf->status; // an unambiguous AST-inferred status (all success branches agree)
        }
        if ($astInf->statusConflict) {
            $this->diagnostics->warning(
                controller: $reflection->getName(),
                method: $method->getName(),
                dto: null,
                field: 'successStatus',
                cause: 'the success branches return different HTTP statuses; a single success response cannot represent them',
                fix: 'declare successStatus on #[Route] for the canonical status, or add explicit #[Response] entries for each success status',
            );
        }
        return 200;
    }

    /**
     * D4 (universal): a success response that carries a body at status 204 (No Content) is a compile ERROR,
     * regardless of where the body came from — `Route::returns`, a single `#[ApiResponse]`, the native return
     * type, or the AST body. No-content carries no body.
     */
    private function assertSuccessBodyAllowedForStatus(ResponseMetadata $success, ReflectionMethod $method): void
    {
        if ($success->status !== 204) {
            return;
        }
        if ($success->schema !== null || $success->arrayItem !== null) {
            $this->diagnostics->error(
                controller: $method->getDeclaringClass()->getName(),
                method: $method->getName(),
                dto: null,
                field: 'return',
                cause: 'the success response declares a body but the success status is 204 (No Content carries no body)',
                fix: 'drop the success body (returns / #[Response(schema:)] / the Response::json body) for a 204, or set successStatus to 200/201',
            );
        }
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
     * Infer the success SCHEMA (no `returns`, no single-2xx ApiResponse) by priority: native return type, then
     * the conservative `Response::json()` AST, then a diagnostic suggesting `#[Route(returns: …)]`. The STATUS is
     * resolved independently by the caller and passed in — schema and status are decoupled (D3).
     */
    private function inferSuccessResponseMetadata(ReflectionClass $reflection, ReflectionMethod $method, SuccessInference $astInf, int $status): ResponseMetadata
    {
        $native = $this->inferNativeSuccess($method);
        if ($native->definite) {
            return $this->responseMetadataFromInference($native, $status);
        }
        if ($astInf->definite) {
            return $this->responseMetadataFromInference($astInf, $status);
        }
        $this->diagnostics->warning(
            controller: $reflection->getName(),
            method: $method->getName(),
            dto: $native->class ?? $astInf->class,
            field: 'return',
            cause: 'the success response schema is not derivable from the return type or the response body (' . ($native->reason ?? $astInf->reason ?? 'opaque') . ')',
            fix: 'declare the success body with #[Route(returns: Dto::class)] (or returns: [Dto::class] for a list), or keep an explicit #[Response(schema: …)]',
        );
        return new ResponseMetadata($status, schema: null, description: 'OK');
    }

    /**
     * Native return-type success inference — the definite-flagged form of the old inferSuccessResponse.
     * Definite ONLY for an unambiguous DTO/enum/scalar/DateTime, or an empty (void/null) body. Opaque returns
     * (missing type / mixed / array / non-eligible class / JsonSerializable) are NON-definite — `returns`
     * may then override them without contradiction.
     *
     * D2 (fix-pass): a union like `Dto|Response|null` is now resolved — `SpsFW\Core\Http\Response` and `null` are
     * transport / no-body branches, excluded; if EXACTLY ONE eligible schema type remains it is the definite
     * success body; two or more DIFFERENT schema types remain ambiguous (non-definite). `T|null` still collapses
     * to `T` via unwrapNullable before reaching here.
     */
    private function inferNativeSuccess(ReflectionMethod $method): SuccessInference
    {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            return new SuccessInference(definite: false, reason: 'method declares no return type');
        }
        [$inner] = $this->unwrapNullable($returnType);

        if ($this->isVoidType($inner)) {
            return new SuccessInference(definite: true, status: 200);
        }
        if ($this->isMixedType($inner)) {
            return new SuccessInference(definite: false, reason: 'mixed return type is not auto-derivable');
        }
        if ($this->isArrayType($inner)) {
            return new SuccessInference(definite: false, reason: 'array return type has no derivable item type');
        }
        if ($inner instanceof ReflectionUnionType) {
            // D2: exclude the Response transport and the null no-body branch; one remaining schema type ⇒ definite.
            $remaining = [];
            foreach ($inner->getTypes() as $member) {
                if (!$member instanceof ReflectionNamedType) {
                    return new SuccessInference(definite: false, reason: 'union with an intersection/non-named member is not auto-derivable');
                }
                if ($member->getName() === 'null') {
                    continue;
                }
                if (!$member->isBuiltin() && is_a($member->getName(), \SpsFW\Core\Http\Response::class, true)) {
                    continue;
                }
                $remaining[] = $member;
            }
            if (count($remaining) !== 1) {
                $reason = $remaining === []
                    ? 'union has no schema type after excluding Response/null'
                    : 'multiple schema types in the union are ambiguous';
                return new SuccessInference(definite: false, reason: $reason);
            }
            $inner = $remaining[0];
        }
        if (!$inner instanceof ReflectionNamedType) {
            return new SuccessInference(definite: false, reason: 'return type ' . $this->typeLabel($inner) . ' is not auto-derivable');
        }

        $mapped = $this->typeMapper->map($inner);
        if ($this->typeMapper->isUnsupported($mapped)) {
            return new SuccessInference(definite: false, reason: 'return type ' . $this->typeLabel($inner) . ' is not auto-derivable: ' . ($mapped['reason'] ?? 'unsupported'));
        }
        if ($mapped['ref'] !== null) {
            if (!$this->eligibility->isEligible($mapped['ref'])) {
                return new SuccessInference(definite: false, class: $mapped['ref'], reason: $mapped['ref'] . ' is not a DTO-eligible class');
            }
            if ($this->declaresJsonSerializable($mapped['ref'])) {
                return new SuccessInference(definite: false, class: $mapped['ref'], reason: $mapped['ref'] . ' implements JsonSerializable');
            }
            return new SuccessInference(definite: true, class: $mapped['ref']);
        }
        // scalar / enum / DateTime / Uuid ⇒ inline schema fragment.
        return new SuccessInference(definite: true, inlineSchema: $this->inlineSchema($mapped));
    }

    /**
     * Materialize a success ResponseMetadata from a definite {@see SuccessInference}.
     */
    private function responseMetadataFromInference(SuccessInference $inf, int $status): ResponseMetadata
    {
        if ($inf->class !== null) {
            $schema = class_exists($inf->class) ? $this->schemas->build($inf->class) : null;
            return $inf->collection
                ? new ResponseMetadata($status, schema: null, arrayItem: $schema)
                : new ResponseMetadata($status, schema: $schema);
        }
        if ($inf->inlineSchema !== null) {
            return $inf->collection
                ? new ResponseMetadata($status, schema: null, arrayItem: $inf->inlineSchema)
                : new ResponseMetadata($status, schema: $inf->inlineSchema);
        }
        // definite empty body (void / 204 / Response::noContent()).
        return new ResponseMetadata($status, schema: null, description: 'OK');
    }

    /**
     * Validate `Route::returns` and build the success body from it (M8b §1). The STATUS is resolved by the caller
     * and passed in (schema and status are decoupled — D3); a body at status 204 is caught by the universal D4
     * check in {@see collectResponses()}, not here.
     *   - `Dto::class`                     ⇒ single object;
     *   - `[Dto::class]`                   ⇒ list of that object;
     *   - `'string'|'integer'|'number'|'boolean'|'object'` ⇒ scalar/object body;
     *   - `['string']` etc.                ⇒ list of that scalar.
     * Forbidden (ERROR, empty success body): `[]`, >1 element, nested arrays, an unknown scalar type, or a
     * nonexistent/unfit class.
     */
    private function returnsResponseMetadata(Route $route, ReflectionMethod $method, int $status): ResponseMetadata
    {
        $controller = $method->getDeclaringClass()->getName();
        $method0 = $method->getName();

        $collection = false;
        $element = $route->returns;
        if (is_array($route->returns)) {
            if ($route->returns === []) {
                $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'returns', cause: '#[Route(returns: [])] is empty; declare a DTO/scalar or omit returns to infer it', fix: "use returns: Dto::class / returns: [Dto::class] / returns: 'string', or remove returns to infer");
                return new ResponseMetadata($status, schema: null, description: 'OK');
            }
            if (count($route->returns) > 1) {
                $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'returns', cause: '#[Route(returns: …)] has more than one element; a list is expressed by wrapping a SINGLE element in []', fix: 'use returns: [Dto::class] for a list, not returns: [A::class, B::class]');
                return new ResponseMetadata($status, schema: null, description: 'OK');
            }
            $element = $route->returns[0];
            if (is_array($element)) {
                $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'returns', cause: '#[Route(returns: …)] is nested; cardinality is single-level only', fix: 'use returns: [Dto::class] (one level of nesting)');
                return new ResponseMetadata($status, schema: null, description: 'OK');
            }
            $collection = true;
        }

        $schema = $this->returnsElementSchema(is_string($element) ? $element : null, $controller, $method0);
        if ($schema === null) {
            return new ResponseMetadata($status, schema: null, description: 'OK');
        }
        return $collection
            ? new ResponseMetadata($status, schema: null, arrayItem: $schema)
            : new ResponseMetadata($status, schema: $schema);
    }

    /**
     * Build the element SchemaMetadata for a `returns` declaration (a scalar/object name or a DTO/enum class).
     * Returns null (after a fatal diagnostic) when the element is invalid. (The 204+body check is universal — D4.)
     */
    private function returnsElementSchema(?string $element, string $controller, string $method): ?SchemaMetadata
    {
        if ($element !== null && in_array($type = strtolower($element), ['string', 'integer', 'number', 'boolean', 'object'], true)) {
            return new SchemaMetadata(name: '', type: $type);
        }
        if ($element === null || !class_exists($element)) {
            $this->diagnostics->error(controller: $controller, method: $method, dto: $element, field: 'returns', cause: sprintf('#[Route(returns: …)] names a nonexistent class %s', var_export($element, true)), fix: 'point returns at a loadable DTO/enum class, or a scalar type name (string/integer/number/boolean/object)');
            return null;
        }
        $mapped = $this->typeMapper->mapClass($element);
        if ($mapped['ref'] !== null) {
            if (!$this->eligibility->isEligible($element)) {
                $this->diagnostics->error(controller: $controller, method: $method, dto: $element, field: 'returns', cause: sprintf('#[Route(returns: %s)] is not a DTO-eligible class', $element), fix: 'point returns at a *Dto (or an enum / scalar type name)');
                return null;
            }
            return $this->schemas->build($element);
        }
        if ($mapped['type'] !== null || $mapped['enum'] !== null) {
            return $this->inlineSchema($mapped);
        }
        $this->diagnostics->error(controller: $controller, method: $method, dto: $element, field: 'returns', cause: sprintf('#[Route(returns: %s)] is not a valid schema class', $element), fix: 'point returns at a *Dto, an enum, or a scalar type name');
        return null;
    }

    /**
     * A DEFINITE native / AST success inference that CONTRADICTS `Route::returns` is a compile ERROR (§1).
     * Non-definite inferences never contradict — `returns` may override them freely. The AST inference is passed
     * in (already computed once per method) rather than re-queried.
     */
    private function assertReturnsNotContradicted(ReflectionMethod $method, ResponseMetadata $declared, SuccessInference $astInf): void
    {
        $declaredSig = $this->responseBodySignature($declared);
        $controller = $method->getDeclaringClass()->getName();
        $method0 = $method->getName();

        $native = $this->inferNativeSuccess($method);
        if ($native->definite && $native->signature() !== $declaredSig) {
            $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'returns', cause: sprintf('#[Route(returns: …)] (%s) contradicts the definite native return-type inference (%s)', $declaredSig, $native->signature()), fix: 'align returns with the return type, or widen the return type so it is non-definite');
        }
        if ($astInf->definite && $astInf->signature() !== $declaredSig) {
            $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'returns', cause: sprintf('#[Route(returns: …)] (%s) contradicts the definite Response::json() body inference (%s)', $declaredSig, $astInf->signature()), fix: 'align returns with the response body, or change the body so it is non-definite');
        }
    }

    /**
     * The endpoint-specific error projection: ONLY `Route::errors` (validated list-or-map), each a
     * [code, ?description] carried on the operation for the emitter to render as an Error-schema response. Literal
     * error statuses are NO LONGER inferred from the method body (the fix-pass removed AST error inference);
     * declare endpoint-specific codes with `Route::errors` or an explicit `#[ApiResponse]`. Standard
     * 400/401/403/429/500 come from the policy and are NOT part of this projection — declaring one here only
     * overrides its description.
     *
     * @return list<array{int, ?string}>
     */
    private function collectErrorProjection(Route $route, ReflectionMethod $method): array
    {
        $merged = $this->normalizeRouteErrors($route, $method);
        usort($merged, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return $merged;
    }

    /**
     * Validate `Route::errors` to a list of [code, ?description]. STRICT list-or-map only (§5): a list of int
     * codes `[404, 409]` or a code⇒description map `[404 => '…']`; a mixed form is rejected. Only HTTP 400–599.
     *
     * @return list<array{int, ?string}>
     */
    private function normalizeRouteErrors(Route $route, ReflectionMethod $method): array
    {
        $errors = $route->errors;
        if ($errors === []) {
            return [];
        }
        $controller = $method->getDeclaringClass()->getName();
        $method0 = $method->getName();

        $allIntValues = true;
        $allStringValues = true;
        $allIntKeys = true;
        foreach ($errors as $value) {
            if (!is_int($value)) {
                $allIntValues = false;
            }
            if (!is_string($value)) {
                $allStringValues = false;
            }
        }
        foreach (array_keys($errors) as $key) {
            if (!is_int($key)) {
                $allIntKeys = false;
            }
        }
        $isList = array_is_list($errors) && $allIntValues;
        $isMap = $allIntKeys && $allStringValues;
        if (!$isList && !$isMap) {
            $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'errors', cause: "#[Route(errors: …)] must be a list of codes ([404, 409]) or a code⇒description map ([404 => '…']); a mixed form is rejected", fix: 'use one form consistently — all int codes, or all int⇒string pairs');
            return [];
        }

        $out = [];
        foreach ($errors as $key => $value) {
            $code = $isList ? $value : $key;
            $description = $isList ? null : $value;
            if ($code < 400 || $code > 599) {
                $this->diagnostics->error(controller: $controller, method: $method0, dto: null, field: 'errors', cause: sprintf('#[Route(errors: …)] code %s is outside the HTTP error range 400–599', var_export($code, true)), fix: 'use an HTTP error status (400–599)');
                continue;
            }
            $out[] = [(int) $code, $description];
        }
        return $out;
    }

    /**
     * A stable BODY-shape identity for a ResponseMetadata — mirrors {@see SuccessInference::signature()}
     * (`ref:<fqcn>` / `inline:<type>:<format>` / `none`, suffixed `[]` for a collection), WITHOUT the status.
     * Used both to drop a redeclared success duplicate and to compare a definite inference against `returns`.
     */
    private function responseBodySignature(ResponseMetadata $response): string
    {
        $body = 'none';
        if ($response->schema !== null) {
            $body = $this->schemaSignature($response->schema);
        } elseif ($response->arrayItem !== null) {
            $body = $this->schemaSignature($response->arrayItem) . '[]';
        }
        return $body;
    }

    private function schemaSignature(SchemaMetadata $schema): string
    {
        if ($schema->className !== null) {
            return 'ref:' . $schema->className;
        }
        if ($schema->isEnum) {
            return 'inline:' . ($schema->enumType ?? '') . ':' . ($schema->format ?? '');
        }
        return $schema->type !== null ? 'inline:' . $schema->type . ':' . ($schema->format ?? '') : 'none';
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
