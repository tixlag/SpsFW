<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use BackedEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Maps PHP types to OpenAPI schema fragments.
 *
 * Single source of truth for "PHP type => {openapi type, format, ref, enum, nullable}", consumed by both
 * the schema projection and the rule graph. Deterministic rules (plan §7):
 *  - scalars          : int→integer, string→string, bool→boolean, float/double→number, array→array
 *  - backed enum      : enum values; type inferred from the backing type (int/string)
 *  - unit enum        : enum names, type string
 *  - DateTimeInterface: string / format date-time
 *  - *Uuid class      : string / format uuid (no framework Uuid type exists, so class-name heuristic)
 *  - other class      : a $ref (caller decides DTO eligibility); format only via explicit override
 *  - nullable / union : `?T` and `T|null` collapse to T nullable; genuine unions take the first member
 *
 * Format/date/email/uuid may be forced via $formatOverride (from #[Field(format)]).
 *
 * Step 1 (M1): type mapping only; collection item resolution (via #[Items] / docblock) is Step 2/3, so
 * `items` is always returned null here and filled upstream.
 */
final class TypeMapper
{
    /** @var string */
    public const REF_PREFIX = '#/components/schemas/';

    /** @var array<string, ?string> PHP scalar/pseudo type => OpenAPI type (null = no JSON type) */
    private const SCALARS = [
        'int' => 'integer',
        'string' => 'string',
        'bool' => 'boolean',
        'float' => 'number',
        'double' => 'number',
        'array' => 'array',
        'object' => 'object',
        'iterable' => 'array',
        'mixed' => null,
        'callable' => null,
        'void' => null,
        'never' => null,
        'null' => null,
        'false' => 'boolean',
        'true' => 'boolean',
    ];

    /**
     * Map a reflected PHP type to an OpenAPI schema fragment.
     *
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array}
     */
    public function map(?ReflectionType $type, ?string $formatOverride = null): array
    {
        if ($type === null) {
            return $this->empty(nullable: true);
        }
        if ($type instanceof ReflectionUnionType) {
            return $this->mapUnion($type, $formatOverride);
        }
        if ($type instanceof ReflectionIntersectionType) {
            // Intersection types are exotic in DTOs; represent via the first member as a ref/object.
            $members = $type->getTypes();
            return $this->mapNamed($members[0] ?? null, $formatOverride);
        }
        if ($type instanceof ReflectionNamedType) {
            return $this->mapNamed($type, $formatOverride);
        }
        return $this->empty(nullable: true);
    }

    /**
     * Map a known class FQCN (not a reflection type) to a schema fragment.
     *
     * @param ?class-string $fqcn
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array}
     */
    public function mapClass(string $fqcn, bool $nullable = false, ?string $formatOverride = null): array
    {
        if (is_subclass_of($fqcn, BackedEnum::class)) {
            $values = array_map(static fn(BackedEnum $case) => $case->value, $fqcn::cases());
            $type = is_int($values[0] ?? null) ? 'integer' : 'string';
            return ['type' => $type, 'format' => $formatOverride, 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => $values];
        }
        if (is_subclass_of($fqcn, UnitEnum::class)) {
            $values = array_map(static fn(UnitEnum $case) => $case->name, $fqcn::cases());
            return ['type' => 'string', 'format' => $formatOverride, 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => $values];
        }
        if (is_a($fqcn, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => $formatOverride ?? 'date-time', 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => null];
        }
        if (str_ends_with($fqcn, 'Uuid')) {
            return ['type' => 'string', 'format' => $formatOverride ?? 'uuid', 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => null];
        }

        // Anything else is a referenced schema (DTO/entity); the caller decides eligibility.
        return ['type' => null, 'format' => $formatOverride, 'nullable' => $nullable, 'ref' => $fqcn, 'items' => null, 'enum' => null];
    }

    /**
     * OpenAPI type for a PHP scalar name, or null when it is not a built-in scalar.
     */
    public function mapScalar(string $phpType): ?string
    {
        return self::SCALARS[$phpType] ?? null;
    }

    /**
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array}
     */
    private function mapNamed(?ReflectionNamedType $type, ?string $formatOverride): array
    {
        if ($type === null) {
            return $this->empty(nullable: true);
        }
        $phpName = $type->getName();
        $nullable = $type->allowsNull();

        if (array_key_exists($phpName, self::SCALARS)) {
            return ['type' => self::SCALARS[$phpName], 'format' => $formatOverride, 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => null];
        }
        if (in_array($phpName, ['self', 'static', 'parent'], true)) {
            // Treated as object; the enclosing-class ref is resolved by the caller.
            return ['type' => 'object', 'format' => $formatOverride, 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => null];
        }

        return $this->mapClass($phpName, $nullable, $formatOverride);
    }

    private function mapUnion(ReflectionUnionType $type, ?string $formatOverride): array
    {
        $nullable = false;
        $nonNull = [];
        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType && $member->getName() === 'null') {
                $nullable = true;
            } else {
                $nonNull[] = $member;
            }
        }
        if ($nonNull === []) {
            return $this->empty(nullable: true);
        }
        // Genuine unions are not auto-derived (plan §7 boundary); take the first member in reflection's
        // canonical order (PHP reorders union members, so this is NOT declaration order) and keep nullable.
        $mapped = $this->mapNamed($nonNull[0], $formatOverride);
        $mapped['nullable'] = $nullable || $mapped['nullable'];
        return $mapped;
    }

    /**
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array}
     */
    private function empty(bool $nullable): array
    {
        return ['type' => null, 'format' => null, 'nullable' => $nullable, 'ref' => null, 'items' => null, 'enum' => null];
    }
}
