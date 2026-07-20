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
 *  - configInputs     : SCALAR inputs that affect the build and are recorded verbatim in the manifest fingerprint
 *                       (mode, diagnostic_policy, openapi title/version — never secrets).
 *  - operationIdMap   : the tri-state operationId lockfile "<controller>::<method>" => ?string (plan §19). An
 *                       EXPLICIT, FIRST-CLASS field (Step 5 fix-pass): it is NOT hidden inside configInputs. A
 *                       present non-null keeps the legacy id; a present null keeps an op id-less; an ABSENT key
 *                       gets the controller-qualified convention. Hashed (content) into the fingerprint, never
 *                       stored wholesale in the manifest.
 *  - routeOverrideMap : compile-time route overrides "METHOD:path" => winner "controller::method" (Step 5 fix-pass).
 *                       Lets an intentional shadow (e.g. the app's controller overriding a framework template) keep
 *                       a DECLARED winner independent of discovery order; the loser is dropped from both the route IR
 *                       and the OpenAPI projection. Hashed (content) into the fingerprint, recorded in the manifest
 *                       only as the applied-override summary (no secret data).
 *  - configFiles      : logical name => ABSOLUTE path of a compile-time config file (di_config, operation_id_map
 *                       lock, route_override_map, openapi_escape_hatch). Each is hashed by CONTENT (md5) into the
 *                       fingerprint; the manifest carries only the md5, NEVER the file content — di_config may hold
 *                       secrets, so it is never stored wholesale.
 *  - lockTimeoutSec   : the whole-flow {@see \SpsFW\Core\Compile\Publication\CompileLock} timeout. A real deadline
 *                       (LOCK_NB + usleep loop); ≤0 means a single NON-BLOCKING attempt (the safe default for a
 *                       generic CLI that must not hang on a contended lock).
 *  - mode             : ownership mode — a typed {@see CompileMode} (Legacy = lazy BC default; Managed = preload-built,
 *                       fail-fast in prod), resolved once by the caller; the Coordinator records $mode->value
 *  - diagnosticPolicy : 'parity' (ERROR blocks; WARNING tolerated until M7) or 'strict' (ERROR and WARNING block).
 *
 * OWNERSHIP MODE and DIAGNOSTIC STRICTNESS are deliberately DECOUPLED (plan §11.4, Step 5 requirement): they are
 * independent axes. `mode` decides WHO builds and whether the runtime may lazily rebuild (a Step 6a concern); it
 * does NOT change what the Coordinator itself considers publishable. `diagnosticPolicy` decides the Coordinator's
 * own publication gate. So `managed + parity` is a legitimate configuration (preload-built, but warnings tolerated
 * through the M7 migration), and `legacy + strict` is legitimate too. An ERROR blocks publication in EVERY policy;
 * only a WARNING's effect depends on the policy.
 *
 * RULE SOURCE (Step 7 / M5) is a THIRD, independent axis — which producer feeds the route-cache DTO rule graph
 * (`ruleSource`): it is decoupled from both `mode` and `diagnosticPolicy`. Any combination is legitimate; e.g.
 * `managed + metadata` is the production target once parity holds, while `managed + legacy` is the byte-compat
 * rollback. See {@see RuleSource}.
 */
final readonly class ApplicationContext
{
    /** Ownership-mode aliases (the typed {@see CompileMode} enum, NOT independent strings). Kept for readable named
     *  construction (`mode: ApplicationContext::MODE_MANAGED`); they ARE the enum cases. */
    public const MODE_LEGACY = CompileMode::Legacy;
    public const MODE_MANAGED = CompileMode::Managed;

    public const POLICY_PARITY = 'parity';
    public const POLICY_STRICT = 'strict';

    /** Rule-source aliases (the typed {@see RuleSource} enum). Kept for readable named construction
     *  (`ruleSource: ApplicationContext::RULE_SOURCE_METADATA`); they ARE the enum cases. */
    public const RULE_SOURCE_LEGACY = RuleSource::Legacy;
    public const RULE_SOURCE_METADATA = RuleSource::Metadata;

    /**
     * @param string $projectRoot
     * @param string $cachePath
     * @param list<string> $discoveryPaths
     * @param array<string, mixed> $configInputs scalar config inputs (title/version …; mode & diagnostic_policy are
     *                                            recorded by the Coordinator from the typed fields below)
     * @param CompileMode $mode ownership mode — a typed {@see CompileMode}, resolved ONCE by the caller via
     *                          {@see CompileMode::current()} / {@see CompileMode::fromString()} (invalid values throw
     *                          there, never here). Default Legacy for full BC.
     * @param string $diagnosticPolicy one of ApplicationContext::POLICY_* (parity|strict)
     * @param array<string, ?string> $operationIdMap tri-state operationId lockfile "<controller>::<method>" => id|null
     * @param array<string, string> $routeOverrideMap "METHOD:path" => winner "controller::method"
     * @param array<string, string> $configFiles logical name => absolute path (content-hashed; never stored wholesale)
     * @param float $lockTimeoutSec whole-flow compile lock deadline; ≤0 = a single non-blocking attempt
     * @param list<string> $legacyOpenApiScanPaths directories the PRIMARY openapi.yml parity producer scans, in the
     *                                            caller's (historical) ORDER — DECOUPLED from {@see $discoveryPaths}.
     *                                            Route/DI discovery order (PathManager::getControllersDirs() =
     *                                            [libraryRoot, src]) is NOT the same as the legacy swagger-php scan
     *                                            order (DocsUtil::updateDocs() = [src, libraryRoot]); reusing discovery
     *                                            order for the parity spec is a latent bug (Step 6b parity fix). Empty
     *                                            => the Coordinator falls back to the EXPLICIT BC default
     *                                            [projectRoot/src, libraryRoot] (the historical DocsUtil [src,
     *                                            libraryRoot] contract — the app src taken from $projectRoot so the
     *                                            engine stays isolated from global PathManager state), NEVER to route
     *                                            discovery order. Hashed (relative-normalized, order-preserving) into
     *                                            the fingerprint; absolute paths never reach the manifest.
     * @param RuleSource $ruleSource producer of the route-cache DTO rule graph (Step 7 / M5) — a typed
     *                               {@see RuleSource}, resolved ONCE by the caller via
     *                               {@see RuleSource::fromString()} (invalid values throw there, never here). Legacy
     *                               (default) emits the OA source byte-identically (pure rollback); Metadata emits the
     *                               DtoSchemaBuilder graph gated strict-=== against the legacy source. Recorded in the
     *                               fingerprint + manifest; NO env is read to resolve it (the caller resolves).
     */
    public function __construct(
        public string $projectRoot,
        public string $cachePath,
        public array $discoveryPaths,
        public array $configInputs = [],
        public CompileMode $mode = self::MODE_LEGACY,
        public string $diagnosticPolicy = self::POLICY_PARITY,
        public array $operationIdMap = [],
        public array $routeOverrideMap = [],
        public array $configFiles = [],
        public float $lockTimeoutSec = 0.0,
        public array $legacyOpenApiScanPaths = [],
        public RuleSource $ruleSource = self::RULE_SOURCE_LEGACY,
    ) {
        // Ownership mode needs no validation here: it is a typed CompileMode, so only valid cases can exist; an
        // invalid ENV/CLI value already threw during resolution ({@see CompileMode::fromString()}).
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
