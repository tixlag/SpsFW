<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Compile\CompileDiagnostics;

/**
 * The single source of truth for mapping a DTO FQCN to its OpenAPI component name (Step 4 correctness fix).
 *
 * Every schema reference the {@see OpenApiEmitter} renders — root response/request bodies, nested property
 * refs, array items — goes through {@see resolve()}, so naming is consistent and, crucially, COLLISIONS are
 * detected in ONE place: two different FQCNs that collapse to the same component name produce an ambiguous
 * `$ref` target and surface a FATAL structural diagnostic.
 *
 * The component name is, in priority order:
 *  1. an explicit class-level `#[Field(schema: 'Name')]` override on the DTO (the disambiguation lever for
 *     short-name collisions — e.g. two `CreateNewsDto` classes in different namespaces);
 *  2. otherwise the short class name (last namespace segment).
 *
 * The FQCN⇒name registry lives HERE, inside the compile engine — it is NOT published into the spec (no
 * `x-fqcn` vendor extension in the final document). Tooling/probes that need the mapping read it via
 * {@see componentRegistry()}.
 */
final class SchemaNameResolver
{
    /** @var array<string, string> FQCN ⇒ component name (the registered mappings) */
    private array $byFqcn = [];

    /** @var array<string, string> component name ⇒ owning FQCN (collision detection) */
    private array $ownerByName = [];

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
    ) {
    }

    /**
     * The component name for a DTO class, registering the mapping (and collision-checking) on first sight.
     * On a collision the FIRST registrant keeps the name; the loser is not re-mapped (its refs resolve to the
     * winner's component) — the FATAL diagnostic already blocks publication, so the wrong-but-non-crashing ref
     * never ships.
     */
    public function resolve(string $fqcn): string
    {
        if (isset($this->byFqcn[$fqcn])) {
            return $this->byFqcn[$fqcn];
        }

        $name = $this->declaredName($fqcn) ?? $this->shortName($fqcn);
        $this->register($fqcn, $name);
        return $this->byFqcn[$fqcn] ?? $name;
    }

    /**
     * The FQCN that owns a component name (the first registrant), or null if the name is not yet registered.
     * The emitter uses this to decide whether IT may fill a component slot (only the owner writes it).
     */
    public function ownerOf(string $name): ?string
    {
        return $this->ownerByName[$name] ?? null;
    }

    /**
     * The full FQCN⇒name registry (for tooling/probes; never published into the spec).
     *
     * @return array<string, string>
     */
    public function componentRegistry(): array
    {
        return $this->byFqcn;
    }

    private function register(string $fqcn, string $name): void
    {
        $existing = $this->ownerByName[$name] ?? null;
        if ($existing === $fqcn) {
            return;
        }
        if ($existing !== null) {
            // Two different FQCNs collapse to the same short name ⇒ ambiguous $ref target. First registrant
            // keeps the slot; the collision is fatal and blocks publication, so the loser never ships.
            $this->diagnostics->error(
                controller: null,
                method: null,
                dto: $fqcn,
                field: 'schema',
                cause: sprintf(
                    'schema name collision: %s and %s both map to components.schemas.%s',
                    $existing,
                    $fqcn,
                    $name,
                ),
                fix: 'rename one class, or set an explicit component name via a class-level #[Field(schema: …)]',
            );
            return;
        }
        $this->byFqcn[$fqcn] = $name;
        $this->ownerByName[$name] = $fqcn;
    }

    /**
     * A class-level `#[Field(schema: …)]` override on the DTO, if declared.
     */
    private function declaredName(string $fqcn): ?string
    {
        if (!class_exists($fqcn)) {
            return null;
        }
        foreach ((new \ReflectionClass($fqcn))->getAttributes(Field::class) as $attribute) {
            try {
                $field = $attribute->newInstance();
            } catch (\Throwable) {
                continue;
            }
            if ($field->schema !== null && $field->schema !== '') {
                return $field->schema;
            }
        }
        return null;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
