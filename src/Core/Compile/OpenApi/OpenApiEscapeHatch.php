<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

/**
 * The narrow OA escape-hatch input (plan §13, Step 8 / M6) — a FIRST-CLASS {@see \SpsFW\Core\Compile\ApplicationContext}
 * compile input, NOT a hidden configInputs entry.
 *
 * Under {@see \SpsFW\Core\Compile\OpenApiSource::Metadata} the PRIMARY OpenAPI document is the metadata graph
 * MERGED with the component schemas this hatch extracts from an EXPLICIT swagger-php whitelist. The hatch is
 * deliberately narrow: only `components.schemas.*` may be contributed (no paths, operations, parameters,
 * requestBody, security, responses — those are enforced by {@see OpenApiEscapeHatchMerger}). It exists so a
 * genuinely underivable schema (polymorphism oneOf/anyOf/allOf + discriminator, exotic formats) can be declared
 * with real swagger-php `#[OA\Schema]` attributes on an explicit carrier class/file, without re-introducing OA as
 * a source of truth for routing/security/request-validation.
 *
 * The two lists are CANONICAL at construction: every entry must be a non-empty string (else an explicit
 * {@see \InvalidArgumentException}), duplicates are removed, and the lists are sorted — so the SAME hatch always
 * serializes identically. This canonical form is what feeds the fingerprint (via the Fingerprinter's single
 * canonical representation) and the scan, with no ambiguity from ordering or duplication.
 *
 *  - scanTargets : class-string FQCNs and/or file paths swagger-php scans (the whitelist — NOT all of src).
 *                  Resolved to concrete files by {@see resolveScanFile()} (shared with the Fingerprinter so
 *                  resolution lives in ONE place).
 *  - schemaKeys  : the explicit component schema keys to extract from the partial scan and merge into the graph.
 *
 * The application loads `config/openapi_escape_hatch.php` and constructs this VO (the framework never reads the
 * app config autonomously). The config FILE path is recorded in `configFiles` (content-hashed, md5-only in the
 * manifest); the RESOLVED hatch feeds the fingerprint via the Fingerprinter's canonical representation (no
 * absolute deploy paths, no file content in the manifest).
 */
final readonly class OpenApiEscapeHatch
{
    /** @var list<string> class-string FQCNs + file paths (validated, deduped, sorted) */
    public array $scanTargets;

    /** @var list<string> requested component schema keys (validated, deduped, sorted) */
    public array $schemaKeys;

    /**
     * @param array<int|string, mixed> $scanTargets
     * @param array<int|string, mixed> $schemaKeys
     */
    public function __construct(array $scanTargets = [], array $schemaKeys = [])
    {
        $this->scanTargets = self::canonicalize($scanTargets, 'scan target');
        $this->schemaKeys = self::canonicalize($schemaKeys, 'schema key');
    }

    /**
     * No scan targets AND no requested schema keys → the hatch contributes nothing. This is the common case
     * (N ships an empty hatch); the merger short-circuits to a no-op.
     */
    public function isEmpty(): bool
    {
        return $this->scanTargets === [] && $this->schemaKeys === [];
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Resolve a scan target to the concrete file swagger-php must scan, or null if it is neither an
     * autoloadable class nor an existing file. Shared by the merger (to scan) and the Fingerprinter (to hash)
     * so the class-string → file mapping lives in exactly one place.
     *
     * A class-string FQCN resolves via reflection's getFileName(); a path resolves relative to $projectRoot
     * when not absolute (an app config `__DIR__/openapi/fragments.php` is already absolute). A file OUTSIDE
     * $projectRoot is returned as-is here (the caller — the merger — forbids out-of-root files explicitly).
     */
    public static function resolveScanFile(string $target, string $projectRoot): ?string
    {
        if ($target === '') {
            return null;
        }
        // A class-string FQCN → its declaring file.
        if (class_exists($target)) {
            try {
                $file = (new \ReflectionClass($target))->getFileName();
            } catch (\Throwable) {
                return null;
            }
            return $file === false || $file === '' ? null : $file;
        }
        // A raw path → absolute (resolve relative against projectRoot), confirmed to exist.
        $resolved = $target;
        if (!str_starts_with($resolved, '/') && $projectRoot !== '') {
            $resolved = rtrim($projectRoot, '/') . '/' . ltrim($resolved, '/');
        }
        if (!is_file($resolved)) {
            return null;
        }
        $real = realpath($resolved);

        return $real !== false ? $real : $resolved;
    }

    /**
     * Validate (non-empty strings only), deduplicate, and sort — the deterministic canonical form. A non-string
     * or empty entry is a deployment/config error (an explicit exception), never silently dropped.
     *
     * @param array<int|string, mixed> $values
     * @return list<string>
     */
    private static function canonicalize(array $values, string $label): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException(sprintf(
                    'OpenApiEscapeHatch %s entries must be non-empty strings; got %s.',
                    $label,
                    var_export($value, true),
                ));
            }
            $out[$value] = $value; // dedupe by value (last write is identical anyway)
        }
        sort($out); // deterministic
        return array_values($out);
    }
}
