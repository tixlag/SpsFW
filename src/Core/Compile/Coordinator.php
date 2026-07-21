<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatchMerger;
use SpsFW\Core\Compile\OpenApi\OpenApiValidator;
use SpsFW\Core\Compile\Publication\CompileLock;
use SpsFW\Core\Compile\Publication\Fingerprinter;
use SpsFW\Core\Compile\Publication\StagingPublisher;
use SpsFW\Core\Compile\Route\RouteCacheEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Router\PathManager;
use SpsFW\Core\Router\ClassScanner;
use SpsFW\Core\Router\DICacheBuilder;

/**
 * The compile-engine entry point.
 *
 * Accepts an explicit {@see ApplicationContext} and runs ONE shared compilation flow that produces the whole
 * artifact set: the route cache (RouteMetadataCompiler → RouteCacheEmitter), the DI map + job registry
 * (DICacheBuilder compile-only API), the secondary OpenAPI (OpenApiEmitter + OpenApiValidator), and the
 * publication manifest (Fingerprinter). Every stage reports to a SINGLE aggregated {@see CompileDiagnostics};
 * an ERROR forbids publication in any policy, a WARNING forbids it only under the strict policy. When publishable,
 * artifacts are staged on the same filesystem and published atomically per-file with a rollback journal, the
 * manifest going LAST (plan §11).
 *
 * The engine does NOT own application bootstrap: it does not load env, dynamic config, Config::init() or DI
 * bindings — those are the production owner's responsibility (the client `next/preload.php`, plan §11.2). It also
 * never reaches for `new Router()` to assemble the route cache: route metadata is built directly (plan §11.1), and
 * it does not decide container readiness.
 *
 * Step 5: the real flow is wired here. Ownership `mode` (legacy|managed) is CARRIED (recorded in the manifest) but
 * its runtime-guard effect is a Step 6a concern — it does not change what this engine considers publishable. The
 * `diagnosticPolicy` (parity|strict) alone governs the publication gate.
 */
