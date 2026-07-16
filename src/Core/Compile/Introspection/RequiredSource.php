<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

/**
 * Where a property/parameter's `required` flag is sourced from (plan §7 — required is NOT mixed across phases).
 *
 *  - Oa    : the PARITY source. `required` comes from the legacy `#[OA\Property(required: [true])]` argument,
 *            exactly what `Router::extractValidationRules` replays and what the runtime Validator checks
 *            (`$rules['required'] !== [true]`). This is the active mode until the OA source is removed (M8).
 *  - PhpType: the POST-OA source. `required` is derived from the PHP type (non-nullable, no default). Enabled
 *            only in a separate source-mode after the OA cleanup — never blended with Oa mid-parity.
 *
 * PropertyMetadata carries BOTH signals (oaRequired + nullable/hasDefault); the active mode selects which one
 * a projection reads. Default is {@see Oa} (parity), so the rule graph and the doc projections stay consistent
 * with today's runtime contract.
 */
enum RequiredSource
{
    case Oa;
    case PhpType;
}
