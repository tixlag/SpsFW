<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use SpsFW\Core\Compile\Metadata\SchemaMetadata;

/**
 * The statically-inferred success-body shape of a controller action — the shared result of native
 * return-type inference and {@see ResponseAstAnalyzer} AST inference (M8b).
 *
 *  - definite      : the shape is UNAMBIGUOUS and may be emitted without an explicit declaration;
 *                    a definite inference that CONTRADICTS `Route::returns` is a compile ERROR.
 *                    Non-definite (opaque array, mixed, JsonSerializable, ambiguous union, divergent
 *                    branches, dynamic payload) never contradicts — `returns` may override it freely.
 *  - class         : the DTO/enum FQCN of an OBJECT body (mutually exclusive with `inlineSchema`).
 *  - inlineSchema  : a scalar/enum/DateTime/Uuid inline schema fragment (the non-object body case).
 *  - collection    : the body is a LIST of `class` / `inlineSchema`.
 *  - status        : a literal success status detected statically (e.g. Response::json($x, 201),
 *                    Response::created() ⇒ 201, Response::noContent() ⇒ 204); null ⇒ 200 default.
 *  - reason        : why inference is non-definite (for diagnostics).
 *
 * Pure value object — building a {@see \SpsFW\Core\Compile\Metadata\ResponseMetadata} from it is the
 * compiler's job (it owns the schema builder). {@see signature()} is a stable identity used only to
 * compare a definite inference against an explicit `Route::returns` for the contradiction check.
 */
final readonly class SuccessInference
{
    public function __construct(
        public bool $definite = false,
        public ?string $class = null,
        public ?SchemaMetadata $inlineSchema = null,
        public bool $collection = false,
        public ?int $status = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * A stable body-shape identity: `ref:<fqcn>` / `inline:<type>:<format>` / `none`, suffixed with
     * `[]` for a collection. Two definite inferences with the same signature agree; a `Route::returns`
     * whose resolved signature differs from a definite inference's signature is a contradiction.
     */
    public function signature(): string
    {
        $base = $this->class !== null
            ? 'ref:' . $this->class
            : ($this->inlineSchema !== null
                ? 'inline:' . ($this->inlineSchema->type ?? '') . ':' . ($this->inlineSchema->format ?? '')
                : 'none');
        return $base . ($this->collection ? '[]' : '');
    }
}
