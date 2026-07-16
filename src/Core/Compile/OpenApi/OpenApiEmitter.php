<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Introspection\TypeMapper;
use SpsFW\Core\Compile\Metadata\OperationMetadata;
use SpsFW\Core\Compile\Metadata\ParameterMetadata;
use SpsFW\Core\Compile\Metadata\PropertyMetadata;
use SpsFW\Core\Compile\Metadata\ResponseMetadata;
use SpsFW\Core\Compile\Metadata\SchemaMetadata;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the OpenAPI 3.1.0 document from {@see OperationMetadata}[] (Step 4).
 *
 * ARRAY-FIRST (plan Шаг 4): the emitter assembles a deterministic PHP array — the full document AST
 * (openapi/info/paths/components/securitySchemes) — and YAML is ONLY the final serialization step
 * ({@see Yaml::dump()}). No hand-rolled indentation, no string concatenation. The array is the testable,
 * assertable artifact; {@see toYaml()}/{@see toFile()} are thin serializers over it.
 *
 * The emitter emits a SECONDARY document (`.cache/swagger/openapi.generated.yml`); the primary
 * `openapi.yml` (swagger-php / {@see \SpsFW\Core\DocsUtil}) is untouched until M6 (plan §15).
 *
 * Operation normalization: raw operations are keyed by METHOD:path and collapsed last-wins — mirroring the
 * route-IR / Router cache (384 raw ⇒ 377 effective on the real `next` inventory). Each shadowed operation
 * surfaces a FATAL duplicate diagnostic; the surviving op is the one OpenAPI publishes.
 *
 * Schema collection: object DTOs referenced by request bodies / responses (and transitively, their nested
 * refs) are gathered into `components.schemas.<ShortName>` and referenced via `$ref`. Enums / scalars /
 * DateTime render inline. A short-name collision across two FQCNs is a FATAL structural error (ambiguous ref).
 *
 * Standard errors: {@see StandardErrorPolicy} contributes the 400/401/403/429/500 responses (no 422), each
 * $ref-ing the shared `Error` component, merged with the operation's declared #[Response] entries (declared
 * wins — a status is never duplicated).
 */
final class OpenApiEmitter
{
    private readonly StandardErrorPolicy $errors;

    private readonly TypeMapper $typeMapper;

