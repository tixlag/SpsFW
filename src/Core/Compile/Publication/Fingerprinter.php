<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Publication;

use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;

/**
 * Builds the deterministic source+config fingerprint and the publication manifest (plan §11.5, Step 5 fix-pass).
 *
 * The FINGERPRINT is a stable hash of everything that should change the build output: the compiler version, the
 * content of every discovered source file, the scalar config inputs, and the content of the compile-time config
 * files + the operationId/route-override maps. It is DETERMINISTIC — the same source + config always yields the same
 * fingerprint — because invalidation must not depend on WHEN the build ran. `built_at` is therefore deliberately
 * EXCLUDED: it is recorded in the manifest (for humans / ordering) but never feeds the fingerprint, so two builds of
 * identical source produce the same fingerprint regardless of wall-clock time.
 *
 * DEPLOY-PATH-INDEPENDENCE (Step 5 fix-pass): source files are keyed by path RELATIVE to projectRoot, so the same
 * checkout at `/home/a/proj` and `/home/b/proj` yields the same fingerprint. Compile-time config files (di_config,
 * operation-id / route-override maps, escape hatch) are hashed by CONTENT (md5) — and the manifest stores ONLY the
 * md5, never the file content, because di_config may hold secrets (the directive: do not store potential secrets
 * wholesale in the manifest). The operationId/route-override maps are likewise canonicalized-then-hashed.
 *
 * The MANIFEST is published LAST (after every artifact). It carries the compiler version, the fingerprint, the
 * scalar config inputs, the per-config-file content hashes (md5 only), the per-map hashes (md5 only), the applied
 * route overrides (structural observability — controller::method + METHOD:path, present in source, not secret), the
 * per-artifact content hashes, and `built_at`.
 */
final class Fingerprinter
{
    /** Bumped on any change to the emitted artifact shapes; part of the fingerprint so a new engine invalidates.
     *  spsfw-compile-3: the PRIMARY openapi.yml parity producer scans a dedicated, caller-supplied
     *  legacyOpenApiScanPaths (historical [src, libraryRoot] order) instead of reusing route discovery order.
     *  spsfw-compile-4 (Step 8 / M6): the PRIMARY openapi.yml PRODUCER is now switchable via OpenApiSource
     *  (Legacy swagger-php vs Metadata graph + escape hatch), and the escape hatch feeds the fingerprint via a
     *  canonical representation — a behavior change to the emitted spec set, so the fingerprint invalidates. */
    public const COMPILER_VERSION = 'spsfw-compile-4';

