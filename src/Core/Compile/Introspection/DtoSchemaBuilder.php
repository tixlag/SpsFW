<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use OpenApi\Attributes\Property;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Attributes\OpenApi\Items;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Metadata\PropertyMetadata;
use SpsFW\Core\Compile\Metadata\SchemaMetadata;
use SpsFW\Core\Compile\Metadata\ValidationRuleGraph;
use SpsFW\Core\Validation\Validator;

/**
 * Builds the canonical DTO schema (response/request projection) and the validation rule graph from a single
 * reflection pass over a class.
 *
 * This is the unification point (plan §6): one read of a DTO yields two DISTINCT property sets:
 *
 *  - the SCHEMA projection ({@see build()}): every public, non-static, serializable property — with or
 *    without `#[OA\Property]` — enriched from `#[Field]`/`#[Items]`. Its serial name is the JSON key
 *    `json_encode` actually emits: `#[Field(name)]` or the PHP property name, NEVER the legacy
 *    `#[OA\Property(property:)]` arg (which may lie — serialization contract, plan §6). private/protected
 *    properties are excluded (they are not serialized). This is what the OpenAPI schema projection consumes.
 *
 *  - the RULE GRAPH ({@see ruleGraph()} / {@see ruleGraphForClass()}): ONLY the OA-tagged properties (plus
 *    non-promoted ctor params carrying `#[OA\Property]`), with the OA `property` arg as the key. This is the
 *    PARITY source — {@see ruleGraphArray()} replays Router::extractValidationRules() BYTE-FOR-BYTE over the
 *    captured raw #[OA\Property] arguments. OA is the legacy validation key, not the JSON serial name; the two
 *    sets diverge until the OA source is removed (M8), after which the typed schema fields drive the graph too.
 *
 * Splitting the two sets is what keeps today's runtime Validator byte-identical (it reads OA) while letting the
 * spec describe the real JSON shape (which may have no OA at all). The rule graph is available without building
 * the schema via {@see ruleGraphFor()} — the route-IR path uses that, so a DTO's schema projection (and any
 * schema-only diagnostics it might add) never leaks into route-cache compilation.
 *
 * Memoized per FQCN (plan §17). The rule graph carries an explicit cycle guard: a nested-DTO cycle is reported
 * as a compile diagnostic and halts via {@see CompileDiagnostics::throwOnErrors()} — never silently collapsed.
 */
final class DtoSchemaBuilder
{
    /** @var array<class-string, SchemaMetadata> schema projection per FQCN */
    private array $memo = [];

    /** @var array<class-string, array<string, array<string, mixed>>> rule graph per FQCN (deterministic) */
    private array $ruleMemo = [];

    /** @var array<class-string, true> DTOs currently on the ruleGraph expansion stack (cycle detection) */
    private array $ruleStack = [];

    public function __construct(
        private readonly CompileDiagnostics $diagnostics = new CompileDiagnostics(),
    ) {
    }

