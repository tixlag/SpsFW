<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * Outcome of {@see Coordinator::compile()}.
 *
 * `success` means the compilation COMPLETED (no fatal crash) — it does NOT mean artifacts were published.
 * `published` is the separate signal that the artifact set was actually written to the production cache: it is
 * false for a dry run, and whenever the diagnostic policy forbids publication (errors always; warnings too under
 * strict). `reason` explains a non-publication. The published artifact paths and the manifest path are populated
 * only when `published` is true.
 */
final readonly class CompileResult
{
    /** @var list<string> */
    public array $artifacts;

    /** @var list<array{key: string, winner: string, shadowed: list<string>}> */
    public array $overrides;

    /**
     * @param list<string> $artifacts absolute paths of published artifacts (empty unless published)
     * @param list<array{key: string, winner: string, shadowed: list<string>}> $overrides route overrides applied
     *        during endpoint-set resolution (winner + shadowed are "controller::method"); populated even for a
     *        dry run / blocked build so the override resolution is observable without publishing.
     * @param ?string $stagingDir the unique-per-run staging directory used (already cleaned up by the time this is
     *        returned). Observable so tooling/logs can name it and so tests can prove two runs never share one.
     */
    public function __construct(
        public bool $success,
        array $artifacts = [],
        public ?string $manifestPath = null,
        public bool $published = false,
        public bool $dryRun = false,
        public int $errorCount = 0,
        public int $warningCount = 0,
        public ?string $fingerprint = null,
        public ?string $reason = null,
        array $overrides = [],
        public ?string $stagingDir = null,
    ) {
        $this->artifacts = array_values($artifacts);
        $this->overrides = array_values($overrides);
    }

    /** BC skeleton: an empty success that publishes nothing. */
    public static function emptySuccess(): self
    {
        return new self(success: true);
    }

    /**
     * A completed compile that did NOT publish (dry run, or blocked by diagnostics).
     *
     * @param list<string> $artifacts always [] here
     */
    public static function notPublished(
        bool $dryRun,
        int $errorCount,
        int $warningCount,
        ?string $fingerprint,
        ?string $reason,
        array $overrides = [],
    ): self {
        return new self(
            success: true,
            artifacts: [],
            manifestPath: null,
            published: false,
            dryRun: $dryRun,
            errorCount: $errorCount,
            warningCount: $warningCount,
            fingerprint: $fingerprint,
            reason: $reason,
            overrides: $overrides,
        );
    }

    /**
     * A completed compile whose artifact set WAS published.
     *
     * @param list<string> $artifacts absolute paths of published artifacts (excludes the manifest)
     * @param list<array{key: string, winner: string, shadowed: list<string>}> $overrides
     */
    public static function published(
        array $artifacts,
        string $manifestPath,
        int $errorCount,
        int $warningCount,
        string $fingerprint,
        array $overrides = [],
        ?string $stagingDir = null,
    ): self {
        return new self(
            success: true,
            artifacts: $artifacts,
            manifestPath: $manifestPath,
            published: true,
            dryRun: false,
            errorCount: $errorCount,
            warningCount: $warningCount,
            fingerprint: $fingerprint,
            reason: null,
            overrides: $overrides,
            stagingDir: $stagingDir,
        );
    }
}
