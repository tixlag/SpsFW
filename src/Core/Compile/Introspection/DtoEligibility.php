<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

/**
 * Whether a referenced class is auto-derivable as a response/request schema (plan §6 — the eligibility gate).
 *
 * A bare `class` return type is a schema source ONLY when it satisfies ONE of:
 *   - lives in a `\Dto\` namespace segment (e.g. `App\Dto\UserDto`, `Dto\Foo`);
 *   - its short name ends with the `Dto` suffix (case-insensitive) — the framework convention (325 classes
 *     in `next`);
 *   - it is a PHP enum (`UnitEnum`) or a `DateTimeInterface`;
 *   - it is in the application whitelist (FQCN match).
 *
 * Domain entities, {@see \SpsFW\Core\Http\Response}, and other framework/base types are NOT eligible: their
 * JSON shape cannot be inferred from public properties alone, so they require an explicit `#[Response]`
 * (or the OA escape hatch). Eligibility is checked only on AUTO-inferred returns; an explicit `#[Response]`
 * bypasses it (the developer declares the contract).
 *
 * The whitelist and namespace markers are injectable so the framework stays clean-checkout-independent while
 * an application can register its own DTO roots. Targeted, not a generic plugin surface (plan: no over-engineering).
 */
final readonly class DtoEligibility
{
    /**
     * @param list<string> $whitelist FQCNs always treated as eligible
     * @param list<string> $namespaceMarkers namespace segments marking a DTO root (default: a `\Dto\` segment)
     */
    public function __construct(
        private array $whitelist = [],
        private array $namespaceMarkers = ['\\Dto\\'],
    ) {
    }

    public function isEligible(string $fqcn): bool
    {
        if ($fqcn === '') {
            return false;
        }
        if (in_array($fqcn, $this->whitelist, true)) {
            return true;
        }
        if (is_subclass_of($fqcn, \UnitEnum::class) || is_a($fqcn, \DateTimeInterface::class, true)) {
            return true;
        }
        $prefixed = '\\' . $fqcn;
        foreach ($this->namespaceMarkers as $marker) {
            if (str_contains($prefixed, $marker)) {
                return true;
            }
        }
        $pos = strrpos($fqcn, '\\');
        $short = $pos === false ? $fqcn : substr($fqcn, $pos + 1);
        return str_ends_with(strtolower($short), 'dto');
    }
}
