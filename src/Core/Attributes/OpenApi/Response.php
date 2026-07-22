<?php

declare(strict_types=1);

namespace SpsFW\Core\Attributes\OpenApi;

use Attribute;

/**
 * Declares one HTTP response of an operation (plan §8). Repeatable: declare one per status.
 *
 * Required whenever the success return type is NOT auto-derivable (plan §7): an opaque framework
 * {@see \SpsFW\Core\Http\Response}, a bare `array` without an item type, a non-eligible class (domain entity
 * that is not a `*Dto`), a union, or a non-200 success with headers. When the return IS derivable (a `*Dto`,
 * an enum, or an array-of-`*Dto` with {@see Items}), the compiler infers the 200 success response and a success
 * `#[Response]` is optional. A 2xx `#[Response]` is the SUCCESS response (it supplies the success body/status,
 * so no success is then auto-inferred); a non-2xx `#[Response]` SUPPLEMENTS the success alongside the standard
 * errors rather than replacing them. Declare the success response explicitly when the body is not auto-derivable.
 *
 * `schema` is a class-string projected through DtoSchemaBuilder; it may name a `*Dto`, a backed enum, or an
 * entity whose JSON shape is otherwise described by its properties.
 *
 * `collection` (default false) marks the response body as an ARRAY of `schema` items — disambiguating an
 * opaque collection (`#[Response(schema: ItemDto::class, collection: true)]`) from a single object
 * (`#[Response(schema: ItemDto::class)]`). When true the response projects `type: array, items: {schema}`;
 * without it a `#[Response(schema: …)]` is a single object. This removes the array/opaque ambiguity that
 * previously lost the item shape on array responses (plan §6/§7).
 *
 * Doc-only. Named `Response` to mirror swagger-php's `#[OA\Response]`; alias on import if a controller also
 * uses {@see \SpsFW\Core\Http\Response}: `use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;`.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    /**
     * @param ?class-string $schema FQCN projected to a schema; null = opaque/empty body
     * @param array<string, mixed> $headers declared response headers
     */
    public function __construct(
        public ?string $schema = null,
        public int $status = 200,
        public ?string $description = null,
        public string $contentType = 'application/json',
        public array $headers = [],
        public bool $collection = false,
    ) {
    }
}
