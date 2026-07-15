<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * Collects compile-time diagnostics produced by the metadata builders (RouteMetadataCompiler,
 * OpenApiEmitter, …) and renders them into a single {@see CompileException} on demand.
 *
 * The shape of an error record is stable so that tests and tooling can assert on it:
 *  - controller : FQCN of the controller involved, or null (e.g. a DTO-level error)
 *  - method     : controller method name, or null
 *  - dto        : DTO/schema FQCN involved, or null
 *  - field      : property/parameter name, or null
 *  - cause      : human-readable description of what is wrong (always set)
 *  - fix        : suggested remediation, or null
 *
 * Step 1 (M1): only the collector + thrower exist; builders emit errors from M2 onward.
 */
final class CompileDiagnostics
{
    /** @var list<array{controller: ?string, method: ?string, dto: ?string, field: ?string, cause: string, fix: ?string}> */
    private array $errors = [];

    /**
     * Record a compile error. Argument order follows the canonical signature
     * (controller, method, dto, field, cause, fix); all but `cause` are optional.
     */
    public function error(
        string $cause,
        ?string $controller = null,
        ?string $method = null,
        ?string $dto = null,
        ?string $field = null,
        ?string $fix = null,
    ): void {
        $this->errors[] = [
            'controller' => $controller,
            'method' => $method,
            'dto' => $dto,
            'field' => $field,
            'cause' => $cause,
            'fix' => $fix,
        ];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /**
     * @return list<array{controller: ?string, method: ?string, dto: ?string, field: ?string, cause: string, fix: ?string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Multi-line render of all accumulated errors (one per line), prefixed with a 1-based index.
     */
    public function render(): string
    {
        $lines = [];
        $i = 1;
        foreach ($this->errors as $e) {
            $where = [];
            foreach (['controller', 'method', 'dto', 'field'] as $key) {
                if ($e[$key] !== null) {
                    $where[] = $key . '=' . $e[$key];
                }
            }
            $where = $where === [] ? '(no location)' : implode(', ', $where);
            $line = sprintf('[%d] %s :: %s', $i++, $where, $e['cause']);
            if ($e['fix'] !== null) {
                $line .= ' (fix: ' . $e['fix'] . ')';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Throw a {@see CompileException} carrying the rendered report iff at least one error was recorded.
     * The exception code is the number of errors, for easy assertion.
     */
    public function throwOnErrors(): void
    {
        if ($this->errors === []) {
            return;
        }
        throw new CompileException(
            $this->count() . " compile error(s):\n" . $this->render(),
            $this->count(),
        );
    }
}
