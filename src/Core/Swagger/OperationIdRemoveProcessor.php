<?php
declare(strict_types=1);


namespace SpsFW\Core\Swagger;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Generator;

/**
 * Избавляемся от operationId в openapi.yml, чтобы Orval генерировал названия хуков по endpoints path
 */
class OperationIdRemoveProcessor
{
    protected bool $hash;

    public function __construct(bool $hash = true)
    {
        $this->hash = $hash;
    }

    public function isHash(): bool
    {
        return $this->hash;
    }

    /**
     *  If set to <code>true</code> generate ids (md5) instead of clear text operation ids.
     */
    public function setHash(bool $hash): OperationId
    {
        $this->hash = $hash;

        return $this;
    }

    public function __invoke(Analysis $analysis): void
    {
        $allOperations = $analysis->getAnnotationsOfType(OA\Operation::class);

        /** @var OA\Operation $operation */
        foreach ($allOperations as $operation) {
            $operation->operationId = Generator::UNDEFINED;

        }
    }
}
