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
 * ({@see Yaml::dump()}). {@see emit()} produces the array (and accumulates diagnostics ONCE);
 * {@see dump()} / {@see writeFile()} serialize an ALREADY-BUILT array without recompiling, so diagnostics stay
 * idempotent (never double-counted). The legacy {@see toYaml()} / {@see toFile()} re-emit internally and are
 * kept only for standalone convenience.
 *
 * The emitter emits a SECONDARY document (`.cache/swagger/openapi.generated.yml`); the primary `openapi.yml`
 * (swagger-php / {@see \SpsFW\Core\DocsUtil}) is untouched until M6 (plan §15). Publication is gated by the
 * caller: in parity mode {@see CompileDiagnostics::throwOnErrors()} MUST run before the file is written —
 * structural errors block the artifact; only an in-memory preview is allowed for the parity report.
 *
 * Operation normalization: raw operations are keyed by METHOD:path and collapsed last-wins — mirroring the
 * route-IR / Router cache (384 raw ⇒ 377 effective on the real `next` inventory). Each shadowed operation
 * surfaces a FATAL duplicate diagnostic; the surviving op is the one OpenAPI publishes.
 *
 * Schema naming: a {@see SchemaNameResolver} maps every referenced DTO FQCN ⇒ component name (class-level
 * `#[Field(schema:)]` override, else short name) with collision detection. The FQCN⇒name registry is INTERNAL
 * (never published as `x-fqcn`); the cycle guard keeps a separate set of collected FQCNs. Object DTOs are
 * gathered into `components.schemas.<Name>` and referenced via `$ref`; enums / scalars / DateTime inline.
 *
 * Nullability is expressed the OpenAPI 3.1 / JSON Schema 2020-12 way — a nullable scalar becomes
 * `type: [<type>, "null"]`, a nullable `$ref` becomes `anyOf: [{$ref}, {type: "null"}]`. The legacy
 * `nullable: true` is NEVER emitted.
 *
 * Standard errors: {@see StandardErrorPolicy} contributes the 400/401/403/429/500 responses (no 422), each
 * $ref-ing the shared `Error` component, merged with the operation's declared #[Response] entries (declared
 * wins — a status is never duplicated). 403 follows the EFFECTIVE runtime access pipeline, not the
 * documentation projection.
 */
final class OpenApiEmitter
{
    private readonly StandardErrorPolicy $errors;

    private readonly TypeMapper $typeMapper;

    private readonly DtoSchemaBuilder $schemaBuilder;

    /** Set fresh by each {@see emit()} call so emission is repeatable/idempotent. */
    private SchemaNameResolver $resolver;

