<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

use SpsFW\Core\Compile\Introspection\RequiredSource;

/**
 * Compile-time description of a single DTO property — the common input for both the OpenAPI
 * SchemaMetadata projection and the ValidationRuleGraph produced from the same schema.
 *
 *  - name        : PHP property name
 *  - serialName  : JSON key this property serializes to (defaults to name); must match json_encode output.
 *                  In the schema projection it comes from #[Field(name)] or the PHP name — NEVER from the
 *                  legacy `#[OA\Property(property:)]` arg, which is the VALIDATION key only (serialization
 *                  contract, plan §6). The rule graph still uses that OA arg as its key (parity, M2).
 *  - phpType     : normalized PHP type string (e.g. 'int', 'string', FQCN, 'array')
 *  - ref         : FQCN of a nested DTO the graph references; null for scalars / collections of scalars
 *  - refClass    : reflection-derived nested class (first non-builtin type member), the parity source for ref
 *  - itemType    : for arrays: the element PHP type / FQCN, else null (resolved via #[Items])
 *  - objectMap   : a PHP `array` EXPLICITLY declared an OBJECT/map (a JSON object, not a sequence). Set ONLY
 *                  by an explicit signal — `#[Field(objectMap: true)]` or a legacy OA object declaration
 *                  (`type:object`/`additionalProperties`/inline `properties`). NEVER inferred from a bare array
 *                  or a bare `$ref` (those are ambiguous and surface a warning instead). When objectMap is set
 *                  AND a resolvable ref is present, the emitter renders the typed single object `{$ref}`;
 *                  otherwise it renders free-form `{type: object}`. A real list still uses itemType.
 *  - format      : OpenAPI format hint (uuid, date, date-time, email, …) from #[Field(format)] or class type
 *  - constraints : minimum/maximum/minLength/maxLength/enum from #[Field]
 *  - nullable / hasDefault / defaultValue : the PHP-type optionality signals (post-OA required source)
 *  - required    : the OA-parity required flag (legacy `required:[true]`); the active source in {@see isRequired()}
 *                  under {@see RequiredSource::Oa} until the OA source is removed (M8). The two sources are
 *                  NEVER blended — see {@see isRequired()}.
 *  - readOnly / writeOnly / schemaName : direction-specific projection hints (#[Field])
 *  - inlineObject/inlineItems : an INLINE response shape facet (from #[Response(shape: …)]) — the property is
 *                  either this inline object (inlineObject) or an ARRAY of it (inlineItems). Set ONLY by the
 *                  inline-shape projection of an ad-hoc response body (e.g. `{msg, status}`); never by a DTO.
 *                  Lets the emitter recurse into nested inline shapes that no DTO class describes.
 *  - description : an inline-shape property description (the `description:` facet). Emitted verbatim; never
 *                  blended with a legacy OA description (inline shapes are doc-only). Null = absent.
 *  - itemSchema  : a full INLINE array element fragment (carrying type/format/enum/example/default/
 *                  constraints), set from a shape facet `items: {type: …, …}`. Preferred over `itemType`
 *                  (the plain string) when both are present, so an inline item keeps ALL its facets instead
 *                  of collapsing to a bare scalar type. Set ONLY by the inline-shape projection (D3).
 *  - additionalProperties / additionalPropertiesFalse : a TYPED map value — a property whose JSON value is an
 *                  object of uniform values (e.g. `rules: {<id>: [string]}`). additionalProperties holds the
 *                  value-schema fragment; additionalPropertiesFalse forbids extra keys. Set ONLY by an explicit
 *                  inline-shape facet (a real list still uses itemType; a free-form map uses objectMap).
 *  - rawArguments: PARITY-PHASE ONLY — the ordered #[OA\Property] getArguments() snapshot that the rule
 *                  graph replays to stay byte-compatible with Router::extractValidationRules(); removed in M8.
 *
 * Step 1 (M1): the VO exists but is not yet populated by any reader.
 */
final readonly class PropertyMetadata
{
    /**
     * @param ?class-string $ref
     * @param ?class-string $refClass
     * @param ?list<mixed> $enum
     * @param array<string, mixed> $extra forward-compatible bag for rules not yet modeled explicitly
     * @param ?array<string, mixed> $rawArguments parity-phase ordered #[OA\Property] getArguments()
     */
    public function __construct(
        public string $name,
        public ?string $serialName = null,
        public ?string $phpType = null,
        public ?string $ref = null,
        public ?string $refClass = null,
        public ?string $itemType = null,
        public bool $objectMap = false,
        public ?string $format = null,
        public bool $nullable = false,
        public bool $hasDefault = false,
        public mixed $defaultValue = null,
        public bool $required = false,
        public ?array $enum = null,
        public mixed $minimum = null,
        public mixed $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public mixed $example = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public ?string $schemaName = null,
        public ?SchemaMetadata $inlineObject = null,
        public ?SchemaMetadata $inlineItems = null,
        public ?string $description = null,
        public ?SchemaMetadata $itemSchema = null,
        public ?SchemaMetadata $additionalProperties = null,
        public bool $additionalPropertiesFalse = false,
        public array $extra = [],
        public ?array $rawArguments = null,
    ) {
    }

    /**
     * The JSON key this property serializes to (serialName if set, else the PHP name).
     */
    public function serialName(): string
    {
        return $this->serialName ?? $this->name;
    }

    /**
     * Whether the property is required, resolved through the active {@see RequiredSource} (plan §7 — the two
     * sources are never blended mid-parity):
     *  - {@see RequiredSource::Oa}     : the legacy OA `required:[true]` flag ($required) — the PARITY source,
     *                                     byte-identical to Router::extractValidationRules; the default until M8.
     *  - {@see RequiredSource::PhpType}: derived from the PHP type — non-nullable AND no default. The post-OA
     *                                     source, enabled in a separate source-mode after the OA cleanup.
     */
    public function isRequired(RequiredSource $mode = RequiredSource::Oa): bool
    {
        return $mode === RequiredSource::Oa
            ? $this->required
            : (!$this->nullable && !$this->hasDefault);
    }
}
