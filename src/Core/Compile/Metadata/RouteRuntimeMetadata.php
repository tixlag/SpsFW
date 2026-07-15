<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

use SpsFW\Core\Validation\Enum\ParamsIn;

/**
 * Exact reproduction of the route cache IR — the source of truth for runtime routing & validation.
 * {@see \SpsFW\Core\Compile\Route\RouteCacheEmitter} (Step 3) serializes this byte-for-byte into
 * compiled_routes.php, so the field set and shapes mirror today's Router IR (Router.php:291–302).
 *
 *  - params         : ordered path-parameter names
 *  - middlewares    : class + method merged; shape [{class, params}] (mirrors #[Middleware] normalization)
 *  - accessRules    : EXACT current form: ['NO_AUTH_ACCESS'] | [] | ['any'=>['rules'=>..], 'all'=>['rules'=>..]].
 *                     Preserves the known quirks: AccessRulesAll-only collapses to []; class-level access is
 *                     ignored (only method-level is collected) — unlike middlewares (class + method merge).
 *  - dtos           : bindings [{in: ParamsIn, dto: class-string, rules: ValidationRuleGraph}]
 *  - phpIniSettings : from #[PhpIni]
 *
 * httpMethod is a string (not the HttpMethod enum) so the emitted cache stays identical to today's IR.
 *
 * Step 1 (M1): the VO exists but is not produced by any builder yet.
 */
final readonly class RouteRuntimeMetadata
{
    /**
     * @param array<string, null> $params ordered path-parameter names as `[name => null]` (true IR shape)
     * @param list<array{class: class-string, params: array<string, mixed>}> $middlewares
     * @param array<string, mixed> $accessRules
     * @param list<array{in: ?ParamsIn, dto: class-string, rules: ValidationRuleGraph}> $dtos
     * @param ?array<string, mixed> $phpIniSettings null when no #[PhpIni] (matches the IR)
     */
    public function __construct(
        public string $controller,
        public string $httpMethod,
        public string $method,
        public string $rawPath,
        public string $pattern,
        public array $params = [],
        public array $middlewares = [],
        public array $accessRules = [],
        public array $dtos = [],
        public ?array $phpIniSettings = null,
    ) {
    }

    /**
     * The route-cache key this metadata resolves to: `METHOD:path` (the dedup key in Router.php:290).
     */
    public function routeKey(): string
    {
        return $this->httpMethod . ':' . $this->rawPath;
    }

    public function isAnonymous(): bool
    {
        return in_array('NO_AUTH_ACCESS', $this->accessRules, true);
    }
}