    /** @var array<string, true> FQCNs whose component slot is allocated (collected or mid-render) — cycle guard */
    private array $collectedFqcn = [];

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
     * sorted alphabetically; per-operation keys are emitted in a fixed order. Diagnostics accumulate ONCE per
     * call (deduplicated by {@see CompileDiagnostics}); pass the result to {@see dump()}/{@see writeFile()} to
     * serialize without recompiling.
     *
     * @param list<OperationMetadata> $operations raw (pre-normalization) operations
     * @return array<string, mixed>
     */
    public function emit(array $operations, ?string $title = null, ?string $version = null): array
    {
        // Fresh per-call state ⇒ emit() is idempotent and may be invoked repeatedly without compounding.
        $this->resolver = new SchemaNameResolver($this->diagnostics);
        $this->collectedFqcn = [];

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
     * Serialize an ALREADY-BUILT document array to YAML. No compilation, no diagnostics — the document came
     * from {@see emit()}.
     *
     * Two Symfony-YAML portability guarantees are applied so the dumped spec is valid, portable OpenAPI
     * (consumers include js-yaml/Orval, which are stricter than the framework's own validator):
     *  - {@see normalizeForYaml()} collapses PHP enum instances to scalars (no `!php/enum` tag); and
     *  - `Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE` renders empty arrays as `[]`, not `{}`. Without it Symfony
     *    emits e.g. `security: [{ bearerAuth: {} }]` — the empty OAuth-scopes list `[]` becomes an empty
     *    MAP, which is invalid OpenAPI (must be an array). Safe here because the metadata graph never
     *    produces a legitimate empty-object SCHEMA `{}` (the only OpenAPI value that is an empty map);
     *    every empty array it emits is a list (security requirements, scopes, rule lists).
     */
    public function dump(array $document): string
    {
        return Yaml::dump($this->normalizeForYaml($document), inline: 4, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * Recursively convert PHP enum instances to portable scalars so the dumped YAML never carries the
     * Symfony-specific `!php/enum` tag — which js-yaml (Orval) and other OpenAPI tooling reject as an
     * unknown tag. An `example` declared as `[Site::LK, Site::PRO]` (real enum instances, read from a
     * PHP attribute) would otherwise serialize as `!php/enum FQCN::LK`. Backed enums use their backing
     * value; pure unit enums use the case name. Every other value passes through unchanged.
     */
    private function normalizeForYaml(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeForYaml($v);
            }
        }
        return $value;
    }

    /**
     * Serialize an ALREADY-BUILT document array and write it to disk.
     */
    public function writeFile(array $document, string $path): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $this->dump($document));
    }

    /**
     * The FQCN⇒component-name registry from the last {@see emit()} (for tooling/probes; never published).
     *
     * @return array<string, string>
     */
    public function componentRegistry(): array
    {
        return isset($this->resolver) ? $this->resolver->componentRegistry() : [];
    }

    /**
     * Convenience: emit + dump in one call. Prefer emit() once → dump() to avoid rebuilding. Diagnostics are
     * deduplicated, so calling this after emit() does not double-count — but it DOES rebuild the document.
     */
    public function toYaml(array $operations, ?string $title = null, ?string $version = null): string
    {
        return $this->dump($this->emit($operations, $title, $version));
    }

