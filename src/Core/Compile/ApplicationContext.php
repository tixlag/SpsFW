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
 *  - projectRoot    : absolute path to the application using the framework
 *  - cachePath      : absolute path to the cache directory (where compiled_* files live / are staged)
 *  - discoveryPaths : directories scanned for controllers / classes ( mirrors PathManager::getControllersDirs()
 *                     + the all-classes DI discovery )
 *  - configInputs   : inputs that affect the build and must be hashed into the manifest fingerprint
 *                     (di_config, openapi_escape_hatch, operation_id_map.lock, standard_error_policy, …)
 *  - mode           : 'legacy' (lazy BC, default) or 'managed' (preload-built, fail-fast in prod)
 */
final readonly class ApplicationContext
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_MANAGED = 'managed';

    /**
     * @param string $projectRoot
     * @param string $cachePath
     * @param list<string> $discoveryPaths
     * @param array<string, mixed> $configInputs
     * @param string $mode one of ApplicationContext::MODE_*
     */
    public function __construct(
        public string $projectRoot,
        public string $cachePath,
        public array $discoveryPaths,
        public array $configInputs = [],
        public string $mode = self::MODE_LEGACY,
    ) {
    }
}