    /**
     * Build (and memoize) the SCHEMA projection for a DTO class: every public, non-static, serializable
     * property, enriched from #[Field]/#[Items]. Non-recursive — nested-class references are captured as
     * strings (refClass/itemType), so a cyclic DTO does not loop here; the cycle surfaces only when the rule
     * graph is expanded.
     *
     * @param class-string $class
     */
    public function build(string $class): SchemaMetadata
    {
        if (isset($this->memo[$class])) {
            return $this->memo[$class];
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        // ctor-param defaults (by PHP name) — the middle rung of the property default-value chain.
        $constructorParamDefaults = [];
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $constructorParamDefaults[$param->getName()] = $param->getDefaultValue();
                }
            }
        }

        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            // Only public, non-static properties serialize (serialization contract, plan §6). private/protected
            // and static properties are not part of the JSON shape. (Promoted public params are public props.)
            if ($property->isStatic() || !$property->isPublic()) {
                continue;
            }
            $properties[] = $this->makeSchemaProperty($property, $constructorParamDefaults, $class);
        }

        return $this->memo[$class] = new SchemaMetadata(
            className: $class,
            name: $this->shortName($class),
            properties: $properties,
        );
    }

    /**
     * Project a schema to the validation rule graph consumed verbatim by the runtime Validator. Thin wrapper
     * over {@see ruleGraphForClass()} keyed by the schema's class — kept so the parity oracle
     * `ruleGraph(build($dto))` reads naturally.
     */
    public function ruleGraph(SchemaMetadata $schema): ValidationRuleGraph
    {
        return new ValidationRuleGraph($this->ruleGraphForClass($schema->className ?? ''));
    }

    /**
     * Rule graph for a class WITHOUT building the schema projection. The route-cache path uses this so a DTO's
     * schema projection (and any future schema-only diagnostics) never leaks into route-cache compilation.
     *
     * @param class-string $class
     */
    public function ruleGraphFor(string $class): ValidationRuleGraph
    {
        return new ValidationRuleGraph($this->ruleGraphForClass($class));
    }

    /**
     * Expand the rule graph for a class with memoization and an explicit cycle guard.
     *
     * A class already on the expansion stack means the validation graph is cyclic; it is reported as a
     * compile diagnostic and returns [] (so the caller does not recurse forever). The Coordinator's
     * {@see CompileDiagnostics::throwOnErrors()} then halts the build.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ruleGraphForClass(string $class): array
    {
        if (isset($this->ruleMemo[$class])) {
            return $this->ruleMemo[$class];
        }
        if (isset($this->ruleStack[$class])) {
            $this->diagnostics->error(
                controller: null,
                method: null,
                dto: $class,
                field: null,
                cause: sprintf(
                    'cyclic validation graph: %s participates in a nested-DTO cycle (direct or transitive self-reference)',
                    $class,
                ),
                fix: 'break the cycle (describe the leaf with #[Items]/#[Field], or exclude the property from nested-rule extraction)',
            );
            return [];
        }
        $this->ruleStack[$class] = true;
        $rules = $this->ruleGraphArray($this->oaTaggedProperties($class));
        unset($this->ruleStack[$class]);
        return $this->ruleMemo[$class] = $rules;
    }

    /**
     * The OA-tagged property set that drives the PARITY rule graph: every #[OA\Property] on a class property
     * (incl. promoted ctor params) PLUS non-promoted ctor params carrying #[OA\Property]. The serial name is
     * the OA `property` arg (the legacy validation key), and rawArguments carries the verbatim OA arguments
     * that {@see ruleGraphArray()} replays.
     *
     * @return list<PropertyMetadata>
     */
    private function oaTaggedProperties(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $constructorParamDefaults = [];
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $constructorParamDefaults[$param->getName()] = $param->getDefaultValue();
                }
            }
        }

        $properties = [];

        // 1) Class properties — this ALSO covers promoted ctor params (getProperties() returns them once),
        //    matching Router::extractValidationRules' first loop.
        foreach ($reflection->getProperties() as $property) {
            $realName = $property->getName();
            foreach ($this->oaPropertyArguments($property) as $args) {
                [$hasDefault, $defaultValue] = $this->resolvePropertyDefault(
                    $property,
                    $realName,
                    $args,
                    $constructorParamDefaults,
                );
                $properties[] = $this->makeOaProperty($realName, $property->getType(), $args, $hasDefault, $defaultValue);
            }
        }

        // 2) Non-promoted ctor params carrying #[OA\Property] (dedicated branch; promoted ones are already
        //    handled above and are skipped here to avoid duplicates — exactly like Router).
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isPromoted()) {
                    continue;
                }
                foreach ($this->oaPropertyArguments($param) as $args) {
                    [$hasDefault, $defaultValue] = $this->resolveParamDefault($param, $args);
                    $properties[] = $this->makeOaProperty($param->getName(), $param->getType(), $args, $hasDefault, $defaultValue);
                }
            }
        }

        return $properties;
    }

    /**
     * The byte-faithful replay of Router::extractValidationRules() over the OA-tagged property set.
     *
     * @param list<PropertyMetadata> $oaProperties
     * @return array<string, array<string, mixed>>
     */
    private function ruleGraphArray(array $oaProperties): array
    {
        $rules = [];
        foreach ($oaProperties as $property) {
            $name = $property->serialName(); // OA `property` arg when given, else the PHP name
            $realName = $property->name;
            $args = $property->rawArguments ?? [];
            $refClass = $property->refClass;

            $propertyRules = [];
            if ($property->hasDefault) {
                $propertyRules['default'] = $property->defaultValue;
            }
            $propertyRules['real_name'] = $realName;

            foreach ($args as $attributeKey => $attributeValue) {
                if ($attributeKey === 'ref' || $refClass !== null || $attributeKey === 'items') {
                    if ($refClass !== null) {
                        $attributeValue = $refClass;
                    } elseif ($attributeKey === 'items' && isset($attributeValue->ref)) {
                        $attributeValue = $attributeValue->ref;
                    }
                    if (!class_exists($attributeValue)) {
                        break;
                    }
                    if ($attributeKey === 'items' || (isset($args['type']) && $args['type'] == 'array')) {
                        $propertyRules['ref'] = $attributeValue;
                        $propertyRules['type'] = 'array';
                        $propertyRules['nested_rules'] = $this->ruleGraphForClass($attributeValue);
                    } else {
                        $propertyRules['ref'] = $attributeValue;
                        $propertyRules['nested_rules'] = $this->ruleGraphForClass($attributeValue);
                    }
                    break;
                }
                if (isset(Validator::$attributesOpenApi[$attributeKey])) {
                    $propertyRules[$attributeKey] = $attributeValue;
                }
            }

            if (!empty($propertyRules)) {
                $rules[$name] = $propertyRules;
            }
        }

        return $rules;
    }

    /**
     * Assemble a PropertyMetadata for the PARITY rule graph: serial name = OA `property` arg, rawArguments =
     * verbatim OA args, ref/default from reflection+OA. (The typed schema-projection fields are populated too
     * but are NOT read by the rule graph — they exist for traceability.)
     *
     * @param array<string, mixed> $args
     */
    private function makeOaProperty(
        string $realName,
        ?ReflectionType $type,
        array $args,
        bool $hasDefault,
        mixed $defaultValue,
    ): PropertyMetadata {
        $serialName = isset($args['property']) ? (string) $args['property'] : null;
        $refClass = $this->reflectionClassName($type);
        $oaRef = isset($args['ref']) && is_string($args['ref']) ? $args['ref'] : null;
        $phpType = $type instanceof ReflectionNamedType
            ? $type->getName()
            : ($type !== null ? (string) $type : null);

        return new PropertyMetadata(
            name: $realName,
            serialName: $serialName,
            phpType: $phpType,
            ref: $refClass ?? $oaRef,
            refClass: $refClass,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            nullable: $type?->allowsNull() ?? false,
            required: isset($args['required']) && $args['required'] === [true],
            rawArguments: $args,
        );
    }

    /**
     * Assemble a PropertyMetadata for the SCHEMA projection: serial name = #[Field(name)] ?? PHP name (NOT the
     * OA `property` arg), constraints from #[Field], item type from #[Items], ref/nullability/default from
     * reflection (with the OA `default`/`required` as legacy fallback). #[Items] must declare exactly one of
     * `class`/`type`, else a diagnostic.
     *
     * @param array<string, mixed> $constructorParamDefaults
     */
    private function makeSchemaProperty(
        ReflectionProperty $property,
        array $constructorParamDefaults,
        string $declaringClass,
    ): PropertyMetadata {
        $realName = $property->getName();
        $type = $property->getType();
        $oaArgs = $this->oaPropertyArguments($property)[0] ?? [];
        $field = $this->firstAttribute($property, Field::class);
        $items = $this->firstAttribute($property, Items::class);

        if ($items !== null) {
            $this->assertItemsExclusive($items, $realName, $declaringClass);
        }

        [$hasDefault, $defaultValue] = $this->resolvePropertyDefault($property, $realName, $oaArgs, $constructorParamDefaults);
        $refClass = $this->reflectionClassName($type);
        $oaRef = isset($oaArgs['ref']) && is_string($oaArgs['ref']) ? $oaArgs['ref'] : null;
        $phpType = $type instanceof ReflectionNamedType
            ? $type->getName()
            : ($type !== null ? (string) $type : null);

        // #[Field(enum)] is always a list; the legacy OA `enum` may be a scalar (swagger-php allows it) —
        // normalize to a list so the typed ?array field never receives a string.
        $enum = $field?->enum;
        if ($enum === null && array_key_exists('enum', $oaArgs)) {
            $rawEnum = $oaArgs['enum'];
            $enum = is_array($rawEnum) ? array_values($rawEnum) : [$rawEnum];
        }

        return new PropertyMetadata(
            name: $realName,
            serialName: $field?->name, // null ⇒ serialName() falls back to the PHP name (the real JSON key)
            phpType: $phpType,
            ref: $refClass ?? $oaRef,
            refClass: $refClass,
            itemType: $this->itemsType($items),
            format: $field?->format ?? ($oaArgs['format'] ?? null),
            nullable: $type?->allowsNull() ?? true, // untyped ⇒ optional (treated as nullable)
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            required: isset($oaArgs['required']) && $oaArgs['required'] === [true], // OA-parity flag
            enum: $enum,
            minimum: $field?->min ?? ($oaArgs['minimum'] ?? null),
            maximum: $field?->max ?? ($oaArgs['maximum'] ?? null),
            minLength: $field?->minLength ?? ($oaArgs['minLength'] ?? null),
            maxLength: $field?->maxLength ?? ($oaArgs['maxLength'] ?? null),
            example: $field?->example ?? ($oaArgs['example'] ?? null),
            readOnly: $field?->readOnly ?? false,
            writeOnly: $field?->writeOnly ?? false,
            schemaName: $field?->schema,
            rawArguments: $oaArgs ?: null,
        );
    }

    /**
     * #[Items] must declare exactly one of `class` (a schema ref) or `type` (an OpenAPI scalar). Both or
     * neither is ambiguous and surfaces a compile diagnostic.
     */
    private function assertItemsExclusive(Items $items, string $propertyName, string $declaringClass): void
    {
        $hasClass = $items->class !== null && $items->class !== '';
        $hasType = $items->type !== null && $items->type !== '';
        if ($hasClass === $hasType) {
            $this->diagnostics->error(
                controller: null,
                method: null,
                dto: $declaringClass,
                field: $propertyName,
                cause: sprintf('#[Items] on %s::$%s must declare exactly one of `class` or `type`', $declaringClass, $propertyName),
                fix: "use #[Items(class: \ExampleDto::class)] OR #[Items(type: 'integer')], not both and not neither",
            );
        }
    }

    private function itemsType(?Items $items): ?string
    {
        if ($items === null) {
            return null;
        }
        return $items->class ?? $items->type;
    }

    /**
     * The first instantiated attribute named $class on a property, or null. (Field/Items are single-use.)
     */
    private function firstAttribute(ReflectionProperty $property, string $class): ?object
    {
        foreach ($property->getAttributes($class) as $attribute) {
            return $attribute->newInstance();
        }
        return null;
    }

    /**
     * The ordered #[OA\Property] argument maps declared on a property/parameter (verbatim, no instantiation),
     * one entry per declared attribute (Router processes each; later entries overwrite the same key).
     *
     * @return list<array<string, mixed>>
     */
    private function oaPropertyArguments(ReflectionProperty|ReflectionParameter $reflector): array
    {
        $out = [];
        foreach ($reflector->getAttributes(Property::class) as $attribute) {
            $out[] = $attribute->getArguments();
        }
        return $out;
    }

    /**
     * Property default-value precedence: property literal default > ctor-param default > OA `default` arg.
     * isset(null) is false, so an explicit null default yields no `default` key — matching Router.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $constructorParamDefaults
     * @return list{bool, mixed} [hasDefault, defaultValue]
     */
    private function resolvePropertyDefault(
        ReflectionProperty $property,
        string $realName,
        array $args,
        array $constructorParamDefaults,
    ): array {
        if ($property->hasDefaultValue()) {
            $value = $property->getDefaultValue();
        } elseif (array_key_exists($realName, $constructorParamDefaults)) {
            $value = $constructorParamDefaults[$realName];
        } else {
            $value = $args['default'] ?? null;
        }
        return [isset($value), $value];
    }

    /**
     * Non-promoted ctor-param default: param default > OA `default` arg (no property-default rung).
     *
     * @param array<string, mixed> $args
     * @return list{bool, mixed} [hasDefault, defaultValue]
     */
    private function resolveParamDefault(ReflectionParameter $param, array $args): array
    {
        if ($param->isDefaultValueAvailable()) {
            $value = $param->getDefaultValue();
        } elseif (isset($args['default'])) {
            $value = $args['default'];
        } else {
            $value = null;
        }
        return [isset($value), $value];
    }

    /**
     * First non-builtin class name in a (possibly union/intersection) type, mirroring Router exactly so
     * the ref/nested_rules branch fires on the same property the legacy producer picks.
     */
    private function reflectionClassName(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                $className = $this->reflectionClassName($nestedType);
                if ($className !== null) {
                    return $className;
                }
            }
        }
        return null;
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
