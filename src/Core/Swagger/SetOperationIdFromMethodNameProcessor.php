<?php

namespace SpsFW\Core\Swagger;

use OpenApi\Analysis;
use OpenApi\Annotations\Operation;

class SetOperationIdFromMethodNameProcessor
{
    public function __invoke(Analysis $analysis)
    {
        /** @var Operation $operation */
        foreach ($analysis->getAnnotationsOfType(Operation::class) as $operation) {
            if ($operation->operationId === null && $operation->_context->method) {
                $methodName = $operation->_context->method;

                $operation->operationId = $methodName;
            }
        }
    }
}