    /**
     * Recursively gather every .php source file under the discovery dirs (controllers, DTOs, …), deterministically
     * sorted. These are the files whose content feeds the fingerprint.
     *
     * @param list<string> $discoveryPaths
     * @return list<string> absolute paths, sorted
     */
    public function sourceFiles(array $discoveryPaths): array
    {
        $files = [];
        foreach ($discoveryPaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getRealPath();
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    /**
     * Deterministic fingerprint of the compiler version + source file contents + scalar config + config-file contents
     * + map contents. `built_at` is intentionally NOT part of the input.
     *
     * @param list<string> $sourceFiles (from {@see sourceFiles()})
     * @param array<string, mixed> $configInputs scalar inputs (mode/policy/title/version …)
     * @param string $projectRoot absolute project root — source files are keyed relative to it (deploy-path-independent)
     * @param array<string, string> $configFiles logical name => absolute path (content-hashed; never stored wholesale)
     * @param array<string, ?string> $operationIdMap tri-state lockfile (canonicalized-hashed)
     * @param array<string, string> $routeOverrideMap METHOD:path => controller::method (canonicalized-hashed)
     * @param list<string> $legacyOpenApiScanPaths directories the PRIMARY openapi.yml parity producer scans, in the
     *                                            caller's (historical) ORDER. Order matters — swagger-php output is
     *                                            order-sensitive — so the list is keyed as-given (NOT sorted), then
     *                                            normalized RELATIVE to projectRoot so the fingerprint stays
     *                                            deploy-path-independent. Only the normalized list feeds the hash; the
     *                                            absolute paths never reach the manifest (hashed into the md5 below).
     */
    public function fingerprint(
        array $sourceFiles,
        array $configInputs,
        string $projectRoot,
        array $configFiles = [],
        array $operationIdMap = [],
        array $routeOverrideMap = [],
        array $legacyOpenApiScanPaths = [],
        ?OpenApiEscapeHatch $escapeHatch = null,
    ): string {
        $sources = [];
        foreach ($sourceFiles as $path) {
            // Key by path RELATIVE to projectRoot so the fingerprint is deploy-path-independent. A missing file
            // hashes as 'missing' (rather than throwing) so the build stays deterministic and the gap surfaces as a
            // content change once the file reappears.
            $sources[$this->relativeTo($projectRoot, $path)] = is_file($path) ? md5_file($path) : 'missing';
        }
        ksort($sources);

        // The legacy OpenAPI scan ORDER affects swagger-php output, so it participates in the fingerprint. Normalize
        // each path RELATIVE to projectRoot (deploy-path-independent), preserving order — do NOT sort. The list is
        // hashed into the payload below; the manifest never carries these (absolute or relative) paths.
        $legacyScanRelative = array_map(
            fn (string $p): string => $this->relativeTo($projectRoot, $p),
            array_values($legacyOpenApiScanPaths),
        );

        $payload = json_encode([
            'compiler' => self::COMPILER_VERSION,
            'sources' => $sources,
            'config' => $this->canonicalizeConfig($configInputs),
            'config_files' => $this->configFileHashes($configFiles),
            'maps' => [
                'operation_id_map' => $this->hashMap($operationIdMap),
                'route_override_map' => $this->hashMap($routeOverrideMap),
            ],
            'legacy_openapi_scan_paths' => $legacyScanRelative,
            // The escape hatch (Step 8 / M6) participates via ONE canonical representation — also the source
            // of the manifest escape_hatch_hash. Deploy-path-stable keys only (class:<FQCN> / file:<relative>);
            // content is a separate md5. No absolute deploy paths ever reach the payload or the manifest.
            'escape_hatch' => $this->escapeHatchCanonical($escapeHatch, $projectRoot),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return md5($payload);
    }

    /**
     * Assemble the manifest array. Published as the LAST artifact so its presence signals a complete set.
     *
     * @param array<string, string> $artifactHashes relative artifact name => md5 of its staged content
     * @param array<string, mixed> $configInputs scalar config inputs
     * @param array<string, string> $configFiles logical name => absolute path (content-hashed; content NOT stored)
     * @param array<string, ?string> $operationIdMap hashed, not stored wholesale
     * @param array<string, string> $routeOverrideMap hashed, not stored wholesale
     * @param list<array{key: string, winner: string, shadowed: list<string>}> $appliedOverrides
     * @param string $builtAt
     */
    public function manifest(
        string $fingerprint,
        array $artifactHashes,
        array $configInputs,
        array $configFiles,
        array $operationIdMap,
        array $routeOverrideMap,
        array $appliedOverrides,
        string $builtAt,
        string $projectRoot = '',
        ?OpenApiEscapeHatch $escapeHatch = null,
    ): array {
        ksort($artifactHashes);
        return [
            'compiler_version' => self::COMPILER_VERSION,
            'fingerprint' => $fingerprint,
            'config_inputs' => $this->canonicalizeConfig($configInputs),
            'config_file_hashes' => $this->configFileHashes($configFiles),
            'map_hashes' => [
                'operation_id_map' => $this->hashMap($operationIdMap),
                'route_override_map' => $this->hashMap($routeOverrideMap),
            ],
            // md5 of the SAME canonical representation that feeds the fingerprint payload — single source of
            // truth (the Coordinator passes the hatch + projectRoot; it never computes its own hash). md5 ONLY:
            // no scan targets, no absolute paths, no file content in the manifest.
            'escape_hatch_hash' => $this->escapeHatchHash($escapeHatch, $projectRoot),
            'overrides_applied' => array_values($appliedOverrides),
            'artifact_hashes' => $artifactHashes,
            'built_at' => $builtAt,
        ];
    }

    /**
     * Render the manifest array as a PHP cache file (`<?php\n\nreturn …;\n`), consistent with the other caches.
     *
     * @param array<string, mixed> $manifest
     */
    public function manifestSource(array $manifest): string
    {
        return "<?php\n\nreturn " . var_export($manifest, true) . ";\n";
    }

    /**
     * The path $path expressed relative to $base, so two checkouts at different absolute roots key identically.
     * Falls back to the given path when it does not live under $base.
     */
    private function relativeTo(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }
        // Best-effort against resolved paths (symlinks) before giving up.
        $realBase = realpath($base);
        $realPath = realpath($path);
        if ($realBase !== false && $realPath !== false && str_starts_with($realPath, $realBase)) {
            return ltrim(substr($realPath, strlen($realBase)), '/');
        }
        return $path;
    }

    /**
     * Content hashes of the compile-time config files (md5 of file content; the content itself is never stored).
     *
     * @param array<string, string> $configFiles logical name => absolute path
     * @return array<string, string> logical name => md5 (or 'missing')
     */
    private function configFileHashes(array $configFiles): array
    {
        ksort($configFiles);
        $out = [];
        foreach ($configFiles as $name => $path) {
            $out[$name] = is_file($path) ? md5_file($path) : 'missing';
        }
        return $out;
    }

    /**
     * A content hash of a map (canonicalized, sorted, then md5). Only the hash is ever stored — never the map, so
     * the manifest never carries potential secret data wholesale.
     *
     * @param array<string, mixed> $map
     */
    private function hashMap(array $map): string
    {
        return md5(json_encode($this->canonicalizeConfig($map), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The md5 of the canonical escape-hatch representation — used for BOTH the fingerprint payload's
     * escape_hatch section AND the manifest's escape_hatch_hash (single source of truth). The Coordinator
     * passes the hatch + projectRoot and NEVER computes its own hash.
     */
    private function escapeHatchHash(?OpenApiEscapeHatch $hatch, string $projectRoot): string
    {
        return md5(json_encode($this->escapeHatchCanonical($hatch, $projectRoot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The canonical, DEPLOY-PATH-INDEPENDENT representation of the escape hatch. Scan targets use STABLE keys:
     * a class-string target ⇒ `class:<FQCN>`; a file target ⇒ `file:<project-relative-path>`. The resolved
     * file's CONTENT is a separate md5 (so a content change invalidates without leaking the path). A file that
     * resolves OUTSIDE projectRoot is represented as `file:out-of-root` (no absolute path is ever emitted) — the
     * merger already rejects such a target with a FATAL, so this representation is leak-free even pre-block.
     * Schema keys are already deduped+sorted by the VO. null ⇒ an empty hatch canonical form.
     *
     * @return array<string, mixed>
     */
    private function escapeHatchCanonical(?OpenApiEscapeHatch $hatch, string $projectRoot): array
    {
        $hatch ??= OpenApiEscapeHatch::empty();

        $targets = [];
        foreach ($hatch->scanTargets as $target) {
            $file = OpenApiEscapeHatch::resolveScanFile($target, $projectRoot);
            $content = ($file !== null && is_file($file)) ? md5_file($file) : 'missing';
            if (class_exists($target)) {
                // A class-string target: stable by FQCN. The KEY (`class:<FQCN>`) is the deploy-path-stable identity;
                // the file's CONTENT (md5) drives invalidation. The file PATH is deliberately NOT recorded here: a class
                // file may live outside projectRoot (e.g. a framework class scanned by the app), in which case
                // relativeTo() would fall back to an absolute path and break deploy-path-independence. FQCN + content
                // are sufficient and never leak a deploy path.
                $targets['class:' . $target] = [
                    'kind' => 'class',
                    'fqcn' => $target,
                    'content' => $content,
                ];
                continue;
            }
            // A file target: stable by project-relative path (or the out-of-root sentinel — never absolute).
            $relative = $file !== null ? $this->relativeTo($projectRoot, $file) : 'missing';
            $leakSafe = ($relative === 'missing' || !str_starts_with($relative, '/')) ? $relative : 'out-of-root';
            $targets['file:' . $leakSafe] = [
                'kind' => 'file',
                'path' => $leakSafe,
                'content' => $content,
            ];
        }
        ksort($targets);

        return [
            'scan_targets' => $targets,
            'schema_keys' => $hatch->schemaKeys,
        ];
    }

    /**
     * Canonicalize a structure into a deterministic form (sorted keys recursively).
     *
     * @param array<string, mixed> $configInputs
     * @return array<string, mixed>
     */
    private function canonicalizeConfig(array $configInputs): array
    {
        ksort($configInputs);
        $out = [];
        foreach ($configInputs as $key => $value) {
            $out[$key] = is_array($value) ? $this->canonicalizeConfig($value) : $value;
        }
        return $out;
    }
}
