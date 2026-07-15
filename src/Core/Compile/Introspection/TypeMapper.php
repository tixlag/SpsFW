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
 *  - nullable union   : the ONLY union that maps cleanly — `T|null` (and `?T`) collapse to T nullable
 *
 * Boundaries (plan §7) — returned as an explicit *unsupported* result so the builder can surface a
 * CompileDiagnostics error / halt instead of silently losing information:
 *  - genuine union    : two or more non-null members (int|string) — not auto-derived
 *  - intersection     : (A&B), including the nullable DNF form (A&B)|null — not auto-derived
 * A mapping with `unsupported === true` carries a human-readable `reason`; callers should check
 * {@see isUnsupported()} before trusting `type`/`ref`/`enum`.
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
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array, unsupported: bool, reason: ?string}
     */
    public function map(?ReflectionType $type, ?string $formatOverride = null): array
    {
        if ($type === null) {
            return $this->pack(nullable: true);
        }
        if ($type instanceof ReflectionUnionType) {
            return $this->mapUnion($type, $formatOverride);
        }
        if ($type instanceof ReflectionIntersectionType) {
            return $this->unsupported('intersection types are not auto-derived; use #[Field]/#[Items] or the OA escape hatch');
        }
        if ($type instanceof ReflectionNamedType) {
            return $this->mapNamed($type, $formatOverride);
        }
        return $this->pack(nullable: true);
    }

    /**
     * Map a known class FQCN (not a reflection type) to a schema fragment.
     *
     * @param ?class-string $fqcn
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array, unsupported: bool, reason: ?string}
     */
    public function mapClass(string $fqcn, bool $nullable = false, ?string $formatOverride = null): array
    {
        if (is_subclass_of($fqcn, BackedEnum::class)) {
            $values = array_map(static fn(BackedEnum $case) => $case->value, $fqcn::cases());
            $type = is_int($values[0] ?? null) ? 'integer' : 'string';
            return $this->pack(type: $type, format: $formatOverride, nullable: $nullable, enum: $values);
        }
        if (is_subclass_of($fqcn, UnitEnum::class)) {
            $values = array_map(static fn(UnitEnum $case) => $case->name, $fqcn::cases());
            return $this->pack(type: 'string', format: $formatOverride, nullable: $nullable, enum: $values);
        }
        if (is_a($fqcn, \DateTimeInterface::class, true)) {
            return $this->pack(type: 'string', format: $formatOverride ?? 'date-time', nullable: $nullable);
        }
        if (str_ends_with($fqcn, 'Uuid')) {
            return $this->pack(type: 'string', format: $formatOverride ?? 'uuid', nullable: $nullable);
        }

        // Anything else is a referenced schema (DTO/entity); the caller decides eligibility.
        return $this->pack(ref: $fqcn, format: $formatOverride, nullable: $nullable);
    }

    /**
     * OpenAPI type for a PHP scalar name, or null when it is not a built-in scalar.
     */
    public function mapScalar(string $phpType): ?string
    {
        return self::SCALARS[$phpType] ?? null;
    }

    /**
     * Whether a mapping returned by map()/mapClass() is an explicit unsupported result.
     *
     * @param array{unsupported?: bool} $mapping
     */
    public function isUnsupported(array $mapping): bool
    {
        return !empty($mapping['unsupported']);
    }

    /**
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array, unsupported: bool, reason: ?string}
     */
    private function mapNamed(?ReflectionNamedType $type, ?string $formatOverride): array
    {
        if ($type === null) {
            return $this->pack(nullable: true);
        }
        $phpName = $type->getName();
        $nullable = $type->allowsNull();

        if (array_key_exists($phpName, self::SCALARS)) {
            return $this->pack(type: self::SCALARS[$phpName], format: $formatOverride, nullable: $nullable);
        }
        if (in_array($phpName, ['self', 'static', 'parent'], true)) {
            // Treated as object; the enclosing-class ref is resolved by the caller.
            return $this->pack(type: 'object', format: $formatOverride, nullable: $nullable);
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
            // null-only union.
            return $this->pack(nullable: true);
        }
        if (count($nonNull) === 1 && $nonNull[0] instanceof ReflectionNamedType) {
            // T|null (the only union that maps cleanly): collapse to the single NAMED member, nullable.
            $mapped = $this->mapNamed($nonNull[0], $formatOverride);
            $mapped['nullable'] = true;
            return $mapped;
        }

        // Not auto-derived (plan §7 boundary): a genuine union (two or more non-null members) OR a
        // nullable intersection member ((A&B)|null, PHP 8.2 DNF — a single non-named member). mapNamed()
        // is intentionally NOT called here: the member is not a ReflectionNamedType.
        $names = array_map(
            static function (ReflectionType $t): string {
                return $t instanceof ReflectionNamedType ? $t->getName() : (string) $t;
            },
            $nonNull,
        );
        if (count($nonNull) === 1) {
            return $this->unsupported('intersection (' . $names[0] . ')|null is not auto-derived; use #[Field]/#[Items] or the OA escape hatch');
        }
        return $this->unsupported('union ' . implode('|', $names) . ' is not auto-derived; use #[Field]/#[Items] or the OA escape hatch');
    }

    /**
     * Build a supported mapping fragment.
     *
     * @param ?class-string $ref
     * @param ?list<mixed> $enum
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array, unsupported: bool, reason: ?string}
     */
    private function pack(
        ?string $type = null,
        ?string $format = null,
        bool $nullable = false,
        ?string $ref = null,
        ?array $items = null,
        ?array $enum = null,
    ): array {
        return [
            'type' => $type,
            'format' => $format,
            'nullable' => $nullable,
            'ref' => $ref,
            'items' => $items,
            'enum' => $enum,
            'unsupported' => false,
            'reason' => null,
        ];
    }

    /**
     * Build an explicit unsupported mapping with a human-readable reason.
     *
     * @return array{type: ?string, format: ?string, nullable: bool, ref: ?class-string, items: ?array, enum: ?array, unsupported: bool, reason: ?string}
     */
    private function unsupported(string $reason, bool $nullable = false): array
    {
        return [
            'type' => null,
            'format' => null,
            'nullable' => $nullable,
            'ref' => null,
            'items' => null,
            'enum' => null,
            'unsupported' => true,
            'reason' => $reason,
        ];
    }
}
