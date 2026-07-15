<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * The compile-engine entry point.
 *
 * Accepts an explicit {@see ApplicationContext} and — from Step 3+ — builds the full artifact set
 * (route cache, DI map, job registry, OpenAPI) into staging and publishes it atomically per the safe
 * publication rules (plan §11). The engine does NOT own application bootstrap: it does not load env,
 * dynamic config, Config::init() or DI bindings — those are the production owner's responsibility
 * (the client `next/preload.php`, plan §11.2). It also never reaches for `new Router()` to assemble the
 * route cache: route metadata is built directly (plan §11.1).
 *
 * Step 1 (M1): additive skeleton. {@see compile()} is a no-op that returns an empty success and touches
 * NO runtime code, NO production cache, NO Router / Validator / DI flow. Builders are wired in from
 * Step 2 onward; safe publication arrives in Step 5.
 */
final class Coordinator
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CompileDiagnostics $diagnostics = new CompileDiagnostics(),
    ) {
    }

    public function context(): ApplicationContext
    {
        return $this->context;
    }

    public function diagnostics(): CompileDiagnostics
    {
        return $this->diagnostics;
    }

    /**
     * Build all metadata artifacts for the application context and (from Step 5+) publish them.
     *
     * Step 1: returns an empty success without side effects. Real compilation (RouteMetadataCompiler,
     * DtoSchemaBuilder, OpenApiEmitter, compile-only DI API) is added in Steps 2–5; nothing here changes
     * the production cache, the Router runtime, the Validator, the DI flow, or client preload.
     */
    public function compile(): CompileResult
    {
        return CompileResult::emptySuccess();
    }
}
