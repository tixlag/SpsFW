<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Publication;

/**
 * Builds the deterministic source+config fingerprint and the publication manifest (plan §11.5).
 *
 * The FINGERPRINT is a stable hash of everything that should change the build output: the compiler version, the
 * content of every discovered source file, and the config inputs. It is DETERMINISTIC — the same source + config
 * always yields the same fingerprint — because invalidation must not depend on WHEN the build ran. `built_at` is
 * therefore deliberately EXCLUDED: it is recorded in the manifest (for humans / ordering) but never feeds the
 * fingerprint, so two builds of identical source produce the same fingerprint regardless of wall-clock time.
 *
 * The MANIFEST is published LAST (after every artifact). It carries the compiler version, the fingerprint, the
 * config inputs, the per-artifact content hashes, and `built_at` — so a reader that finds a valid manifest knows a
 * complete, fingerprint-matching artifact set is already in place.
 */
final class Fingerprinter
{
    /** Bumped on any change to the emitted artifact shapes; part of the fingerprint so a new engine invalidates. */
    public const COMPILER_VERSION = 'spsfw-compile-1';

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
     * Deterministic fingerprint of the compiler version + source file contents + config inputs.
     * `built_at` is intentionally NOT part of the input.
     *
     * @param list<string> $sourceFiles  (from {@see sourceFiles()})
     * @param array<string, mixed> $configInputs
     */
    public function fingerprint(array $sourceFiles, array $configInputs): string
    {
        $sources = [];
        foreach ($sourceFiles as $path) {
            // A missing file hashes as 'missing' (rather than throwing) so the build stays deterministic and the
            // gap surfaces as a content change once the file reappears.
            $sources[$path] = is_file($path) ? md5_file($path) : 'missing';
        }
        ksort($sources);

        // A stable canonicalization of the config inputs: sorted keys, scalar-friendly. json_encode with sorted
        // keys makes the hash independent of the caller's key order.
        $payload = json_encode([
            'compiler' => self::COMPILER_VERSION,
            'sources' => $sources,
            'config' => $this->canonicalizeConfig($configInputs),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return md5($payload);
    }

    /**
     * Assemble the manifest array. Published as the LAST artifact so its presence signals a complete set.
     *
     * @param array<string, string> $artifactHashes  relative artifact name => md5 of its staged content
     * @param array<string, mixed> $configInputs
     */
    public function manifest(string $fingerprint, array $artifactHashes, array $configInputs, string $builtAt): array
    {
        ksort($artifactHashes);
        return [
            'compiler_version' => self::COMPILER_VERSION,
            'fingerprint' => $fingerprint,
            'config_inputs' => $this->canonicalizeConfig($configInputs),
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
     * Canonicalize config inputs into a deterministic structure (sorted keys recursively).
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