    private readonly DtoSchemaBuilder $schemaBuilder;

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
        ?StandardErrorPolicy $errors = null,
        ?TypeMapper $typeMapper = null,
        ?DtoSchemaBuilder $schemaBuilder = null,
        private readonly string $defaultTitle = 'SpsFW API',
        private readonly string $defaultVersion = '0.1.0',
    ) {
        $this->errors = $errors ?? new StandardErrorPolicy();
        $this->typeMapper = $typeMapper ?? new TypeMapper();
        // Share the diagnostics so a nested-DTO cycle / eligibility error surfaces on the SAME collector that
        // halts the build, and so collected schemas stay consistent with the operation projection.
        $this->schemaBuilder = $schemaBuilder ?? new DtoSchemaBuilder($this->diagnostics);
    }

    /**
     * Assemble the full OpenAPI document array (array-first). Deterministic: paths and component schemas are
     * sorted alphabetically; per-operation keys are emitted in a fixed order.
     *
     * @param list<OperationMetadata> $operations raw (pre-normalization) operations
     * @return array<string, mixed>
     */
    public function emit(array $operations, ?string $title = null, ?string $version = null): array
    {
        $effective = $this->normalize($operations);

        /** @var array<string, array<string, mixed>> $componentsSchemas name ⇒ schema array */
        $componentsSchemas = [];
        // The canonical Error envelope is always present (referenced by standard error responses).
        $componentsSchemas[$this->errors::ERROR_SCHEMA_NAME] = $this->errors->errorSchema();

        $paths = [];
        foreach ($effective as $operation) {
            $methodKey = strtolower($operation->httpMethod);
            $paths[$operation->path][$methodKey] = $this->buildOperation($operation, $componentsSchemas);
        }
        ksort($paths);
        ksort($componentsSchemas);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $title ?? $this->defaultTitle,
                'version' => $version ?? $this->defaultVersion,
            ],
            'paths' => $paths,
            'components' => [
                'schemas' => $componentsSchemas,
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
        ];
    }

    /**
     * Serialize the document to a YAML string (final serialization step only).
     */
    public function toYaml(array $operations, ?string $title = null, ?string $version = null): string
    {
        return Yaml::dump($this->emit($operations, $title, $version), inline: 4, indent: 2, flags: 0);
    }

    /**
     * Emit and write the secondary generated spec to a file (dev/CI; never the primary openapi.yml).
     */
    public function toFile(array $operations, string $path, ?string $title = null, ?string $version = null): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $this->toYaml($operations, $title, $version));
    }

    /**
     * Collapse raw operations to the effective METHOD:path set (last-wins, mirroring the route IR / Router
     * cache). Each operation shadowed by a duplicate key surfaces a FATAL structural diagnostic — OpenAPI
     * cannot publish two operations on the same path+method, so the collision is never silent.
     *
     * @param list<OperationMetadata> $operations
     * @return list<OperationMetadata> one entry per effective METHOD:path
     */
    private function normalize(array $operations): array
    {
        /** @var array<string, list<OperationMetadata>> $byKey */
        $byKey = [];
        foreach ($operations as $operation) {
            $byKey[$this->operationKey($operation)][] = $operation;
        }

        $effective = [];
        foreach ($byKey as $key => $group) {
            if (count($group) > 1) {
                foreach ($group as $shadowed) {
                    $this->diagnostics->error(
                        controller: $shadowed->controller,
                        method: $shadowed->method,
                        dto: null,
                        field: 'route',
                        cause: sprintf(
                            'duplicate operation key %s is published by %d operations; only the last one is emitted',
                            $key,
                            count($group),
                        ),
                        fix: 'disambiguate the path or HTTP method so each operation has a unique METHOD:path',
                    );
                }
            }
            // Last-wins, mirroring Router::registerControllerRoutes ($routes[$key] = …).
            $effective[] = $group[count($group) - 1];
        }
        return $effective;
    }

    private function operationKey(OperationMetadata $operation): string
    {
        return strtoupper($operation->httpMethod) . ':' . $operation->path;
    }

    /**
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function buildOperation(OperationMetadata $operation, array &$componentsSchemas): array
    {
        $op = [];
        if ($operation->hasOperationId()) {
            $op['operationId'] = $operation->operationId;
        }
        $op['tags'] = $operation->tags;
        if ($operation->summary !== null && $operation->summary !== '') {
            $op['summary'] = $operation->summary;
        }
        if ($operation->description !== null && $operation->description !== '') {
            $op['description'] = $operation->description;
        }
        if ($operation->deprecated) {
            $op['deprecated'] = true;
        }

        $parameters = array_merge(
            $this->buildParameters($operation->pathParams),
            $this->buildParameters($operation->queryParams),
        );
        if ($parameters !== []) {
            $op['parameters'] = $parameters;
        }

        $requestBody = $this->buildRequestBody($operation, $componentsSchemas);
        if ($requestBody !== null) {
            $op['requestBody'] = $requestBody;
        }

        $op['responses'] = $this->buildResponses($operation, $componentsSchemas);

        $op = array_merge($op, $this->buildSecurity($operation));
        return $op;
    }

    /**
     * @param list<ParameterMetadata> $params
     * @return list<array<string, mixed>>
     */
    private function buildParameters(array $params): array
    {
        $out = [];
        foreach ($params as $param) {
            $schema = ['type' => $param->type ?? 'string'];
            if ($param->format !== null) {
                $schema['format'] = $param->format;
            }
            $entry = [
                'name' => $param->name,
                'in' => $param->in,
                'required' => $param->required,
                'schema' => $schema,
            ];
            if ($param->description !== null && $param->description !== '') {
                $entry['description'] = $param->description;
            }
            if ($param->deprecated) {
                $entry['deprecated'] = true;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return ?array<string, mixed>
     */
    private function buildRequestBody(OperationMetadata $operation, array &$componentsSchemas): ?array
    {
        $body = $operation->requestBody;
        if ($body === null) {
            return null;
        }
        $schema = $body->schema !== null
            ? $this->refOrInline($body->schema, $componentsSchemas)
            : ['type' => 'object'];
        $request = [
            'required' => $body->required,
            'content' => [
                $body->contentType => ['schema' => $schema],
            ],
        ];
        if ($body->description !== null && $body->description !== '') {
            $request['description'] = $body->description;
        }
        return $request;
    }

    /**
     * Merge the operation's declared responses with the standard error responses. A declared status always
     * wins (never duplicated); standard errors $ref the shared Error component.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, array<string, mixed>> keyed by status (string)
     */
    private function buildResponses(OperationMetadata $operation, array &$componentsSchemas): array
    {
        $responses = [];
        foreach ($operation->responses as $response) {
            $responses[(string) $response->status] = $this->buildResponse($response, $componentsSchemas);
        }

        $errorRef = ['$ref' => $this->refTo($this->errors::ERROR_SCHEMA_NAME)];
        foreach ($this->errors->responsesFor($operation) as $status => $description) {
            $key = (string) $status;
            if (array_key_exists($key, $responses)) {
                continue; // a declared #[Response] for this status wins.
            }
            $responses[$key] = [
                'description' => $description,
                'content' => [
                    'application/json' => ['schema' => $errorRef],
                ],
            ];
        }

        ksort($responses);
        return $responses;
    }

    /**
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function buildResponse(ResponseMetadata $response, array &$componentsSchemas): array
    {
        $out = ['description' => $response->description !== '' ? $response->description : 'OK'];
        if ($response->schema !== null) {
            $out['content'] = [
                $response->contentType => ['schema' => $this->refOrInline($response->schema, $componentsSchemas)],
            ];
        } elseif ($response->arrayItem !== null) {
            $out['content'] = [
                $response->contentType => [
                    'schema' => [
                        'type' => 'array',
                        'items' => $this->refOrInline($response->arrayItem, $componentsSchemas),
                    ],
                ],
            ];
        }
        if ($response->headers !== []) {
            $out['headers'] = $response->headers;
        }
        return $out;
    }

    /**
     * @return array<string, mixed> security + x-required-rules keys (omitted entirely when anonymous)
     */
    private function buildSecurity(OperationMetadata $operation): array
    {
        $security = $operation->security;
        if ($security === null || $security->isAnonymous()) {
            return ['security' => []];
        }
        $out = [
            'security' => [['bearerAuth' => []]],
        ];
        if ($security->hasRules()) {
            $out['x-required-rules'] = [
                'any' => $security->requiredRules['any'],
                'all' => $security->requiredRules['all'],
            ];
        }
        return $out;
    }

    // --- schema rendering / collection ---

    /**
     * Render a schema fragment: object DTOs ⇒ $ref (collected into components); enums/scalars ⇒ inline.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function refOrInline(SchemaMetadata $schema, array &$componentsSchemas): array
    {
        if ($schema->isEnum) {
            $inline = ['type' => $schema->enumType ?? 'string'];
            if ($schema->enumCases !== null) {
                $inline['enum'] = array_values($schema->enumCases);
            }
            return $inline;
        }
        if ($schema->type !== null) {
            // scalar / DateTime inline fragment.
            $inline = ['type' => $schema->type];
            if ($schema->format !== null) {
                $inline['format'] = $schema->format;
            }
            return $inline;
        }
        if ($schema->className !== null && !$schema->isEmpty()) {
            $name = $this->schemaName($schema->className);
            $this->collectObjectSchema($name, $schema, $componentsSchemas);
            return ['$ref' => $this->refTo($name)];
        }
        // Empty object fragment (no properties, no type) — render as a bare object.
        return ['type' => 'object'];
    }

    /**
     * Register an object DTO schema under its short name (collision ⇒ FATAL), rendering its properties and
     * collecting nested refs. CYCLE-SAFE: a placeholder (x-fqcn only) is pre-registered BEFORE the property
     * walk, so a cyclic ref (A→B→A) re-enters, sees its own placeholder, and stops — the outer call fills in
     * the real properties afterward. The x-fqcn vendor extension also lets the parity normalizer resolve refs.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     */
    private function collectObjectSchema(string $name, SchemaMetadata $schema, array &$componentsSchemas): void
    {
        if (isset($componentsSchemas[$name])) {
            $existing = $componentsSchemas[$name];
            // Same class already collected OR mid-render (cycle placeholder) — nothing to do.
            if (($existing['x-fqcn'] ?? null) === $schema->className) {
                return;
            }
            // Two different FQCNs collapse to the same short name ⇒ ambiguous $ref target.
            $this->diagnostics->error(
                controller: null,
                method: null,
                dto: $schema->className,
                field: 'schema',
                cause: sprintf(
                    'schema name collision: %s and %s both map to components.schemas.%s',
                    $existing['x-fqcn'] ?? '(unknown)',
                    $schema->className,
                    $name,
                ),
                fix: 'rename one class, or set an explicit component name via #[Field(schema: …)]',
            );
            return;
        }

        // Pre-register a placeholder so a self-referential (or mutually-recursive) property walk terminates.
        $componentsSchemas[$name] = ['type' => 'object', 'properties' => new \stdClass(), 'x-fqcn' => $schema->className];

        $properties = [];
        $required = [];
        foreach ($schema->properties as $property) {
            $properties[$property->serialName()] = $this->renderProperty($property, $componentsSchemas);
            if ($property->required) {
                $required[] = $property->serialName();
            }
        }

        $component = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $component['required'] = $required;
        }
        if ($schema->description !== '') {
            $component['description'] = $schema->description;
        }
        $component['x-fqcn'] = $schema->className;
        $componentsSchemas[$name] = $component;
    }

    /**
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function renderProperty(PropertyMetadata $property, array &$componentsSchemas): array
    {
        // Array element (#[Items] / legacy OA items).
        if ($property->itemType !== null) {
            $items = $this->isClassish($property->itemType)
                ? $this->classRefSchema($property->itemType, $componentsSchemas)
                : ['type' => $this->typeMapper->mapScalar($property->itemType) ?? 'string'];
            $schema = ['type' => 'array', 'items' => $items];
            return $this->withConstraints($schema, $property);
        }

        // Nested object ref (reflection class or legacy OA ref).
        if ($property->refClass !== null) {
            return $this->withConstraints(
                $this->classRefSchema($property->refClass, $componentsSchemas),
                $property,
            );
        }

        // Scalar / enum-on-property.
        $schema = $this->scalarPropertySchema($property);
        return $this->withConstraints($schema, $property);
    }

    /**
     * A nested class reference: enum / DateTime render inline (no component); a real object DTO is built via
     * the shared {@see DtoSchemaBuilder}, collected into components, and referenced via $ref.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function classRefSchema(string $fqcn, array &$componentsSchemas): array
    {
        $mapped = $this->typeMapper->mapClass($fqcn);
        // Enum / DateTime ⇒ inline scalar/enum fragment (no component).
        if ($mapped['ref'] === null) {
            $inline = [];
            if ($mapped['type'] !== null) {
                $inline['type'] = $mapped['type'];
            }
            if ($mapped['format'] !== null) {
                $inline['format'] = $mapped['format'];
            }
            if ($mapped['enum'] !== null) {
                $inline['enum'] = array_values($mapped['enum']);
            }
            return $inline;
        }

        $name = $this->schemaName($fqcn);
        $this->collectObjectSchema($name, $this->schemaBuilder->build($fqcn), $componentsSchemas);
        return ['$ref' => $this->refTo($name)];
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarPropertySchema(PropertyMetadata $property): array
    {
        $schema = [];
        $type = $this->typeMapper->mapScalar($property->phpType ?? 'string');
        $schema['type'] = $type ?? 'string';
        if ($property->format !== null) {
            $schema['format'] = $property->format;
        }
        if ($property->enum !== null) {
            $schema['enum'] = array_values($property->enum);
        }
        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withConstraints(array $schema, PropertyMetadata $property): array
    {
        if ($property->format !== null && !isset($schema['format'])) {
            $schema['format'] = $property->format;
        }
        if ($property->enum !== null && !isset($schema['enum'])) {
            $schema['enum'] = array_values($property->enum);
        }
        if ($property->minimum !== null) {
            $schema['minimum'] = $property->minimum;
        }
        if ($property->maximum !== null) {
            $schema['maximum'] = $property->maximum;
        }
        if ($property->minLength !== null) {
            $schema['minLength'] = $property->minLength;
        }
        if ($property->maxLength !== null) {
            $schema['maxLength'] = $property->maxLength;
        }
        if ($property->example !== null) {
            $schema['example'] = $property->example;
        }
        if ($property->readOnly) {
            $schema['readOnly'] = true;
        }
        if ($property->writeOnly) {
            $schema['writeOnly'] = true;
        }
        if ($property->nullable && !isset($schema['$ref'])) {
            $schema['nullable'] = true;
        }
        return $schema;
    }

    private function isClassish(string $type): bool
    {
        return !$this->isBuiltinScalar($type);
    }

    private function isBuiltinScalar(string $type): bool
    {
        return in_array($type, ['int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'number', 'array', 'object', 'mixed'], true);
    }

    private function schemaName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function refTo(string $name): string
    {
        return '#/components/schemas/' . $name;
    }
}
