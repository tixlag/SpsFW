<?php

declare(strict_types=1);

namespace SpsFW\Core\Attributes\OpenApi;

use Attribute;

/**
 * Declares an operation's request body — the lossless carry-over of the legacy inline `#[OA\RequestBody]` /
 * `#[OA\Parameter(in: body)]` markup for bodies the bound-DTO markers ({@see \SpsFW\Core\Attributes\Validation\JsonBody},
 * {@see \SpsFW\Core\Attributes\Validation\PostBody}, {@see \SpsFW\Core\Attributes\Validation\FormDataBody}) do
 * NOT describe:
 *  - a raw JSON body with NO DTO parameter;
 *  - a multipart upload, or a body whose content type / required-ness / schema the signature cannot express.
 *
 * The body schema is given either as a DTO `schema` class-string (projected through DtoSchemaBuilder) or as an
 * inline `shape` (the same propertyName => facet form as {@see Response::shape}). `contentType` defaults to
 * `application/json`; use `multipart/form-data` or `application/x-www-form-urlencoded` for the other markers.
 *
 * When present, this attribute is CANONICAL — it overrides any body inferred from a DTO marker for that method.
 * Doc-only — the runtime Router never reads it. Maps into the existing
 * {@see \SpsFW\Core\Compile\Metadata\RequestBodyMetadata}.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequestBody
{
    /**
     * @param ?class-string $schema FQCN projected to the body schema
     * @param ?array<string, array<string, mixed>> $shape inline object body (propertyName => facet)
     */
    public function __construct(
        public bool $required = false,
        public string $contentType = 'application/json',
        public ?string $schema = null,
        public ?string $description = null,
        public ?array $shape = null,
    ) {
    }
}
