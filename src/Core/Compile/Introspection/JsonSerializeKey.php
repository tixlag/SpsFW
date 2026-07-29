<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

/**
 * One wire key of a statically-proven {@see \JsonSerializable::jsonSerialize()} shape (Step 9.5 §2), and where
 * its type comes from. Exactly one of `propertyName` / `literalType` / `nested` is set.
 *
 *  - wireName      : the literal JSON key (`'preview_info'` for the alias `preview_info => $this->preview`).
 *  - propertyName  : the `$this->X` the value is derived from (`preview`); the builder projects THIS property's
 *                    type/constraints under `wireName`. Covers a bare `$this->prop`, a scalar-value transform on
 *                    a property (`$this->date->format(…)` / `$this->date?->format(…)`), an array access on a
 *                    property (`$this->map['k']`), and a property-derived `array_map(fn $x => …, $this->prop)`.
 *  - literalType   : a PHP scalar type name (`'string'|'int'|'float'|'bool'|'null'`) when the value is a literal
 *                    scalar — emitted with that type and no constraints.
 *  - nested        : the value is a nested literal array (an inline object); each entry is itself a proven key.
 *
 * @param list<JsonSerializeKey> $nested
 */
final readonly class JsonSerializeKey
{
    public function __construct(
        public string $wireName,
        public ?string $propertyName = null,
        public ?string $literalType = null,
        public array $nested = [],
    ) {
    }
}
