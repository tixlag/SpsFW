<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Introspection\TypeMapper;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 1 (M1): pins TypeMapper — the single PHP-type → OpenAPI-fragment mapping. Covers scalars, backed
 * (string/int) and unit enums, the *Uuid class-name heuristic, DateTimeInterface (and a subclass) →
 * date-time, plain-class → ref, arrays, nullability, genuine unions, and the format override.
 */
enum TmColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}
enum TmPriority: int
{
    case Low = 1;
    case High = 2;
}
enum TmPlain
{
    case Foo;
    case Bar;
}

final class TmUuid
{
}
final class TmDto
{
}
class TmMoment extends DateTime
{
}

class TmHolder
{
    public int $age;
    public string $name;
    public bool $active;
    public float $score;
    public ?string $nickname;
    public TmColor $color;
    public TmPriority $priority;
    public TmPlain $plain;
    public TmUuid $id;
    public DateTimeInterface $at;
    public TmMoment $moment;
    public TmDto $dto;
    public array $items;
    public int|string $union;            // genuine union (2 non-null members) -> unsupported
    public ?TmDto $maybeDto;             // T|null nullable union -> supported, ref, nullable
    public \Countable&\IteratorAggregate $inter; // intersection -> unsupported
}

$mapper = new TypeMapper();

$property = static function (string $name): ?ReflectionType {
    return (new ReflectionProperty(TmHolder::class, $name))->getType();
};
$map = static fn (string $name, ?string $formatOverride = null): array => $mapper->map($property($name), $formatOverride);

// scalars
assert_same('integer', $mapper->mapScalar('int'), 'int -> integer');
assert_same('string', $mapper->mapScalar('string'), 'string -> string');
assert_same('boolean', $mapper->mapScalar('bool'), 'bool -> boolean');
assert_same('number', $mapper->mapScalar('float'), 'float -> number');
assert_same(null, $mapper->mapScalar('unknown'), 'unknown scalar -> null');

assert_same('integer', $map('age')['type'], 'int property -> integer');
assert_same('string', $map('name')['type'], 'string property -> string');
assert_same('boolean', $map('active')['type'], 'bool property -> boolean');
assert_same('number', $map('score')['type'], 'float property -> number');
assert_true(!$map('age')['nullable'], 'non-nullable property is not nullable');

// nullable shorthand ?T is the only union that maps cleanly (T|null collapses to T nullable)
$nickname = $map('nickname');
assert_same('string', $nickname['type'], '?string collapses to string');
assert_true($nickname['nullable'], '?string is nullable');
assert_true(!$mapper->isUnsupported($nickname), '?string is supported');

// backed string enum
$color = $map('color');
assert_same('string', $color['type'], 'backed string enum -> string');
assert_same(['red', 'blue'], $color['enum'], 'backed string enum -> backing values');

// backed int enum
$priority = $map('priority');
assert_same('integer', $priority['type'], 'backed int enum -> integer');
assert_same([1, 2], $priority['enum'], 'backed int enum -> backing values');

// unit enum
$plain = $map('plain');
assert_same('string', $plain['type'], 'unit enum -> string');
assert_same(['Foo', 'Bar'], $plain['enum'], 'unit enum -> case names');

// *Uuid class heuristic
$uuid = $map('id');
assert_same('string', $uuid['type'], '*Uuid class -> string');
assert_same('uuid', $uuid['format'], '*Uuid class -> uuid format');
assert_same(null, $uuid['ref'], '*Uuid class is not a ref');

// DateTimeInterface and a subclass -> date-time
assert_same('string', $map('at')['type'], 'DateTimeInterface -> string');
assert_same('date-time', $map('at')['format'], 'DateTimeInterface -> date-time format');
assert_same('date-time', $map('moment')['format'], 'DateTime subclass -> date-time format');

// format override wins
assert_same('date', $map('at', 'date')['format'], 'explicit format override wins over date-time');

// plain class -> ref, no type
$dto = $map('dto');
assert_same(null, $dto['type'], 'plain class has no OpenAPI type');
assert_same('TmDto', $dto['ref'], 'plain class -> ref (FQCN)');
assert_true(!$dto['nullable'], 'plain class is not nullable');

// array without item type
assert_same('array', $map('items')['type'], 'array -> array type');
assert_same(null, $map('items')['items'], 'item resolution is deferred (Step 2/3)');

// nullable class union ?T|null -> ref, nullable, supported (same T|null rule)
$maybeDto = $map('maybeDto');
assert_same('TmDto', $maybeDto['ref'], '?TmDto collapses to ref TmDto');
assert_true($maybeDto['nullable'], '?TmDto is nullable');
assert_true(!$mapper->isUnsupported($maybeDto), '?TmDto is supported');

// genuine union (two non-null members) is NOT auto-derived -> explicit unsupported result
$union = $map('union');
assert_true($mapper->isUnsupported($union), 'int|string genuine union is unsupported');
assert_true(str_contains((string) $union['reason'], 'union'), 'unsupported union carries a reason');

// intersection is NOT auto-derived -> explicit unsupported result
$inter = $map('inter');
assert_true($mapper->isUnsupported($inter), 'intersection type is unsupported');
assert_true(str_contains((string) $inter['reason'], 'intersection'), 'unsupported intersection carries a reason');

// null / absent type
assert_true($mapper->map(null)['nullable'], 'null type maps to nullable');
assert_same(null, $mapper->map(null)['type'], 'null type has no OpenAPI type');

// mapClass direct entry points
assert_same('date-time', $mapper->mapClass('DateTimeImmutable')['format'], 'mapClass DateTimeImmutable -> date-time');
assert_same('uuid', $mapper->mapClass(TmUuid::class)['format'], 'mapClass *Uuid -> uuid');

echo "TypeMapper passed\n";
