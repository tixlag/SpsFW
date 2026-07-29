<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

/**
 * The statically-projected wire shape of a {@see \JsonSerializable::jsonSerialize()} method (Step 9.5 §2).
 *
 * A class implementing {@see \JsonSerializable} curates its own response shape — typically a SUBSET of its
 * properties, excluding internal DB columns and credentials. The schema OUTPUT projection must honor that
 * subset instead of emitting every public/inherited property. This VO is the conservative, statically-provable
 * description of that subset, produced by {@see JsonSerializeShapeAnalyzer}.
 *
 *  - provable : the wire KEY SET is statically determined — the method returns a literal array (optionally
 *                merged with a provable `parent::jsonSerialize()` or a single-hop delegation), with NO runtime
 *                branching, dynamic/computed keys, loops, or `get_object_vars($this)`. When false, `reason`
 *                explains why and the builder renders NO exhaustive fallback (§3) — it emits a diagnostic asking
 *                for an explicit response declaration instead.
 *  - keys     : one {@see JsonSerializeKey} per wire key, in source order. Empty when !provable.
 *  - reason   : why the shape is not statically provable (for the diagnostic), null when provable.
 *
 * Conservative by construction: any value the analyzer cannot tie to a `$this->property`, a literal scalar, a
 * nested literal array, or a property-derived `array_map` makes the WHOLE shape non-provable (§2: an arbitrary
 * method call / service result ⇒ no exhaustive schema). A scalar-value transform on a property
 * (`$this->createdAt->format('Y-m-d')`) is NOT opaque — the key still maps to that property.
 */
final readonly class JsonSerializeProjection
{
    /**
     * @param list<JsonSerializeKey> $keys
     */
    public function __construct(
        public bool $provable,
        public array $keys = [],
        public ?string $reason = null,
    ) {
    }

    public static function unprovable(string $reason): self
    {
        return new self(provable: false, keys: [], reason: $reason);
    }
}
