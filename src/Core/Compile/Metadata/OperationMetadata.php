<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * OpenAPI projection of a controller action. Derived from the same reflection pass as
 * {@see RouteRuntimeMetadata} but serving documentation (path / method / operationId / params / body /
 * responses / security / tags), never runtime. OperationId is stable: legacy ids are preserved; new ones
 * follow `<ControllerShort><Method>` with compile-time uniqueness (OperationIdResolver, Step 2).
 */
final readonly class OperationMetadata
{
    /**
     * @param list<ParameterMetadata> $pathParams
     * @param list<ParameterMetadata> $queryParams
     * @param list<ResponseMetadata> $responses
     * @param list<string> $tags
     */
    public function __construct(
        public string $httpMethod,
        public string $path,
        public ?string $operationId = null,
        public array $pathParams = [],
        public array $queryParams = [],
        public ?RequestBodyMetadata $requestBody = null,
        public array $responses = [],
        public ?SecurityMetadata $security = null,
        public array $tags = [],
        public ?string $summary = null,
        public ?string $description = null,
        public bool $deprecated = false,
        public bool $exclude = false,
        public ?string $controller = null,
        public ?string $method = null,
    ) {
    }

    public function hasOperationId(): bool
    {
        return $this->operationId !== null && $this->operationId !== '';
    }
}
