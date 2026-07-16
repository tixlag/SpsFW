<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * Explicit application context handed to the {@see Coordinator}.
 *
 * The SpsFW compile-engine does NOT own application bootstrap: it does not load env, dynamic config,
 * Config::init() or DI bindings. Those are the client's responsibility (the production compilation owner
 * is the client preload). The Coordinator instead receives everything it needs through this VO, so the
 * engine stays side-effect-free and testable in isolation.
 *
 *  - projectRoot      : absolute path to the application using the framework
 *  - cachePath        : absolute path to the cache directory (where compiled_* files live / are staged)
 *  - discoveryPaths   : directories scanned for controllers / classes ( mirrors PathManager::getControllersDirs()
 *                       + the all-classes DI discovery )
 *  - configInputs     : inputs that affect the build and must be hashed into the manifest fingerprint
 *                       (di_config, openapi_escape_hatch, operation_id_map.lock, standard_error_policy, …)
 *  - mode             : ownership mode — 'legacy' (lazy BC, default) or 'managed' (preload-built, fail-fast in prod)
 *  - diagnosticPolicy : 'parity' (ERROR blocks; WARNING tolerated until M7) or 'strict' (ERROR and WARNING block).
 *
 * OWNERSHIP MODE and DIAGNOSTIC STRICTNESS are deliberately DECOUPLED (plan §11.4, Step 5 requirement): they are
 * independent axes. `mode` decides WHO builds and whether the runtime may lazily rebuild (a Step 6a concern); it
 * does NOT change what the Coordinator itself considers publishable. `diagnosticPolicy` decides the Coordinator's
 * own publication gate. So `managed + parity` is a legitimate configuration (preload-built, but warnings tolerated
 * through the M7 migration), and `legacy + strict` is legitimate too. An ERROR blocks publication in EVERY policy;
 * only a WARNING's effect depends on the policy.
 */
final readonly class ApplicationContext
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_MANAGED = 'managed';

    public const POLICY_PARITY = 'parity';
    public const POLICY_STRICT = 'strict';

    /**
     * @param string $projectRoot
     * @param string $cachePath
     * @param list<string> $discoveryPaths
     * @param array<string, mixed> $configInputs
     * @param string $mode one of ApplicationContext::MODE_* (legacy|managed)
     * @param string $diagnosticPolicy one of ApplicationContext::POLICY_* (parity|strict)
     */
    public function __construct(
        public string $projectRoot,
        public string $cachePath,
        public array $discoveryPaths,
        public array $configInputs = [],
        public string $mode = self::MODE_LEGACY,
        public string $diagnosticPolicy = self::POLICY_PARITY,
    ) {
        if ($mode !== self::MODE_LEGACY && $mode !== self::MODE_MANAGED) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown ApplicationContext mode %s; allowed values: "%s", "%s".',
                $mode,
                self::MODE_LEGACY,
                self::MODE_MANAGED,
            ));
        }
        if ($diagnosticPolicy !== self::POLICY_PARITY && $diagnosticPolicy !== self::POLICY_STRICT) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown ApplicationContext diagnostic policy %s; allowed values: "%s", "%s".',
                $diagnosticPolicy,
                self::POLICY_PARITY,
                self::POLICY_STRICT,
            ));
        }
    }

    /**
     * Whether a WARNING blocks publication under this policy. (An ERROR blocks in every policy; this only governs
     * the migration-gap warnings.)
     */
    public function warningsBlock(): bool
    {
        return $this->diagnosticPolicy === self::POLICY_STRICT;
    }
}
