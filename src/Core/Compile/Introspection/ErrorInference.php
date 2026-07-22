<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

/**
 * The statically-inferred ERROR status codes of a controller action, from AST analysis of
 * `Response::error(...)` / `Response::errorMessage(...)` calls (M8b).
 *
 *  - literalStatuses : distinct HTTP status codes passed as LITERAL arguments (e.g.
 *                      `Response::error(..., statusCode: 404)`). Each becomes a documented error
 *                      response (the standard `Error` schema), supplementing the auto-derived
 *                      400/401/403/429/500 set.
 *  - hasDynamic      : a `Response::error(...)` / `Response::errorMessage(...)` carries a
 *                      NON-literal (computed) status ⇒ the exact code is not statically knowable,
 *                      so the emitter adds an OpenAPI `default` error response (Error schema)
 *                      instead of forcing every Throwable to be marked. A bare `throw` does NOT
 *                      set this — it is covered by the always-present 500.
 *
 * Pure value object consumed by {@see \SpsFW\Core\Compile\Route\RouteMetadataCompiler}.
 *
 * @param list<int> $literalStatuses
 */
final readonly class ErrorInference
{
    public function __construct(
        public array $literalStatuses = [],
        public bool $hasDynamic = false,
    ) {
    }
}
