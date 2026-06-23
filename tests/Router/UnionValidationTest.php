<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

final readonly class UnionValidationDto
{
    public function __construct(
        #[OA\Property(property: 'amount', type: 'number', nullable: true)]
        public int|float|string|null $amount = null,
    ) {
    }
}

$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$extract = new ReflectionMethod(Router::class, 'extractValidationRules');
$rules = $extract->invoke($router, UnionValidationDto::class);

assert_same('number', $rules['amount']['type'] ?? null, 'builtin union DTO fields keep OpenAPI validation rules');

echo "Union validation contract passed\n";