final class Coordinator
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CompileDiagnostics $diagnostics = new CompileDiagnostics(),
    ) {
    }

    public function context(): ApplicationContext
    {
        return $this->context;
    }

    public function diagnostics(): CompileDiagnostics
    {
        return $this->diagnostics;
    }

    /**
     * Build the full artifact set for the application context and publish it (unless $dryRun, or the diagnostic
     * policy forbids it). Returns the outcome.
     *
     * LOCK SCOPE (Step 5 fix-pass, plan §11.3): for a NON-DRY-RUN compile the whole-flow {@see CompileLock} is taken
     * BEFORE discovery/reflection/OpenAPI/DI/fingerprint and held through publication + cleanup, released in a
     * `finally`. A pre-held lock therefore means this compile does not even discover/reflect — two concurrent
     * compiles can never interleave (never stage while another publishes, never share a staging dir, never
     * half-overwrite an artifact set), and a contended run fails before doing any work. A DRY-RUN compile is
     * lock-free and write-free (a read-only probe).
     *
     * @param bool $dryRun build + validate + report, but never publish (read-only probe)
     * @param ?\Closure(int, string): void $publishFaultHook test/dev seam threaded to {@see StagingPublisher::publish()};
     *        throwing from it injects a publication failure at an exact step, exercising the rollback + cleanup path.
     */
    public function compile(bool $dryRun = false, ?\Closure $publishFaultHook = null): CompileResult
    {
        $ctx = $this->context;

        // The whole compile flow — discovery → reflection → OpenAPI → DI → fingerprint → (publish). Factored into a
        // closure so the non-dry-run path can hold a single whole-flow lock around ALL of it, while the dry-run path
        // stays lock-free and write-free. The publish fault hook is a test/dev seam.
        $run = function () use ($ctx, $dryRun, $publishFaultHook): CompileResult {
            $fingerprinter = new Fingerprinter();

            // ---- ONE shared diagnostics collector: route compiler, OpenAPI emitter + validator, DI all report here.
            //      The tri-state operationId map is a FIRST-CLASS input (not hidden in configInputs): it is passed
            //      explicitly to the route compiler so the real `next` inventory keeps its preserved ids / nulls.
            $routeCompiler = new RouteMetadataCompiler(
                $this->diagnostics,
                operationIdMap: $ctx->operationIdMap,
                ruleSource: $ctx->ruleSource,
            );

            // ---- ONE discovery/reflection flow yields BOTH the route cache IR and the OpenAPI operation projection,
            //      with duplicate METHOD:path keys resolved to operationId uniqueness: declared overrides keep their
            //      map-chosen winner and SHADOW the rest (shadowed ops never reach OpenAPI nor the operationId check).
            $endpointSet = $routeCompiler->compileEndpointSet($ctx->discoveryPaths, $ctx->routeOverrideMap);
            $routes = $endpointSet->routes;
            $operations = $endpointSet->operations;

            $title = is_string($ctx->configInputs['openapi_title'] ?? null) ? $ctx->configInputs['openapi_title'] : 'SpsFW API';
            $version = is_string($ctx->configInputs['openapi_version'] ?? null) ? $ctx->configInputs['openapi_version'] : '0.1.0';
            $emitter = new OpenApiEmitter($this->diagnostics);
            $document = $emitter->emit($operations, title: $title, version: $version);
            (new OpenApiValidator($this->diagnostics))->validate($document);

            // DI map + job registry via the compile-only API — no production cache write, no setCompiledMap().
            $diClasses = $this->discoverDiClasses($ctx->discoveryPaths);
            $di = (new DICacheBuilder(null, ''))->compileOnly($diClasses, $this->diagnostics);

            // Deterministic, DEPLOY-PATH-INDEPENDENT fingerprint: sources keyed relative to projectRoot, compile-time
            // config files + operationId/route-override maps hashed by CONTENT (built_at deliberately excluded).
            $sourceFiles = $fingerprinter->sourceFiles($ctx->discoveryPaths);

            // PRIMARY OpenAPI parity scan paths — DECOUPLED from route/DI discovery (Step 6b parity fix). The legacy
            // swagger-php scan order is the HISTORICAL DocsUtil::updateDocs() contract [src, libraryRoot], which is the
            // REVERSE of PathManager::getControllersDirs() ([libraryRoot, src]) used for route/DI discovery. Reusing
            // discovery order here was a latent parity bug. The caller may pass an explicit ordered list; if it does
            // not, fall back to the EXPLICIT BC default [src, libraryRoot] — NEVER to route discovery order. The app's
            // src is derived from the EXPLICIT $ctx->projectRoot (NOT the global PathManager::getSrcPath()), so the
            // engine stays isolated from global path state and a test/temp projectRoot never over-scans the framework
            // tree; this equals getSrcPath() in production (same project root). Only the framework's OWN libraryRoot
            // uses PathManager (legitimate self-location).
            $legacyScanPaths = $ctx->legacyOpenApiScanPaths !== []
                ? $ctx->legacyOpenApiScanPaths
                : [$ctx->projectRoot . '/src', PathManager::getLibraryRoot()];

            $fingerprint = $fingerprinter->fingerprint(
                $sourceFiles,
                $this->recordedConfig($ctx),
                $ctx->projectRoot,
                $ctx->configFiles,
                $ctx->operationIdMap,
                $ctx->routeOverrideMap,
                $legacyScanPaths,
                $ctx->escapeHatch(),
            );

            // ---- PRIMARY OpenAPI producer is now switchable via the 4th independent OpenApiSource axis (Step 8 / M6).
            //      The GRAPH is built ONCE above ($emitter->emit) and feeds the SECONDARY openapi.generated.yml under
            //      BOTH modes (always pure graph — no escape hatch). The PRIMARY .cache/swagger/openapi.yml differs:
            //        - Legacy (default): the byte-compat swagger-php spec via DocsUtil::produceLegacyOpenApiYaml(),
            //          the historical behavior — pure rollback for every client. The full swagger-php scan runs ONLY
            //          in this branch (it is skipped under Metadata).
            //        - Metadata: the graph document merged with the narrow OA escape hatch (schemas-only fragments),
            //          then re-validated, then dumped. Any merge/shape/provenance/external-ref ERROR is fatal and
            //          blocks publication like any other ERROR (the gate below honors it).
            //      The graph is NEVER re-emitted: under Metadata the merger works on the COPY of the already-built
            //      $document (array-first contract — emit() runs once; validate/merge/dump add no new diagnostics).
            $legacyScanPathsYaml = null;
            $metadataPrimaryYaml = null;
            if ($ctx->openApiSource->isMetadata()) {
                $primaryDoc = (new OpenApiEscapeHatchMerger($this->diagnostics, $ctx->projectRoot))
                    ->merge($document, $ctx->escapeHatch());
                (new OpenApiValidator($this->diagnostics))->validate($primaryDoc);
                $metadataPrimaryYaml = $emitter->dump($primaryDoc);
            } else {
                // Legacy parity producer (plan §11.2, Step 6b #5): the historical swagger-php spec via DocsUtil's
                // generator + the [src, libraryRoot] scan set. It does NOT call DocsUtil::updateDocs() (gated in
                // managed, and it writes the live cache directly, bypassing staging); the PRIMARY spec must never
                // be left stale. The SECONDARY stays the pure graph (openapi.generated.yml).
                $legacyScanPathsYaml = DocsUtil::produceLegacyOpenApiYaml($legacyScanPaths);
            }
            $primaryYaml = $ctx->openApiSource->isMetadata() ? $metadataPrimaryYaml : $legacyScanPathsYaml;

            // ---- Publication gate: ERROR always blocks; a WARNING blocks only under the strict policy.
            $errors = $this->diagnostics->hasErrors();
            $warnings = $this->diagnostics->hasWarnings();
            $publishable = !$errors && !($ctx->warningsBlock() && $warnings);
            $overrides = $endpointSet->overrides;

            if ($dryRun) {
                return CompileResult::notPublished(true, $this->diagnostics->errorCount(), $this->diagnostics->warningCount(), $fingerprint, 'dry-run', $overrides);
            }
            if (!$publishable) {
                $reason = $errors ? 'errors' : 'strict-warnings';
                return CompileResult::notPublished(false, $this->diagnostics->errorCount(), $this->diagnostics->warningCount(), $fingerprint, $reason, $overrides);
            }

            return $this->stageAndPublish($ctx, $fingerprinter, $fingerprint, $emitter, $document, $di, $routes, $overrides, $primaryYaml, $ctx->escapeHatch(), $publishFaultHook);
        };

        // Dry-run: read-only, lock-free, write-free.
        if ($dryRun) {
            return $run();
        }

        // Non-dry-run: a SINGLE whole-flow lock acquired BEFORE discovery. The publisher takes NO lock of its own (it
        // must not re-acquire the lock held here). Staging is UNIQUE per run, never the shared `.staging-compile` name.
        // Released in a `finally` after publication + cleanup; a contended acquire throws before any discovery.
        $lock = new CompileLock($ctx->cachePath . '/.compile.lock');
        $lock->acquire($ctx->lockTimeoutSec);
        try {
            return $run();
        } finally {
            $lock->release();
        }
    }

    /**
     * Stage every artifact on the same FS, compute content hashes, build the manifest, then publish artifacts first
     * and the manifest LAST. Runs UNDER the whole-flow {@see CompileLock}; the {@see StagingPublisher} does not lock.
     *
     * @param array{compiled: array<string, mixed>, jobs: array<string, mixed>} $di
     * @param list<\SpsFW\Core\Compile\Metadata\RouteRuntimeMetadata> $routes
     * @param list<array{key: string, winner: string, shadowed: list<string>}> $overrides
     * @param ?\Closure(int, string): void $publishFaultHook test/dev fault injector (see StagingPublisher::publish())
     */
    private function stageAndPublish(
        ApplicationContext $ctx,
        Fingerprinter $fingerprinter,
        string $fingerprint,
        OpenApiEmitter $emitter,
        array $document,
        array $di,
        array $routes,
        array $overrides,
        ?string $primaryYaml,
        OpenApiEscapeHatch $escapeHatch,
        ?\Closure $publishFaultHook = null,
    ): CompileResult {
        $publisher = new StagingPublisher($ctx->cachePath);

        // Unique staging dir per run (never the shared `.staging-compile`); clear any crash-leftover just in case.
        $stagingDir = $ctx->cachePath . '/.staging-' . bin2hex(random_bytes(8));
        $this->cleanDir($stagingDir);

        $contents = [
            'compiled_routes.php' => (new RouteCacheEmitter())->emitSource($routes),
            'compiled_di.php' => $this->varExportSource($di['compiled']),
            'job_registry.php' => $this->varExportSource($di['jobs']),
            // PRIMARY openapi.yml — the chosen primary producer's output (Legacy swagger-php parity OR Metadata
            // graph+escape-hatch, decided in compile()) — comes BEFORE the SECONDARY openapi.generated.yml (the
            // PURE graph emitter under BOTH modes). Both are staged + published per-file atomically.
            'swagger/openapi.yml' => (string) $primaryYaml,
            'swagger/openapi.generated.yml' => $emitter->dump($document),
        ];

        $stagingMap = [];
        $artifactHashes = [];
        $artifactTargets = [];
        foreach ($contents as $relative => $source) {
            $stagingFile = $stagingDir . '/' . $relative;
            if (!is_dir(dirname($stagingFile))) {
                mkdir(dirname($stagingFile), 0777, true);
            }
            file_put_contents($stagingFile, $source);
            $target = $ctx->cachePath . '/' . $relative;
            $stagingMap[$stagingFile] = $target;
            $artifactTargets[] = $target;
            $artifactHashes[$relative] = md5($source);
        }

        // Manifest last: compiler version, fingerprint, scalar config, config-file content hashes, map hashes,
        // escape-hatch hash (single canonical representation; md5 only — no targets/absolute paths/content),
        // applied overrides, artifact hashes, built_at.
        $manifest = $fingerprinter->manifest(
            $fingerprint,
            $artifactHashes,
            $this->recordedConfig($ctx),
            $ctx->configFiles,
            $ctx->operationIdMap,
            $ctx->routeOverrideMap,
            $overrides,
            date('c'),
            $ctx->projectRoot,
            $escapeHatch,
        );
        $manifestRelative = '.compile_manifest.php';
        $manifestStaging = $stagingDir . '/' . $manifestRelative;
        file_put_contents($manifestStaging, $fingerprinter->manifestSource($manifest));
        $manifestTarget = $ctx->cachePath . '/' . $manifestRelative;
        $stagingMap[$manifestStaging] = $manifestTarget; // appended last ⇒ published last

        // Publish under the lock; clean the unique staging dir in a `finally` on BOTH success and a publication
        // failure. Recovery backups (a separate `.backup-*` dir from an incomplete rollback) are NEVER touched here.
        try {
            $publisher->publish($stagingMap, $publishFaultHook);
        } finally {
            $this->cleanDir($stagingDir);
        }

        return CompileResult::published(
            $artifactTargets,
            $manifestTarget,
            $this->diagnostics->errorCount(),
            $this->diagnostics->warningCount(),
            $fingerprint,
            $overrides,
            $stagingDir,
        );
    }

    /**
     * The config inputs recorded in BOTH the fingerprint and the manifest. Mode and diagnostic policy are NOT
     * independent strings: they come from the typed {@see ApplicationContext} fields, so the manifest ALWAYS records
     * them (their enum/scalar values) regardless of how the context was constructed — even when a caller builds an
     * ApplicationContext directly without pre-merging them into configInputs. The TYPED fields are authoritative: a
     * stray mode/policy string a caller tucked into configInputs is ignored, so the recorded values always match the
     * mode the engine and the Step 6a runtime guards actually use. Other caller-supplied configInputs (openapi
     * title/version, escape-hatch config, …) pass through untouched.
     *
     * @return array<string, mixed>
     */
    private function recordedConfig(ApplicationContext $ctx): array
    {
        return array_merge(
            $ctx->configInputs,
            [
                'mode' => $ctx->mode->value,
                'diagnostic_policy' => $ctx->diagnosticPolicy,
                'rule_source' => $ctx->ruleSource->value,
                // The PRIMARY openapi.yml producer (Step 8 / M6) — a 4th independent typed axis. Recorded here so it
                // feeds the fingerprint + manifest via ONE config source; it is NOT passed a second time to the
                // Fingerprinter (the escape hatch has its own dedicated canonical representation).
                'openapi_source' => $ctx->openApiSource->value,
            ],
        );
    }

    /**
     * @param array<string, mixed> $map
     */
    private function varExportSource(array $map): string
    {
        return "<?php\n\nreturn " . var_export($map, true) . ";\n";
    }

    /**
     * @param list<string> $discoveryPaths
     * @return list<class-string>
     */
    private function discoverDiClasses(array $discoveryPaths): array
    {
        $all = [];
        foreach ($discoveryPaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $all = array_merge($all, ClassScanner::getClassesFromDir($dir));
        }
        return array_values(array_unique($all));
    }

    /**
     * Recursively remove a directory (staging leftovers from a prior run, or after a successful publish whose
     * files were renamed out). No-op if absent.
     */
    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getRealPath()) : @unlink($entry->getRealPath());
        }
        @rmdir($dir);
    }
}
