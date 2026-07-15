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
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Metadata\PropertyMetadata;
use SpsFW\Core\Compile\Metadata\SchemaMetadata;
use SpsFW\Core\Compile\Metadata\ValidationRuleGraph;
use SpsFW\Core\Validation\Validator;

/**
 * Builds the canonical DTO schema and the validation rule graph from a single reflection pass.
 *
 * This is the unification point (plan §6): one read of a DTO yields both the {@see SchemaMetadata}
 * (OpenAPI projection, consumed from Step 4 on) and, via {@see ruleGraph()}, the {@see ValidationRuleGraph}
 * that the runtime Validator consumes — closing today's double read of #[OA\Property] (once by
 * Router::extractValidationRules for rules, once by swagger-php for the spec).
 *
 * Parity contract (plan §14, M2): ruleGraph() reproduces Router::extractValidationRules() BYTE-FOR-BYTE.
 * To guarantee that, the parity phase replays the EXACT same algorithm over the per-property raw
 * #[OA\Property] arguments captured into PropertyMetadata::$rawArguments (ordered, verbatim). The schema
 * projection fields on PropertyMetadata are populated in parallel for forward use but do NOT drive the
 * rule graph yet — that avoids any drift between two interpretations of the same data. Once the OA source
 * is removed (M8), rawArguments is dropped and the typed fields take over.
 *
 * Memoized per FQCN (plan §17). The rule graph is computed through {@see ruleGraphForClass()}, which carries
 * an explicit cycle guard: a nested-DTO cycle (direct or transitive self-reference) is reported as a compile
 * diagnostic and halts via {@see CompileDiagnostics::throwOnErrors()} — it is NEVER silently collapsed to an
 * empty placeholder (Router::extractValidationRules would recurse forever on the same input).
 *
 * Step 2 (M2): build() + ruleGraph(); the schema projection is enriched in Step 4.
 */
final class DtoSchemaBuilder
{
    /** @var array<class-string, SchemaMetadata> */
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
     * Build (and memoize) the schema for a DTO class. Non-recursive: it captures nested-class references as
     * strings (refClass), so a cyclic DTO does not loop here — the cycle surfaces (and is reported) only when
     * the rule graph is expanded.
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
                $properties[] = $this->makePropertyMetadata($realName, $property->getType(), $args, $hasDefault, $defaultValue);
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
                    $properties[] = $this->makePropertyMetadata($param->getName(), $param->getType(), $args, $hasDefault, $defaultValue);
                }
            }
        }

        return $this->memo[$class] = new SchemaMetadata(
            className: $class,
            name: $this->shortName($class),
            properties: $properties,
        );
    }

    /**
     * Project a schema to the validation rule graph consumed verbatim by the runtime Validator.
     *
     * The returned ValidationRuleGraph wraps the same ordered map Router::extractValidationRules() emits
     * (property key => rule map; required stored as [true]; nested DTOs/collections nested inline).
     */
    public function ruleGraph(SchemaMetadata $schema): ValidationRuleGraph
    {
        return new ValidationRuleGraph($this->ruleGraphForClass($schema->className ?? ''));
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
        $rules = $this->ruleGraphArray($this->build($class));
        unset($this->ruleStack[$class]);
        return $this->ruleMemo[$class] = $rules;
    }

    /**
     * The byte-faithful replay of Router::extractValidationRules() over the captured schema properties.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ruleGraphArray(SchemaMetadata $schema): array
    {
        $rules = [];
        foreach ($schema->properties as $property) {
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
     * Assemble a PropertyMetadata capturing both the parity-phase raw arguments and the reflection-derived
     * bits the rule graph replays. Typed schema-projection fields are left to Step 4 (their OA-driven
     * semantics, e.g. required:[true], are not modeled on the typed VO in the parity phase).
     *
     * @param array<string, mixed> $args
     * @return list{bool, mixed} [hasDefault, defaultValue]
     */
    private function makePropertyMetadata(
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
            // Typed projection field (NOT read by the parity rule graph): whether the PHP type allows null.
            // Populated now so the Step 3 query-param projection can mark optional params; the post-OA
            // required source (plan §7) is exactly this PHP-type nullability.
            nullable: $type?->allowsNull() ?? false,
            rawArguments: $args,
        );
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
