<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * Collects compile-time diagnostics produced by the metadata builders (RouteMetadataCompiler,
 * OpenApiEmitter, …) and renders them into a single {@see CompileException} on demand.
 *
 * Diagnostics carry a SEVERITY (Step 4):
 *
 *  - ERROR   (fatal, structural): the build cannot produce a sound artifact in ANY mode — a duplicate
 *             METHOD:path collapsing two operations into one, an operationId collision, a cyclic validation
 *             graph, an unresolvable class reference, a malformed #[Items]. {@see throwOnErrors()} halts on
 *             these unconditionally.
 *  - WARNING (migration gap): the artifact is still GENERATABLE, just incomplete — a response whose schema
 *             cannot be inferred (missing return type / non-eligible entity / itemless array / …), a path
 *             param with no matching signature arg, a collection response without an item schema. These are
 *             the M7 migration backlog. They do NOT block generation in parity mode (the spec is emitted with
 *             an opaque/empty response for the gap); in strict/managed mode {@see throwOnErrorsAndWarnings()}
 *             promotes them to fatal.
 *
 * The shape of a record is stable so that tests and tooling can assert on it:
 *  - severity : 'error' | 'warning'
 *  - controller: FQCN of the controller involved, or null (e.g. a DTO-level diagnostic)
 *  - method    : controller method name, or null
 *  - dto       : DTO/schema FQCN involved, or null
 *  - field     : property/parameter name, or null
 *  - cause     : human-readable description of what is wrong (always set)
 *  - fix       : suggested remediation, or null
 */
final class CompileDiagnostics
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /** @var list<array{severity: string, controller: ?string, method: ?string, dto: ?string, field: ?string, cause: string, fix: ?string}> */
    private array $records = [];

    /** @var array<string, true> dedup signatures (severity + location + cause) already recorded */
    private array $seen = [];

    /**
     * Record a FATAL (structural) compile error. Argument order is the fixed plan contract
     * error(controller, method, dto, field, cause, fix); only `cause` is required.
     */
    public function error(
        ?string $controller,
        ?string $method,
        ?string $dto,
        ?string $field,
        string $cause,
        ?string $fix = null,
    ): void {
        $this->record(self::SEVERITY_ERROR, $controller, $method, $dto, $field, $cause, $fix);
    }

    /**
     * Record a MIGRATION WARNING (generation gap). Same fixed argument contract as {@see error()}.
     */
    public function warning(
        ?string $controller,
        ?string $method,
        ?string $dto,
        ?string $field,
        string $cause,
        ?string $fix = null,
    ): void {
        $this->record(self::SEVERITY_WARNING, $controller, $method, $dto, $field, $cause, $fix);
    }

    private function record(
        string $severity,
        ?string $controller,
        ?string $method,
        ?string $dto,
        ?string $field,
        string $cause,
        ?string $fix,
    ): void {
        // Idempotent accumulation: the same diagnostic produced twice (e.g. when emit() runs once and the
        // serialized form is then dumped/written from the already-built array — or, defensively, if a caller
        // invokes emit() twice) is recorded ONCE. The signature is severity + full location + cause; `fix` is
        // advisory and intentionally not part of it.
        $signature = $severity . "\x1f" . ($controller ?? '') . "\x1f" . ($method ?? '') . "\x1f"
            . ($dto ?? '') . "\x1f" . ($field ?? '') . "\x1f" . $cause;
        if (isset($this->seen[$signature])) {
            return;
        }
        $this->seen[$signature] = true;
        $this->records[] = [
            'severity' => $severity,
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
        return $this->errorCount() > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->warningCount() > 0;
    }

    public function errorCount(): int
    {
        $n = 0;
        foreach ($this->records as $r) {
            if ($r['severity'] === self::SEVERITY_ERROR) {
                $n++;
            }
        }
        return $n;
    }

    public function warningCount(): int
    {
        $n = 0;
        foreach ($this->records as $r) {
            if ($r['severity'] === self::SEVERITY_WARNING) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Total diagnostic count (errors + warnings). Kept as count() for callers that ask "how many diagnostics".
     */
    public function count(): int
    {
        return count($this->records);
    }

    /**
     * @return list<array{severity: string, controller: ?string, method: ?string, dto: ?string, field: ?string, cause: string, fix: ?string}>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * FATAL (structural) records only.
     *
     * @return list<array{severity: string, controller: ?string, method: ?string, dto: ?string, field: ?string, cause: string, fix: ?string}>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $r): bool => $r['severity'] === self::SEVERITY_ERROR,
        ));
    }

    /**
     * MIGRATION (generation-gap) records only.
     *
     * @return list<array{severity: string, controller: ?string, method: ?string, dto: ?string, field: ?string, cause: string, fix: ?string}>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $r): bool => $r['severity'] === self::SEVERITY_WARNING,
        ));
    }

    /**
     * Multi-line render of every diagnostic (errors first, then warnings), each prefixed with a 1-based index
     * and its severity tag.
     */
    public function render(): string
    {
        $lines = [];
        $i = 1;
        foreach ($this->records as $e) {
            $where = [];
            foreach (['controller', 'method', 'dto', 'field'] as $key) {
                if ($e[$key] !== null) {
                    $where[] = $key . '=' . $e[$key];
                }
            }
            $where = $where === [] ? '(no location)' : implode(', ', $where);
            $tag = $e['severity'] === self::SEVERITY_ERROR ? 'ERROR' : 'WARN';
            $line = sprintf('[%d] %s %s :: %s', $i++, $tag, $where, $e['cause']);
            if ($e['fix'] !== null) {
                $line .= ' (fix: ' . $e['fix'] . ')';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Throw a {@see CompileException} carrying the rendered report iff at least one FATAL error was recorded.
     * Warnings alone never halt here — use {@see throwOnErrorsAndWarnings()} for strict/managed mode.
     * The exception code is the number of fatal errors, for easy assertion.
     */
    public function throwOnErrors(): void
    {
        if (!$this->hasErrors()) {
            return;
        }
        throw new CompileException(
            $this->errorCount() . " compile error(s):\n" . $this->render(),
            $this->errorCount(),
        );
    }

    /**
     * Strict/managed-mode gate: halt on ANY diagnostic — fatal errors OR migration warnings. Used where the
     * compile policy demands a gap-free spec (parity mode tolerates warnings and still emits).
     */
    public function throwOnErrorsAndWarnings(): void
    {
        if ($this->records === []) {
            return;
        }
        throw new CompileException(
            $this->count() . " compile diagnostic(s) (strict mode treats warnings as fatal):\n" . $this->render(),
            $this->count(),
        );
    }
}
