<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * OpenAPI-only security projection of an operation. It does NOT replace
 * {@see RouteRuntimeMetadata::$accessRules}: the exact runtime access rules live there; this VO is the
 * documentation projection emitted under the operation (and `x-required-rules` vendor extension).
 *
 *  - scheme        : 'bearerAuth' unless the action is #[NoAuthAccess]; null = no security requirement
 *  - requiredRules : {any: [], all: []} distilled from #[AccessRulesAny] / #[AccessRulesAll]. Capabilities
 *                    are emitted as `x-required-rules` (they do not enter OAuth scope semantics, plan §6C).
 */
final readonly class SecurityMetadata
{
    /**
     * @param array{any: list<string>, all: list<string>} $requiredRules
     */
    public function __construct(
        public ?string $scheme = 'bearerAuth',
        public array $requiredRules = ['any' => [], 'all' => []],
    ) {
    }

    /**
     * Anonymous (no-auth) action — no security requirement is emitted.
     */
    public function isAnonymous(): bool
    {
        return $this->scheme === null;
    }

    public function hasRules(): bool
    {
        return $this->requiredRules['any'] !== [] || $this->requiredRules['all'] !== [];
    }
}
