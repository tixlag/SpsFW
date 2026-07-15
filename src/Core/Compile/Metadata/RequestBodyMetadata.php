<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * OpenAPI requestBody: the operation's bound DTO projected to a schema ref, plus the content type.
 * The same DTO feeds the ValidationRuleGraph for that operation (plan §6 — one schema, projected by
 * direction). The content type follows the request-body marker attribute on the controller parameter:
 *
 *  - #[JsonBody]     => application/json                 (ParamsIn::Json,  Request::getJsonData())
 *  - #[PostBody]     => application/x-www-form-urlencoded (ParamsIn::Post,  Request::getPost() / $_POST)
 *  - #[FormDataBody] => multipart/form-data               (file uploads)
 */
final readonly class RequestBodyMetadata
{
    public const CT_JSON = 'application/json';
    public const CT_FORM_URL = 'application/x-www-form-urlencoded';
    public const CT_MULTIPART = 'multipart/form-data';

    public function __construct(
        public ?SchemaMetadata $schema = null,
        public string $contentType = self::CT_JSON,
        public bool $required = true,
        public ?string $description = null,
    ) {
    }

    public function isJson(): bool
    {
        return $this->contentType === self::CT_JSON;
    }

    public function isFormUrlEncoded(): bool
    {
        return $this->contentType === self::CT_FORM_URL;
    }

    public function isMultipart(): bool
    {
        return $this->contentType === self::CT_MULTIPART;
    }
}
