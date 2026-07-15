<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * One declared response of an operation. Repeatable per status; backs the #[Response] attribute (Step 3).
 *
 *  - status      : HTTP status code (default 200)
 *  - schema      : inferred (DTO-eligible return type / enum / array-of-DTO) OR explicitly declared via
 *                  `#[Response(schema: Class::class)]`; null when the body is opaque / undeclared
 *  - arrayItem   : for array returns: the element FQCN / type, when resolvable (else #[Items] is required)
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
        public ?string $arrayItem = null,
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
