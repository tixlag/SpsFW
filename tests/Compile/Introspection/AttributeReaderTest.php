<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\Introspection\AttributeReader;
use SpsFW\Core\Http\HttpMethod;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 1 (M1): pins AttributeReader — the single reflection-attribute read path the builders will share.
 * Covers class/method reads, the framework #[Route], repeatable attributes, first-or-null, presence check.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class ArMarker
{
    public function __construct(public ?string $tag = null)
    {
    }
}

#[ArMarker(tag: 'cls')]
class ArTarget
{
    #[Route(path: '/items/{id}', httpMethods: [HttpMethod::GET])]
    #[ArMarker(tag: 'm1')]
    #[ArMarker(tag: 'm2')]
    public function show(int $id): void
    {
    }

    public function untouched(): void
    {
    }
}

$reader = new AttributeReader();
$class = new ReflectionClass(ArTarget::class);
$show = new ReflectionMethod(ArTarget::class, 'show');
$untouched = new ReflectionMethod(ArTarget::class, 'untouched');

// class-level single attribute
$classMarker = $reader->firstInstance($class, ArMarker::class);
assert_true($classMarker instanceof ArMarker, 'class-level marker read');
assert_same('cls', $classMarker->tag, 'class-level marker tag preserved');

// method-level framework #[Route] traverses the reader unchanged
$route = $reader->firstInstance($show, Route::class);
assert_true($route instanceof Route, 'method-level #[Route] read');
assert_same('/items/{id}', $route->getPath(), 'route path preserved through the reader');
assert_same([HttpMethod::GET], $route->getHttpMethods(), 'route http methods preserved through the reader');

// repeatable attribute -> all instances returned in declaration order
$markers = $reader->getInstances($show, ArMarker::class);
assert_same(2, count($markers), 'repeatable markers both returned');
assert_same(['m1', 'm2'], array_map(fn (ArMarker $m) => $m->tag, $markers), 'repeatable markers preserve order');

// firstInstance returns null when the attribute is absent
assert_same(null, $reader->firstInstance($untouched, Route::class), 'absent attribute -> null');

// has() detects presence without instantiating
assert_true($reader->has($show, Route::class), 'has() true when the attribute is present');
assert_true(!$reader->has($untouched, ArMarker::class), 'has() false when the attribute is absent');

echo "AttributeReader passed\n";
