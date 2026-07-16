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
 * Router/Bootstrap/DocsUtil as ad-hoc env reads. The default is 'legacy' for full backward compatibility — nothing
 * changes for existing applications until they opt into 'managed' (Step 6b wires the consumer preload).
 */
enum CompileMode: string
{
    case Legacy = 'legacy';
    case Managed = 'managed';

    /**
     * Resolve the current mode from the environment. Default is Legacy. Read on every call (cheap) so a process that
     * flips the env between operations observes the change without a cached stale value (tests rely on this).
     */
    public static function current(): self
    {
        $raw = $_ENV['SPSFW_COMPILE_MODE'] ?? (getenv('SPSFW_COMPILE_MODE') ?: '');
        return self::tryFrom((string) $raw) ?? self::Legacy;
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
