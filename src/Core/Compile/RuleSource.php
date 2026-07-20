<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * The producer of a route-cache DTO validation rule graph (plan §7/§15, Step 7 / M5).
 *
 *  - 'legacy'   (default, byte-compat): the route cache `dtos[].rules` come from the legacy OA source
 *               {@see \SpsFW\Core\Router\Router::extractValidationRules()} — the exact graph today's runtime Validator
 *               consumes. PURE rollback: no introspection is added, no gate runs, the emitted set is byte-identical to
 *               what Router always wrote. The default keeps the M5 switch BEHIND a flag (plan §15: «за флагом»).
 *  - 'metadata' (opt-in, parity-gated): the rules come from {@see \SpsFW\Core\Compile\Introspection\DtoSchemaBuilder}
 *               (the unified producer). Before the metadata graph is allowed to publish, it is checked STRICTLY equal
 *               (=== — keys, key order, value types, recursively) against the legacy OA source; any divergence is a
 *               FATAL {@see CompileDiagnostics} error that blocks publication, leaving the old set untouched.
 *
 * This is a FIRST-CLASS {@see ApplicationContext} compile input (not hidden in configInputs): it participates in the
 * fingerprint + manifest so flipping the source deterministically invalidates the cache. It is DISTINCT from
 * {@see CompileMode} (legacy|managed = WHO builds + whether runtime may lazily rebuild) and from
 * {@see \SpsFW\Core\Compile\Introspection\RequiredSource} (Oa|PhpType = query-param `required` projection). RuleSource
 * is purely the route-cache rule-graph producer switch.
 *
 * NO environment is read here (plan requirement): RuleSource is resolved ONCE by the caller (preload/CLI/config) via
 * {@see fromString()} and passed as a typed value to {@see ApplicationContext}, so no builder reaches for env state.
 * The default is 'legacy' for full backward compatibility — an UNSET/EMPTY value resolves to Legacy; a NON-EMPTY value
 * that is not exactly "legacy" or "metadata" is an EXPLICIT error (no silent Legacy fallback), so a misconfigured
 * source surfaces as a deployment failure rather than quietly emitting the old graph.
 */
enum RuleSource: string
{
    case Legacy = 'legacy';
    case Metadata = 'metadata';

    /**
     * Resolve a source from an explicit string (config value, CLI flag): empty ⇒ Legacy; "legacy"/"metadata" ⇒ the
     * matching case; anything else ⇒ {@see \InvalidArgumentException}. The SINGLE resolution point with the
     * explicit-error contract — the CLI/preload resolve through it so the rule lives in one place. An invalid value
     * is a programming/deployment error, not a runtime condition.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            return self::Legacy;
        }

        return self::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf(
            'Unknown rule source %s; allowed values: "legacy", "metadata" (or an empty/unset value for legacy).',
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