    /**
     * Convenience: emit + write in one call (standalone use). Prefer emit() once → writeFile() in probes/tests.
     */
    public function toFile(array $operations, string $path, ?string $title = null, ?string $version = null): void
    {
        $this->writeFile($this->emit($operations, $title, $version), $path);
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
            $this->buildParameters($operation->pathParams, $componentsSchemas),
            $this->buildParameters($operation->queryParams, $componentsSchemas),
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
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return list<array<string, mixed>>
     */
    private function buildParameters(array $params, array &$componentsSchemas): array
    {
        $out = [];
        foreach ($params as $param) {
            $schema = ['type' => $param->type ?? 'string'];
            if ($param->format !== null) {
                $schema['format'] = $param->format;
            }
            if ($param->enum !== null) {
                $schema['enum'] = array_values($param->enum);
            }
            if ($param->default !== null) {
                $schema['default'] = $param->default;
            }
            if ($param->minimum !== null) {
                $schema['minimum'] = $param->minimum;
            }
            if ($param->maximum !== null) {
                $schema['maximum'] = $param->maximum;
            }
            if ($param->items !== null) {
                $schema['items'] = $this->renderSchemaFragment($param->items, $componentsSchemas);
            }
            if ($param->example !== null) {
                $schema['example'] = $param->example;
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
            ? $this->renderSchemaFragment($body->schema, $componentsSchemas)
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
     * Assemble the responses map (M8b: success + supplements → standard errors → Route::errors).
     *
     *  1. the operation's resolved responses (success + supplementary declared #[ApiResponse]s);
     *  2. the standard error policy (400/401/403/429/500), each $ref-ing the shared Error component — a status
     *     already present (declared) wins and is never duplicated;
     *  3. `Route::errors` codes: a code already present keeps its schema (description overridden when given); a
     *     new code is added with the Error $ref. A code already filled by a declared response with a NON-Error
     *     schema is a structural ERROR (same status, different schema — §5/§6);
     *  4. ksort SORT_STRING ⇒ numeric statuses ordered.
     *
     * No `default` is synthesized from the method body (the fix-pass removed AST error inference): non-standard
     * error responses come only from StandardErrorPolicy, `Route::errors`, and explicit #[ApiResponse]s.
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

        foreach ($this->errors->responsesFor($operation) as $status => $description) {
            $key = (string) $status;
            if (array_key_exists($key, $responses)) {
                continue; // a declared #[Response] for this status wins.
            }
            $responses[$key] = $this->errorResponse($description);
        }

        // Route::errors: override description or add an Error-schema response.
        foreach ($operation->routeErrors as [$code, $description]) {
            $key = (string) $code;
            if (array_key_exists($key, $responses)) {
                if (!$this->isErrorOrDefaultResponse($responses[$key])) {
                    // A declared non-Error schema already occupies this status — §6 conflict.
                    $this->diagnostics->error(
                        controller: $operation->controller,
                        method: $operation->method,
                        dto: null,
                        field: 'errors',
                        cause: sprintf('#[Route(errors: …)] claims status %d, but a declared #[Response] already fills it with a different schema', $code),
                        fix: 'drop the conflicting #[Route(errors)] entry or the declared #[Response] for this status',
                    );
                    continue;
                }
                if ($description !== null && $description !== '') {
                    $responses[$key]['description'] = $description;
                }
                continue;
            }
            $responses[$key] = $this->errorResponse($description ?? sprintf('Error %d', $code));
        }

        ksort($responses, SORT_STRING);
        return $responses;
    }

    /**
     * A standard error response object: description + the shared Error envelope under application/json.
     *
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => ['schema' => ['$ref' => $this->refTo($this->errors::ERROR_SCHEMA_NAME)]],
            ],
        ];
    }

    /**
     * Whether a rendered response is the Error envelope (or an empty body) — i.e. compatible with a
     * `Route::errors` claim at the same status. A response carrying any OTHER schema is a §6 conflict.
     *
     * @param array<string, mixed> $response
     */
    private function isErrorOrDefaultResponse(array $response): bool
    {
        $schema = $response['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return true; // empty body — no conflicting schema.
        }
        return ($schema['$ref'] ?? null) === $this->refTo($this->errors::ERROR_SCHEMA_NAME);
    }

    /**
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function buildResponse(ResponseMetadata $response, array &$componentsSchemas): array
    {
        $out = ['description' => $response->description !== '' ? $response->description : 'OK'];
        $bodySchema = $this->responseBodySchema($response, $componentsSchemas);
        if ($bodySchema !== null) {
            $out['content'] = [
                $response->contentType => ['schema' => $bodySchema],
            ];
        }
        if ($response->headers !== []) {
            $out['headers'] = $response->headers;
        }
        return $out;
    }

    /**
     * The response body schema fragment: a union (oneOf/anyOf), an inline/object/scalar `schema`, an array
     * `arrayItem`, or null for an empty body. The four are mutually exclusive on a single ResponseMetadata.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return ?array<string, mixed>
     */
    private function responseBodySchema(ResponseMetadata $response, array &$componentsSchemas): ?array
    {
        if ($response->oneOf !== null) {
            return ['oneOf' => array_map(
                fn (SchemaMetadata $member): array => $this->renderSchemaFragment($member, $componentsSchemas),
                $response->oneOf,
            )];
        }
        if ($response->anyOf !== null) {
            return ['anyOf' => array_map(
                fn (SchemaMetadata $member): array => $this->renderSchemaFragment($member, $componentsSchemas),
                $response->anyOf,
            )];
        }
        if ($response->schema !== null) {
            return $this->renderSchemaFragment($response->schema, $componentsSchemas);
        }
        if ($response->arrayItem !== null) {
            return [
                'type' => 'array',
                'items' => $this->renderSchemaFragment($response->arrayItem, $componentsSchemas),
            ];
        }
        return null;
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
     * Render a {@see SchemaMetadata} fragment — recursively. A backed-enum ⇒ an inline enum schema; a DTO class
     * ⇒ a `$ref` (collected into components); an array ⇒ `{type: array, items: …}`; an inline object (or a typed
     * map / additionalProperties:false) ⇒ `{type: object, properties: …, additionalProperties: …}`; a scalar ⇒
     * `{type: …, format: …}`. Each branch then folds its own facets (format/enum/example/default/constraints/
     * description/nullability) via {@see withSchemaFacets} so a nested array item, map value, or union member
     * keeps EVERY facet the baseline records — D3: the old renderer collapsed an inline item to a bare scalar type
     * and dropped additionalProperties / description / default / item facets entirely.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function renderSchemaFragment(SchemaMetadata $schema, array &$componentsSchemas): array
    {
        if ($schema->isEnum) {
            $inline = ['type' => $schema->enumType ?? 'string'];
            if ($schema->enumCases !== null) {
                $inline['enum'] = array_values($schema->enumCases);
            }
            return $this->withSchemaFacets($inline, $schema);
        }

        // An array fragment (declared `{type: array, items: …}`, or carrying an items fragment). The item is a
        // FULL recursive fragment, so `{items: {type: string, example: active}}` survives intact.
        if ($schema->type === 'array' || $schema->items !== null) {
            $out = ['type' => 'array'];
            if ($schema->items !== null) {
                $out['items'] = $this->renderSchemaFragment($schema->items, $componentsSchemas);
            }
            return $this->withSchemaFacets($out, $schema);
        }

        // A referenced DTO object (built, so non-empty) — render the component `$ref`.
        if ($schema->className !== null && !$schema->isEmpty()) {
            $name = $this->resolver->resolve($schema->className);
            $this->collectObjectSchema($schema->className, $schema, $componentsSchemas);
            return ['$ref' => $this->refTo($name)];
        }

        // An inline object (properties) and/or a typed map (additionalProperties) / explicit false.
        if ($schema->properties !== [] || $schema->additionalProperties !== null || $schema->additionalPropertiesFalse) {
            return $this->renderInlineObject($schema, $componentsSchemas);
        }

        // A scalar fragment (incl. `{type: 'null'}` and a DateTime `{type: string, format: date-time}`).
        if ($schema->type !== null) {
            $out = ['type' => $schema->type];
            if ($schema->format !== null) {
                $out['format'] = $schema->format;
            }
            return $this->withSchemaFacets($out, $schema);
        }

        // Empty object fragment (no properties, no type) — render as a bare object.
        return ['type' => 'object'];
    }

    /**
     * Fold the facet fields of a {@see SchemaMetadata} onto an already-rendered fragment: enum, example, default
     * (incl. explicit null), bounds, description, and OpenAPI-3.1 nullability. Distinct from
     * {@see withConstraints} (which folds a {@see PropertyMetadata}'s facets) — a fragment is anonymous.
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private function withSchemaFacets(array $out, SchemaMetadata $schema): array
    {
        if ($schema->format !== null && !isset($out['format'])) {
            $out['format'] = $schema->format;
        }
        if ($schema->enum !== null) {
            $out['enum'] = array_values($schema->enum);
        }
        if ($schema->example !== null) {
            $out['example'] = $schema->example;
        }
        if ($schema->hasDefault) {
            $out['default'] = $schema->defaultValue;
        }
        if ($schema->minimum !== null) {
            $out['minimum'] = $schema->minimum;
        }
        if ($schema->maximum !== null) {
            $out['maximum'] = $schema->maximum;
        }
        if ($schema->minLength !== null) {
            $out['minLength'] = $schema->minLength;
        }
        if ($schema->maxLength !== null) {
            $out['maxLength'] = $schema->maxLength;
        }
        if ($schema->description !== '') {
            $out['description'] = $schema->description;
        }
        return $this->applyNullability($out, $schema->nullable);
    }

    /**
     * Render an inline (className-less) object schema: {type: object, properties: …, required: …}. Each property
     * goes through {@see renderProperty}, so nested inline objects / arrays-of-inline-objects recurse correctly.
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function renderInlineObject(SchemaMetadata $schema, array &$componentsSchemas): array
    {
        $properties = [];
        $required = [];
        foreach ($schema->properties as $property) {
            $properties[$property->serialName()] = $this->renderProperty($property, $componentsSchemas);
            if ($property->required) {
                $required[] = $property->serialName();
            }
        }
        $out = ['type' => 'object'];
        if ($properties !== []) {
            $out['properties'] = $properties;
        }
        if ($required !== []) {
            $out['required'] = $required;
        }
        // A typed map value — an object whose values share one schema (e.g. `rules: {<id>: [string]}`),
        // rendered recursively so the value keeps its facets. An explicit `additionalProperties: false`
        // forbids extra keys verbatim. D3: previously lost (the engine rendered a bare {type: object}).
        if ($schema->additionalProperties !== null) {
            $out['additionalProperties'] = $this->renderSchemaFragment($schema->additionalProperties, $componentsSchemas);
        } elseif ($schema->additionalPropertiesFalse) {
            $out['additionalProperties'] = false;
        }
        if ($schema->description !== '') {
            $out['description'] = $schema->description;
        }
        return $out;
    }

    /**
     * Register an object DTO schema under its resolved component name, rendering its properties and collecting
     * nested refs. CYCLE-SAFE: the FQCN is marked collected BEFORE the property walk, so a cyclic ref
     * (A→B→A) re-enters, sees itself collected, and stops — the outer call fills the real slot afterward. Only
     * the OWNER of a name (first registrant) fills the slot; a colliding loser skips it (fatal already recorded).
     *
     * @param array<string, array<string, mixed>> $componentsSchemas
     */
    private function collectObjectSchema(string $fqcn, SchemaMetadata $schema, array &$componentsSchemas): void
    {
        if (isset($this->collectedFqcn[$fqcn])) {
            return; // already collected or mid-render (cycle) — the slot exists or will be filled by the owner.
        }
        $name = $this->resolver->resolve($fqcn);
        if ($this->resolver->ownerOf($name) !== $fqcn) {
            // Collision loser — the name is owned by another FQCN; the fatal diagnostic already blocks publication.
            return;
        }
        $this->collectedFqcn[$fqcn] = true;

        // Pre-register a placeholder so a self-referential (or mutually-recursive) property walk terminates.
        $componentsSchemas[$name] = ['type' => 'object', 'properties' => new \stdClass()];

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
        if ($schema->title !== null && $schema->title !== '') {
            $component['title'] = $schema->title;
        }
        $componentsSchemas[$name] = $component;
    }

    /**
     * @param array<string, array<string, mixed>> $componentsSchemas
     * @return array<string, mixed>
     */
    private function renderProperty(PropertyMetadata $property, array &$componentsSchemas): array
    {
        // An inline response-shape nested object (a #[Response(shape: …)] property that is itself an inline
        // object). Renders before the objectMap/refClass branches.
        if ($property->inlineObject !== null) {
            return $this->withConstraints(
                $this->renderInlineObject($property->inlineObject, $componentsSchemas),
                $property,
            );
        }

        // A TYPED MAP property — an object whose JSON values share one schema (e.g. a `rules` map of id→[string])
        // declared via an inline-shape `additionalProperties:` facet, or an explicit `additionalProperties: false`.
        // Distinct from a free-form objectMap (no value schema). D3: previously collapsed to a bare {type: object}.
        if ($property->additionalProperties !== null || $property->additionalPropertiesFalse) {
            $schema = ['type' => 'object'];
            if ($property->additionalProperties !== null) {
                $schema['additionalProperties'] = $this->renderSchemaFragment($property->additionalProperties, $componentsSchemas);
            } else {
                $schema['additionalProperties'] = false;
            }
            return $this->withConstraints($schema, $property);
        }

        // An array property whose element is a FULL inline fragment (itemSchema) — preferred over itemType, so an
        // inline item keeps its own type/format/enum/example/default/constraints (D3: e.g.
        // `{type: array, items: {type: string, example: active}}`).
        if ($property->itemSchema !== null) {
            return $this->withConstraints(
                ['type' => 'array', 'items' => $this->renderSchemaFragment($property->itemSchema, $componentsSchemas)],
                $property,
            );
        }

        // Legacy inline-shape array element (inlineItems — a nested inline object element). Kept as a fallback
        // for any producer that still sets it; itemSchema above is the preferred path.
        if ($property->inlineItems !== null) {
            return $this->withConstraints(
                ['type' => 'array', 'items' => $this->renderInlineObject($property->inlineItems, $componentsSchemas)],
                $property,
            );
        }

        // An explicitly-declared object/map (a PHP `array` whose JSON value is an object, not a sequence).
        // objectMap is set ONLY by an explicit #[Field(objectMap: true)] or a legacy OA object declaration. When
        // the developer also supplied a resolvable `$ref`, the explicit flag confirms cardinality and the ref
        // supplies the type ⇒ render the typed single object `{$ref}`; otherwise render free-form `type: object`.
        if ($property->objectMap) {
            $schema = $property->refClass !== null
                ? $this->classRefSchema($property->refClass, $componentsSchemas)
                : ['type' => 'object'];
            return $this->withConstraints($schema, $property);
        }

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

        $name = $this->resolver->resolve($fqcn);
        $this->collectObjectSchema($fqcn, $this->schemaBuilder->build($fqcn), $componentsSchemas);
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
     * Fold constraints + OpenAPI 3.1 nullability onto a property schema fragment. Nullability is expressed via
     * JSON Schema 2020-12 — a nullable scalar unions "null" into `type`; a nullable $ref wraps in anyOf — NEVER
     * via the removed `nullable` keyword.
     *
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
        // A declared default — emitted whenever `hasDefault` is set, INCLUDING an explicit null (so
        // `default: null` survives). D3: the renderer previously never projected `default`.
        if ($property->hasDefault) {
            $schema['default'] = $property->defaultValue;
        }
        // A property description (from an inline-shape `description:` facet). D3: previously dropped.
        if ($property->description !== null && $property->description !== '') {
            $schema['description'] = $property->description;
        }
        if ($property->readOnly) {
            $schema['readOnly'] = true;
        }
        if ($property->writeOnly) {
            $schema['writeOnly'] = true;
        }
        return $this->applyNullability($schema, $property->nullable);
    }

    /**
     * OpenAPI 3.1 nullability (JSON Schema 2020-12): scalar ⇒ type:[<type>,"null"]; $ref ⇒ anyOf:[{$ref},{type:"null"}].
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyNullability(array $schema, bool $nullable): array
    {
        if (!$nullable) {
            return $schema;
        }
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }
        if (isset($schema['type'])) {
            $type = $schema['type'];
            $types = is_array($type) ? $type : [$type];
            if (!in_array('null', $types, true)) {
                $types[] = 'null';
            }
            $schema['type'] = array_values($types);
            return $schema;
        }
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    private function isClassish(string $type): bool
    {
        return !$this->isBuiltinScalar($type);
    }

    private function isBuiltinScalar(string $type): bool
    {
        return in_array($type, ['int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'number', 'array', 'object', 'mixed'], true);
    }

    private function refTo(string $name): string
    {
        return '#/components/schemas/' . $name;
    }
}
