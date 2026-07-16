<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\OpenApi\OpenApiValidator;
use SpsFW\Core\Compile\Publication\Fingerprinter;
use SpsFW\Core\Compile\Publication\StagingPublisher;
use SpsFW\Core\Compile\Route\RouteCacheEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
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
     * @param bool $dryRun build + validate + report, but never publish (read-only probe)
     */
    public function compile(bool $dryRun = false): CompileResult
    {
        $ctx = $this->context;
        $publisher = new StagingPublisher($ctx->cachePath);
        $fingerprinter = new Fingerprinter();

        // ---- ONE shared diagnostics collector: route compiler, OpenAPI emitter + validator, DI all report here.
        $routeCompiler = new RouteMetadataCompiler($this->diagnostics);

        // Route cache IR (runtime truth) + OpenAPI operation projection — same reflection pass, two outputs.
        $routes = $routeCompiler->compile($ctx->discoveryPaths);
        $operations = $routeCompiler->compileAllOperations($ctx->discoveryPaths);

        $title = is_string($ctx->configInputs['openapi_title'] ?? null) ? $ctx->configInputs['openapi_title'] : 'SpsFW API';
        $version = is_string($ctx->configInputs['openapi_version'] ?? null) ? $ctx->configInputs['openapi_version'] : '0.1.0';
        $emitter = new OpenApiEmitter($this->diagnostics);
        $document = $emitter->emit($operations, title: $title, version: $version);
        (new OpenApiValidator($this->diagnostics))->validate($document);

        // DI map + job registry via the compile-only API — no production cache write, no setCompiledMap().
        $diClasses = $this->discoverDiClasses($ctx->discoveryPaths);
        $di = (new DICacheBuilder(null, ''))->compileOnly($diClasses, $this->diagnostics);

        // Deterministic source+config fingerprint (built_at deliberately excluded).
        $sourceFiles = $fingerprinter->sourceFiles($ctx->discoveryPaths);
        $fingerprint = $fingerprinter->fingerprint($sourceFiles, $ctx->configInputs);

        // ---- Publication gate: ERROR always blocks; a WARNING blocks only under the strict policy.
        $errors = $this->diagnostics->hasErrors();
        $warnings = $this->diagnostics->hasWarnings();
        $publishable = !$errors && !($ctx->warningsBlock() && $warnings);

        if ($dryRun) {
            return CompileResult::notPublished(true, $this->diagnostics->errorCount(), $this->diagnostics->warningCount(), $fingerprint, 'dry-run');
        }
        if (!$publishable) {
            $reason = $errors ? 'errors' : 'strict-warnings';
            return CompileResult::notPublished(false, $this->diagnostics->errorCount(), $this->diagnostics->warningCount(), $fingerprint, $reason);
        }

        // ---- Stage every artifact on the same FS, compute content hashes, build the manifest, then publish
        //      artifacts first and the manifest LAST (its presence signals a complete, fingerprint-matching set).
        $stagingDir = $ctx->cachePath . '/.staging-compile';
        $this->cleanDir($stagingDir);

        $contents = [
            'compiled_routes.php' => (new RouteCacheEmitter())->emitSource($routes),
            'compiled_di.php' => $this->varExportSource($di['compiled']),
            'job_registry.php' => $this->varExportSource($di['jobs']),
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

        // Manifest last: compiler version, fingerprint, config inputs, artifact hashes, built_at.
        $manifest = $fingerprinter->manifest($fingerprint, $artifactHashes, $ctx->configInputs, date('c'));
        $manifestRelative = '.compile_manifest.php';
        $manifestStaging = $stagingDir . '/' . $manifestRelative;
        file_put_contents($manifestStaging, $fingerprinter->manifestSource($manifest));
        $manifestTarget = $ctx->cachePath . '/' . $manifestRelative;
        $stagingMap[$manifestStaging] = $manifestTarget; // appended last ⇒ published last

        $publisher->publish($stagingMap);

        $this->cleanDir($stagingDir);

        return CompileResult::published(
            $artifactTargets,
            $manifestTarget,
            $this->diagnostics->errorCount(),
            $this->diagnostics->warningCount(),
            $fingerprint,
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
