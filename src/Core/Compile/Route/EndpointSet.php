<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Route;

use SpsFW\Core\Compile\Metadata\OperationMetadata;
use SpsFW\Core\Compile\Metadata\RouteRuntimeMetadata;

/**
 * The EFFECTIVE endpoint set produced by ONE discovery/reflection flow (plan §11.1, Step 5 fix-pass).
 *
 * Both outputs come from the same pass:
 *  - {@see $routes}      — the runtime route IR (RouteCacheEmitter serializes this to compiled_routes.php);
 *  - {@see $operations}  — the OpenAPI operation projection (OpenApiEmitter serializes this).
 *
 * "Effective" means duplicate METHOD:path keys have been RESOLVED:
 *  - a key declared in the compile-time routeOverrideMap keeps its declared WINNER and SHADOWS the rest — the
 *    shadowed operations are excluded from {@see $operations} (so they neither reach OpenAPI nor feed the
 *    operationId uniqueness check), and the shadowed routes are excluded from {@see $routes};
 *  - a duplicate key with NO override is a genuine collision — it is reported as a structural ERROR and collapses
 *    last-wins here (parity with Router), but publication is blocked by the diagnostic regardless.
 *
 * {@see $overrides} records every override actually applied (for the manifest / observability): the key, the
 * winning `controller::method`, and the shadowed `controller::method` list. The winner is chosen by the map —
 * INDEPENDENT of discovery order.
 */
final readonly class EndpointSet
{
    /**
     * @param list<RouteRuntimeMetadata> $routes effective (winners only; shadowed excluded)
     * @param list<OperationMetadata> $operations effective (winners only; shadowed excluded)
     * @param list<array{key: string, winner: string, shadowed: list<string>}> $overrides applied overrides
     *        (winner/shadowed are "controller::method")
     */
    public function __construct(
        public array $routes,
        public array $operations,
        public array $overrides,
    ) {
    }
}
