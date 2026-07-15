<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * OpenAPI requestBody: the operation's bound DTO (#[JsonBody] / #[PostBody] / #[FormDataBody]) projected
 * to a schema ref, plus the content type. The same DTO feeds the ValidationRuleGraph for that operation
 * (plan §6 — one schema, projected by direction).
 *
 *  - JsonBody / PostBody => 'application/json'
 *  - FormDataBody        => 'multipart/form-data'
 */
final readonly class RequestBodyMetadata
{
    public function __construct(
        public ?SchemaMetadata $schema = null,
        public string $contentType = 'application/json',
        public bool $required = true,
        public ?string $description = null,
    ) {
    }

    public function isMultipart(): bool
    {
        return $this->contentType === 'multipart/form-data';
    }
}
