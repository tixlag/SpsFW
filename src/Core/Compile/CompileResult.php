<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * Outcome of {@see Coordinator::compile()}.
 *
 * Step 1 (M1): compile() is a no-op shell returning {@see CompileResult::emptySuccess()} with no
 * artifacts. From Step 3+ the artifact list is populated with the absolute paths of published files
 * (compiled_routes.php, compiled_di.php, job_registry.php, openapi.yml) and the manifest path.
 */
final readonly class CompileResult
{
    /**
     * @param list<string> $artifacts absolute paths of published artifacts
     */
    public function __construct(
        public bool $success,
        public array $artifacts = [],
        public ?string $manifestPath = null,
    ) {
    }

    public static function emptySuccess(): self
    {
        return new self(success: true, artifacts: []);
    }
}
