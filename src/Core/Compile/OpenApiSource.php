<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * The producer of the PRIMARY `.cache/swagger/openapi.yml` document (plan §13/§15, Step 8 / M6).
 *
 *  - 'legacy'   (default, byte-compat): the PRIMARY spec comes from the legacy swagger-php producer
 *               {@see \SpsFW\Core\DocsUtil::produceLegacyOpenApiYaml()} — the exact document Orval reads
 *               today. PURE rollback: the graph emitter still ships the SECONDARY openapi.generated.yml
 *               unchanged, and the primary is byte-identical to the historical build. The default keeps
 *               the M6 capability BEHIND a flag (plan §15: «за флагом»); nothing flips in production.
 *  - 'metadata' (opt-in, validated): the PRIMARY spec is the metadata-graph document (built ONCE by
 *               {@see \SpsFW\Core\Compile\OpenApi\OpenApiEmitter}) merged with the narrow OA escape hatch
 *               ({@see \SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatchMerger}) — only `components.schemas.*`
 *               fragments are allowed — and then passed through {@see OpenApiValidator}. The legacy
 *               swagger-php full scan is NOT run for the primary under this mode.
 *
 * This is a FIRST-CLASS {@see ApplicationContext} compile input (not hidden in configInputs): it participates
 * in the fingerprint (via {@see ApplicationContext}'s recorded `openapi_source` config) + manifest so
 * flipping the producer deterministically invalidates the cache. It is a FOURTH independent axis — DISTINCT
 * from {@see CompileMode} (legacy|managed = WHO builds + whether runtime may lazily rebuild), from
 * {@see RuleSource} (legacy|metadata = route-cache DTO rule-graph producer), and from diagnosticPolicy
 * (parity|strict). Any combination is legitimate; e.g. `managed + legacy + rule_source=metadata` is today's
 * N production config (the route rule graph already comes from metadata while the primary OpenAPI stays
 * legacy swagger-php), and `managed + metadata` is the M6 opt-in exercised by the throwaway probe.
 *
 * NO environment is read here (plan requirement): OpenApiSource is resolved ONCE by the caller
 * (preload/CLI/config) via {@see fromString()} and passed as a typed value to {@see ApplicationContext}, so
 * no builder reaches for env state. The default is 'legacy' for full backward compatibility — an
 * UNSET/EMPTY value resolves to Legacy; a NON-EMPTY value that is not exactly "legacy" or "metadata" is an
 * EXPLICIT error (no silent Legacy fallback), so a misconfigured source surfaces as a deployment failure
 * rather than quietly shipping the wrong spec.
 */
enum OpenApiSource: string
{
    case Legacy = 'legacy';
    case Metadata = 'metadata';

    /**
     * Resolve a source from an explicit string (config value, CLI flag): empty ⇒ Legacy; "legacy"/
     * "metadata" ⇒ the matching case; anything else ⇒ {@see \InvalidArgumentException}. The SINGLE
     * resolution point with the explicit-error contract — the CLI/preload resolve through it so the rule
     * lives in one place. An invalid value is a programming/deployment error, not a runtime condition.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            return self::Legacy;
        }

        return self::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf(
            'Unknown openapi source %s; allowed values: "legacy", "metadata" (or an empty/unset value for legacy).',
            var_export($value, true),
        ));
    }

    public function isLegacy(): bool
    {
        return $this === self::Legacy;
    }

    public function isMetadata(): bool
    {
        return $this === self::Metadata;
    }
}
