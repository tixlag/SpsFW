<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * One declared response of an operation. Repeatable per status; backs the #[Response] attribute (Step 3).
 *
 *  - status      : HTTP status code (default 200)
 *  - schema      : the response body schema — an inferred DTO/enum/scalar/DateTime, or explicitly declared via
 *                  `#[Response(schema: Class::class)]`; null when the body is opaque / undeclared. For a
 *                  COLLECTION response (`#[Response(collection: true)]`) the item schema lives in `arrayItem`
 *                  and `schema` is null — the emitter renders `type: array, items: …`.
 *  - arrayItem   : for collection responses: the element SchemaMetadata (the per-item shape), set when the
 *                  response is an array (#[Response(collection: true)] or an inferred array-of-DTO).
 *  - contentType : default application/json
 *  - headers     : declared response headers
 */
final readonly class ResponseMetadata
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public int $status = 200,
        public ?SchemaMetadata $schema = null,
        public ?SchemaMetadata $arrayItem = null,
        public string $contentType = 'application/json',
        public string $description = '',
        public array $headers = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
