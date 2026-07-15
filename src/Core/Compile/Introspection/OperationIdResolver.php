<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use SpsFW\Core\Compile\CompileDiagnostics;

/**
 * Assigns each operation a stable OpenAPI operationId and asserts global uniqueness (plan §7, §19, Step 2).
 *
 * Resolution priority (highest first):
 *   1. explicit — the `id` of a future `#[Operation(id:)]` (Step 3); wins outright, verbatim.
 *   2. lockfile  — a provisionally-keyed "<controller>::<method>" => id map (the 39 preserved legacy ids);
 *                  the canonical keying is settled at M9 alongside the typed client regen.
 *   3. convention — "<ControllerShort><Method>" (ControllerShort = short class name minus a trailing
 *                  "Controller"); this is the collision-free form assigned to NEW route-only operations.
 *
 * Pre-M9 operationId policy (plan §19, fixed): the 306 legacy operations without an explicit id keep
 * operationId = null (caller passes deferred = true) so the typed client (P) is not perturbed; the
 * controller-qualified convention for them is introduced only in the coordinated M9. Deferred operations
 * are NOT tracked for uniqueness (a null id is "not yet decided", not a real assignment).
 *
 * Collisions among resolved (non-null) ids — e.g. two controllers that collapse to the same short name
 * sharing a method — are collected and reported in bulk via {@see assertUnique()} into the shared
 * {@see CompileDiagnostics}, which the Coordinator turns into a compile-halting error.
 */
final class OperationIdResolver
{
    private const CONTROLLER_SUFFIX = 'Controller';

    /** @var array<string, string> "<controller>::<method>" => explicit operationId */
    private readonly array $lockfile;

    /** @var array<string, list<string>> resolved id => list of "<controller>::<method>" sources */
    private array $assignments = [];

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
        array $lockfile = [],
    ) {
        $this->lockfile = $lockfile;
    }

    /**
     * The short identifier for a controller FQCN: namespace stripped, trailing "Controller" removed.
     */
    public function controllerShort(string $controller): string
    {
        $pos = strrpos($controller, '\\');
        $short = $pos === false ? $controller : substr($controller, $pos + 1);
        if (str_ends_with($short, self::CONTROLLER_SUFFIX)) {
            $short = substr($short, 0, -strlen(self::CONTROLLER_SUFFIX));
        }
        return $short;
    }

    /**
     * The convention operationId for a NEW operation: "<ControllerShort>" + ucfirst(method).
     */
    public function convention(string $controller, string $method): string
    {
        return $this->controllerShort($controller) . ucfirst($method);
    }

    /**
     * Resolve the operationId for a controller::method.
     *
     * @param string $controller FQCN of the controller
     * @param string $method controller method name
     * @param ?string $explicit an explicit override (#[Operation(id:)] in Step 3); '' is treated as absent
     * @param bool $deferred pre-M9 legacy op: keep null (canonical id deferred to M9); not uniqueness-tracked
     */
    public function resolve(
        string $controller,
        string $method,
        ?string $explicit = null,
        bool $deferred = false,
    ): ?string {
        if ($deferred) {
            return null;
        }

        if ($explicit !== null && $explicit !== '') {
            $id = $explicit;
        } else {
            $signature = $controller . '::' . $method;
            $id = $this->lockfile[$signature] ?? $this->convention($controller, $method);
        }

        $this->assignments[$id][] = $controller . '::' . $method;
        return $id;
    }

    /**
     * Report every operationId assigned to more than one operation as a compile error.
     * Called once after all operations are resolved, so all collisions surface together.
     */
    public function assertUnique(): void
    {
        foreach ($this->assignments as $id => $sources) {
            if (count($sources) < 2) {
                continue;
            }
            // Attribute the collision to each colliding operation; the cause names the shared id + the others.
            foreach ($sources as $source) {
                [$controller, $method] = explode('::', $source, 2);
                $others = array_values(array_filter($sources, static fn (string $s): bool => $s !== $source));
                $this->diagnostics->error(
                    controller: $controller,
                    method: $method,
                    dto: null,
                    field: 'operationId',
                    cause: sprintf(
                        'operationId "%s" is assigned to %d operations: %s',
                        $id,
                        count($sources),
                        implode(', ', $sources),
                    ),
                    fix: 'set an explicit operationId (#[Operation(id:)]) or rename one of: ' . implode(', ', $others),
                );
            }
        }
    }
}
