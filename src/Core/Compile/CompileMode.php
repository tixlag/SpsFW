<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * The single typed source of the framework compile mode (Step 6a, plan §11.4).
 *
 *  - 'legacy' (default) preserves the historic lazy behavior: a route-cache miss triggers a runtime scan/compile,
 *    the DI cache is built lazily on demand, Bootstrap rebuilds DI, and the HTTP rebuild endpoints / DocsUtil may
 *    rebuild at will. Full backward compatibility.
 *  - 'managed' declares that the application preload ({@see Coordinator}) built the whole artifact set BEFORE the
 *    container served traffic. The runtime entrypoints (Router, Bootstrap, DocsUtil, the HTTP rebuild controllers)
 *    MUST NOT scan/reflect/compile; a missing or invalid cache fails fast instead of silently rebuilding.
 *
 * This is the ONLY place that reads the SPSFW_COMPILE_MODE environment variable, so the mode is not scattered across
 * Router/Bootstrap/DocsUtil as ad-hoc env reads. The default is 'legacy' for full backward compatibility — an
 * UNSET/EMPTY value resolves to Legacy; a NON-EMPTY value that is not exactly "legacy" or "managed" is an EXPLICIT
 * error (no silent Legacy fallback), so a misconfigured mode surfaces as a deployment failure rather than quietly
 * degrading to lazy behavior. Applications opt into 'managed' by setting the env (Step 6b wires the consumer preload).
 */
enum CompileMode: string
{
    case Legacy = 'legacy';
    case Managed = 'managed';

    /**
     * Resolve the current mode from the environment: empty/unset ⇒ Legacy (full backward compatibility); any
     * non-empty value that is NOT exactly "legacy" or "managed" ⇒ an immediate, EXPLICIT error (no silent Legacy
     * fallback — a typo like "maanged" must surface as a deployment failure, not quietly run lazy). Read on every
     * call (cheap) so a process that flips the env observes the change without a cached stale value.
     */
    public static function current(): self
    {
        return self::fromString($_ENV['SPSFW_COMPILE_MODE'] ?? (getenv('SPSFW_COMPILE_MODE') ?: ''));
    }

    /**
     * Resolve a mode from an explicit string (env value, CLI flag, config): empty ⇒ Legacy; "legacy"/"managed" ⇒ the
     * matching case; anything else ⇒ {@see \InvalidArgumentException}. This is the SINGLE resolution point with the
     * explicit-error contract — {@see current()} and the CLI/preload resolve through it so the rule lives in one
     * place. An invalid value is a programming/deployment error, not a runtime condition.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            return self::Legacy;
        }

        return self::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf(
            'Unknown compile mode %s; allowed values: "legacy", "managed" (or an empty/unset value for legacy).',
            var_export($value, true),
        ));
    }

    public function isManaged(): bool
    {
        return $this === self::Managed;
    }

    public function isLegacy(): bool
    {
        return $this === self::Legacy;
    }
}
