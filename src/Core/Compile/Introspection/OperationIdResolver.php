<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use SpsFW\Core\Compile\CompileDiagnostics;

/**
 * Assigns each operation a stable OpenAPI operationId and asserts global uniqueness (plan §7, §19, Step 2).
 *
 * Resolution priority (highest first):
 *   1. explicit — the `id` of a future `#[Operation(id:)]` (Step 3); wins outright, verbatim, even when the
 *                lockfile carries a null for that signature.
 *   2. lockfile  — a tri-state "<controller>::<method>" => ?string map. `array_key_exists` is the test
 *                  (NOT `??`): a key present with a non-null string is the preserved id (the 39 legacy ids);
 *                  a key present with null means "this legacy op stays id-less" (the 306 deferred ops, so
 *                  the typed client P is not perturbed). The map is the materialized operationId inventory.
 *   3. convention — "<ControllerShort><Method>" (ControllerShort = short class name minus a trailing
 *                  "Controller"); the collision-free form assigned only when the signature is ABSENT from
 *                  the lockfile — i.e. to NEW route-only operations.
 *
 * `controller::method` is a sound key: all 377 signatures in the current inventory are unique.
 *
 * Pre-M9 operationId policy (plan §19, fixed): the lockfile carries the materialized inventory — 39 non-null
 * ids + 306 nulls (+ 22 new route-only ids once discovered). The 306 nulls are replaced by the controller-
 * qualified convention only in the coordinated M9 (client regen). Only NON-NULL resolved ids participate in
 * the uniqueness check; a null is "deliberately id-less", not an assignment.
 *
 * Collisions among resolved (non-null) ids — e.g. two controllers that collapse to the same short name
 * sharing a method — are collected and reported in bulk via {@see assertUnique()} into the shared
 * {@see CompileDiagnostics}, which the Coordinator turns into a compile-halting error.
 */
final class OperationIdResolver
{
    private const CONTROLLER_SUFFIX = 'Controller';

    /** @var array<string, ?string> "<controller>::<method>" => operationId|null (tri-state: id / null / absent) */
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
     * Resolve the operationId for a controller::method AND record it for the uniqueness check.
     *
     * Priority: explicit > lockfile (array_key_exists, value may be null) > convention.
     *
     * This is the recording variant (BC): it feeds {@see assertUnique()}. The unified endpoint flow instead
     * resolves ids for every discovered operation but records ONLY the effective (non-shadowed) ones, so a
     * shadowed override never participates in the collision check — see {@see resolveId()} + {@see record()}.
     *
     * @param string $controller FQCN of the controller
     * @param string $method controller method name
     * @param ?string $explicit an explicit override (#[Operation(id:)] in Step 3); '' is treated as absent
     * @return ?string the resolved id, or null when the lockfile carries a null for this signature (deferred op)
     */
    public function resolve(
        string $controller,
        string $method,
        ?string $explicit = null,
    ): ?string {
        $id = $this->resolveId($controller, $method, $explicit);
        $this->record($id, $controller, $method);
        return $id;
    }

    /**
     * PURE resolution: compute the operationId by priority WITHOUT recording it. Lets the caller decide which
     * operations feed the uniqueness check (effective vs shadowed).
     *
     * @param string $controller FQCN of the controller
     * @param string $method controller method name
     * @param ?string $explicit an explicit override; '' is treated as absent
     * @return ?string the resolved id, or null when the lockfile carries a null for this signature (deferred op)
     */
    public function resolveId(
        string $controller,
        string $method,
        ?string $explicit = null,
    ): ?string {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }
        $signature = $controller . '::' . $method;
        // array_key_exists (NOT ??): a present-but-null entry means "keep id-less" and must NOT fall through to
        // convention; only an ABSENT key is new and gets the controller-qualified id.
        return array_key_exists($signature, $this->lockfile)
            ? $this->lockfile[$signature]
            : $this->convention($controller, $method);
    }

    /**
     * Record a resolved id ⇒ source for the uniqueness check. A null id (a deferred, id-less op) is NOT tracked.
     */
    public function record(?string $id, string $controller, string $method): void
    {
        if ($id !== null) {
            $this->assignments[$id][] = $controller . '::' . $method;
        }
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
