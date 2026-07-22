<?php

namespace SpsFW\Core\Attributes;

use Attribute;
use SpsFW\Core\Http\HttpMethod;


#[Attribute(Attribute::TARGET_METHOD)]
class Route {
    /**
     * Устанавливается над методом контроллера,
     * показывая по какому пути и Http методу вызывать его.
     *
     * The first two params (`path`, `httpMethods`) are POSITIONAL and unchanged — existing
     * `#[Route('/path', ['POST'])]` keeps working byte-for-byte. The remaining params are
     * OPTIONAL compile-time OpenAPI metadata and SHOULD be passed as NAMED arguments. The
     * runtime Router reads only {@see getPath()} / {@see getHttpMethods()}; the metadata
     * fields are doc-only (M8b: Route is the canonical operation source).
     *
     * @param string $path
     * @param HttpMethod[] $httpMethods
     * @param string|null $summary short human summary (the one field worth writing by hand)
     * @param string|null $description longer description
     * @param string|null $operationId explicit operationId override (otherwise lockfile/convention-derived)
     * @param list<string> $tags OpenAPI tags; default derived from the controller short name
     * @param bool $deprecated
     * @param bool $documented false ⇒ hide the operation from the spec (replaces #[Operation(exclude: true)])
     * @param string|array|null $returns the success body declaration. `null` ⇒ infer it
     *   (native return type → Response::json() AST → diagnostic). Otherwise:
     *     - `Dto::class`        ⇒ one object;
     *     - `[Dto::class]`      ⇒ a list of that DTO;
     *     - `'string'|'integer'|'number'|'boolean'|'object'` ⇒ a scalar/object body;
     *     - `['string']` etc.   ⇒ a list of that scalar.
     *   Forbidden (compile ERROR): `[]`, an array with >1 element, nested arrays, an unknown
     *   scalar type, a body at successStatus 204, or a nonexistent/unfit schema class. There
     *   is NO `returnsMany` — cardinality is expressed by wrapping the element in `[]`.
     * @param int $successStatus success HTTP status (default 200; use 201/202/204 where appropriate)
     * @param array $errors non-standard error codes or description overrides. STRICT list-or-map only:
     *     - `errors: [404, 409]`                     (list of codes)
     *     - `errors: [404 => 'Не найдено', 409 => …]` (code ⇒ description)
     *   A mixed list/map form is rejected; only HTTP 400–599. Standard 400/401/403/429/500 are
     *   auto-derived by the error policy — repeating one here overrides its description.
     */
    public function __construct(
        private string $path = '',
        private array $httpMethods = [HttpMethod::GET],
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ?string $operationId = null,
        public readonly array $tags = [],
        public readonly bool $deprecated = false,
        public readonly bool $documented = true,
        public readonly string|array|null $returns = null,
        public readonly int $successStatus = 200,
        public readonly array $errors = [],
    ) {}

    /**
     * @return array
     */
    public function getHttpMethods(): array
    {
        return $this->httpMethods;
    }

    public function getPath(): string {
        return $this->path;
    }
}
