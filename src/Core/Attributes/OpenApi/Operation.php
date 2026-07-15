<?php

declare(strict_types=1);

namespace SpsFW\Core\Attributes\OpenApi;

use Attribute;

/**
 * Compile-only OpenAPI override for a controller action (plan §8): the operationId, tags, summary,
 * description, deprecation and exclusion of an operation that CANNOT be derived from the signature.
 *
 * Resolution priority (OperationIdResolver, plan §7/§19): an explicit `id` here wins outright over the
 * lockfile and the `<ControllerShort><Method>` convention. `exclude: true` drops the operation from the
 * spec entirely (use for routes that must stay reachable at runtime but undocumented).
 *
 * Doc-only — never read at runtime. Lives under {@see \SpsFW\Core\Attributes\OpenApi} so callers may import
 * the whole namespace aliased (`use SpsFW\Core\Attributes\OpenApi as OA;` then `#[OA\Operation]`), mirroring
 * the legacy `OpenApi\Attributes as OA` drop-in.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Operation
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public ?string $id = null,
        public array $tags = [],
        public ?string $summary = null,
        public ?string $description = null,
        public bool $deprecated = false,
        public bool $exclude = false,
    ) {
    }
}
