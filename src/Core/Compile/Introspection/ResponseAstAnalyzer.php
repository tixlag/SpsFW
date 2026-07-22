<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Conservative AST inference of a controller action's SUCCESS body (M8b + fix-pass).
 *
 * Uses nikic/php-parser v5 (a direct compile dependency). Each controller file is parsed AND name-resolved
 * ONCE, the resolved AST memoized by absolute path — no runtime overhead beyond compile. Parse failure ⇒ the
 * inference is non-definite (no throw): the compiler then requires an explicit `Route::returns`.
 *
 * Name resolution uses the canonical {@see NameResolver} visitor (replaceNodes:false ⇒ a `resolvedName`
 * attribute on every `Name` node, `namespacedName` on declarations) — NOT a hand-rolled use-statement map. So
 * `Response` resolves to {@see RESPONSE_FQCN} through any alias (`use ... as ApiResponse`), and `new FooDto()`
 * resolves to its real FQCN. The analyzed `ClassMethod` is located by the method's FILE + its declaring-class
 * FQCN/trait (from reflection) + the Reflection start/end line range — never by short name alone — so two
 * classes/traits defining a same-named method never mix.
 *
 * SUCCESS inference ({@see inferSuccess()}) walks `return` statements and recognizes, via the resolved
 * `Response` class, the success producers `Response::json($data, $status=200)` / `Response::created($data)`
 * (201) / `Response::ok()` (200) / `Response::noContent()` (204). Recognized payload shapes:
 *   A. `Response::json(new Dto())`                       ⇒ Dto (single)
 *   B. `$d = new Dto(); … return Response::json($d);`    ⇒ Dto (single)
 *   C. `Response::json([new Dto(), …])` homogeneous      ⇒ [Dto]
 *   D. several success returns of the SAME Dto           ⇒ Dto
 *   E. `Response::error(...)` / `throw`                  ⇒ ignored (not a success body)
 *   F. `Response::json($this->method())`                 ⇒ the callee's declared single-class return type
 * Anything else (a loop-built/mutable array, divergent DTOs across branches, `array_map`, callbacks,
 * polymorphism, a bare non-Response return) ⇒ NON-DEFINITE (the compiler asks for `returns`).
 *
 * STATUS is tracked INDEPENDENTLY of the body shape: every success branch contributes its literal status
 * (json's explicit/default status, created=201, ok=200, noContent=204). The reported `status` is the
 * UNAMBIGUOUS status — set only when all status-bearing branches agree — and `statusConflict` flags branches
 * that DISAGREE (the single-status model cannot represent them; the compiler then asks for an explicit
 * multi-response declaration). This is order-independent: it never collapses to the last branch's status.
 *
 * ERROR inference was REMOVED in the fix-pass: literal `Response::error(...)` statuses are no longer inferred
 * and no OpenAPI `default` is synthesized from the method body. Standard 400/401/403/429/500 come from
 * StandardErrorPolicy (unchanged); endpoint-specific codes come only from `Route::errors` and explicit
 * `#[ApiResponse]` / `Response` declarations.
 *
 * The analyzer never claims `definite` when it is not: any ambiguity collapses to non-definite.
 */
final class ResponseAstAnalyzer
{
    private const RESPONSE_FQCN = 'SpsFW\Core\Http\Response';

    private ?Parser $parser = null;

    /** @var array<string, ?array<\PhpParser\Node\Stmt>> absolute file path ⇒ RESOLVED stmts (null = unparseable) */
    private array $resolvedAst = [];

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }

    public function inferSuccess(ReflectionMethod $method): SuccessInference
    {
        $methodNode = $this->locateMethod($method);
        if ($methodNode === null) {
            return new SuccessInference(definite: false, reason: 'method body is not statically analyzable');
        }
        $varClasses = $this->collectVariableClasses($methodNode);

        // Payloads come only from RESOLVED success branches; statuses come from EVERY success branch (a literal
        // status is known even when the payload shape is not). $unresolvable marks a branch that carried a
        // payload argument the analyzer could not resolve ⇒ non-definite (distinct from a genuine empty body).
        $payloads = []; // list<array{0:?string, 1:bool}> — (?class, collection), resolved branches only
        $statuses = []; // list<int> — every status-bearing success branch
        $hadSuccessReturn = false;
        $unresolvable = false;
        foreach ($this->returns($methodNode) as $return) {
            $expr = $return->expr;
            if ($expr === null) {
                continue; // bare `return;` — no body
            }
            $classified = $this->classifySuccessReturn($expr, $varClasses, $method);
            if ($classified === null) {
                continue; // error/throw producer — ignored as success
            }
            $hadSuccessReturn = true;
            [$class, $collection, $status, $emptyBody] = $classified;
            if ($status !== null) {
                $statuses[] = $status;
            }
            if ($emptyBody) {
                continue; // a genuine bodyless producer (noContent/ok/json()) — no payload to record
            }
            if ($class === null) {
                $unresolvable = true; // a payload argument was present but not statically resolvable
                continue;
            }
            $payloads[] = [$class, $collection];
        }

        $status = $this->unambiguousStatus($statuses);
        $statusConflict = $this->statusConflicts($statuses);

        if (!$hadSuccessReturn) {
            return new SuccessInference(definite: false, status: $status, statusConflict: $statusConflict, reason: 'no analyzable success return');
        }
        if ($unresolvable) {
            return new SuccessInference(definite: false, status: $status, statusConflict: $statusConflict, reason: 'at least one success return has a non-derivable payload');
        }
        if ($payloads === []) {
            // Every success branch was a genuine bodyless producer (Response::noContent()/ok()) ⇒ definite empty.
            return new SuccessInference(definite: true, status: $status, statusConflict: $statusConflict);
        }
        $first = $payloads[0];
        foreach ($payloads as $p) {
            if ($p !== $first) {
                return new SuccessInference(definite: false, status: $status, statusConflict: $statusConflict, reason: 'divergent success payloads across branches');
            }
        }
        [$class, $collection] = $first;
        return new SuccessInference(definite: true, class: $class, collection: $collection, status: $status, statusConflict: $statusConflict);
    }

    /**
     * The single status when every status-bearing branch agrees; null when they disagree OR none carries one.
     *
     * @param list<int> $statuses
     */
    private function unambiguousStatus(array $statuses): ?int
    {
        $unique = array_values(array_unique($statuses, SORT_REGULAR));
        return count($unique) === 1 ? $unique[0] : null;
    }

    /**
     * Whether two or more success branches carry DIFFERENT literal statuses (order-independent).
     *
     * @param list<int> $statuses
     */
    private function statusConflicts(array $statuses): bool
    {
        return count(array_unique($statuses, SORT_REGULAR)) > 1;
    }

    /**
     * Parse + name-resolve the method's file ONCE (memoized), then locate its ClassMethod. Two passes:
     *  1. the declaring-class FQCN (from reflection) + method name, corroborated by the Reflection start/end
     *     line range — the normal case (a method declared in its own class; two same-named methods in different
     *     classes never mix);
     *  2. a name + line-range sweep across ALL class-likes — for a method defined in a USED TRAIT, reflection
     *     reports the consuming class as the declaring class but points getFileName/getStartLine at the trait,
     *     so the FQCN pass cannot find it; the line range (the method's real definition location) does.
     * Never matches by short name alone. Returns null when the body is not statically analyzable.
     */
    private function locateMethod(ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        $file = $method->getFileName();
        if ($file === false) {
            return null;
        }
        $stmts = $this->resolvedStatements($file);
        if ($stmts === null) {
            return null;
        }

        $declaringFqcn = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $finder = new NodeFinder();
        /** @var list<Node\Stmt\ClassLike> $classLikes */
        $classLikes = $finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class);

        // Pass 1 — declaring FQCN + name + line corroboration.
        foreach ($classLikes as $classLike) {
            if ($this->classLikeFqcn($classLike) !== $declaringFqcn) {
                continue;
            }
            foreach ($classLike->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod
                    && $stmt->name->toString() === $methodName
                    && $this->lineRangeOverlaps($stmt, $startLine, $endLine)) {
                    return $stmt;
                }
            }
        }

        // Pass 2 — trait method (reflection's declaring class is the consumer; the line range lives in the trait).
        if ($startLine !== false && $endLine !== false) {
            $match = null;
            foreach ($classLikes as $classLike) {
                foreach ($classLike->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod
                        && $stmt->name->toString() === $methodName
                        && $this->lineRangeOverlaps($stmt, $startLine, $endLine)) {
                        // Prefer the smallest matching span — the method itself, not an outer wrapper containing it.
                        if ($match === null
                            || ($stmt->getEndLine() - $stmt->getStartLine()) < ($match->getEndLine() - $match->getStartLine())) {
                            $match = $stmt;
                        }
                    }
                }
            }
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function classLikeFqcn(Node\Stmt\ClassLike $classLike): ?string
    {
        if ($classLike->name === null) {
            return null; // anonymous class
        }
        // NameResolver writes the fully-qualified name to the `namespacedName` PROPERTY (a declared subnode on
        // ClassLike), not an attribute. Null for an unresolved/anonymous declaration ⇒ the class-like is skipped.
        return $classLike->namespacedName instanceof Node\Name ? $classLike->namespacedName->toString() : null;
    }

    private function lineRangeOverlaps(Node\Stmt\ClassMethod $method, int|false $startLine, int|false $endLine): bool
    {
        if ($startLine === false || $endLine === false) {
            return true; // reflection gave no lines — do not disambiguate
        }
        return $method->getStartLine() <= $endLine && $method->getEndLine() >= $startLine;
    }

    /**
     * @return list<Node\Stmt\Return_>
     */
    private function returns(Node\Stmt\ClassMethod $method): array
    {
        $finder = new NodeFinder();
        /** @var list<Node\Stmt\Return_> $out */
        $out = $method->stmts === null ? [] : $finder->findInstanceOf($method, Node\Stmt\Return_::class);
        return $out;
    }

    /**
     * Map `$var = new Dto()` assignments in the method to their single class (case B). A variable ever assigned
     * more than one distinct class (or a non-`new`) is excluded (conservative).
     *
     * @return array<string, ?string> var name ⇒ class FQCN (null = ambiguous)
     */
    private function collectVariableClasses(Node\Stmt\ClassMethod $method): array
    {
        $finder = new NodeFinder();
        /** @var list<Node\Expr\Assign> $assigns */
        $assigns = $method->stmts === null ? [] : $finder->findInstanceOf($method, Node\Expr\Assign::class);
        $classes = []; // var ⇒ list<?class>
        foreach ($assigns as $assign) {
            if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }
            if ($assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name) {
                $classes[$assign->var->name][] = self::resolvedFqcn($assign->expr->class);
            } else {
                $classes[$assign->var->name][] = null; // assigned a non-`new` ⇒ ambiguous
            }
        }
        $out = [];
        foreach ($classes as $var => $list) {
            $unique = array_values(array_unique(array_filter($list, fn ($v) => $v !== null)));
            if (count($list) === 1 && $list[0] !== null) {
                $out[$var] = $list[0];
            } elseif (count($unique) === 1 && count($list) === count($unique)) {
                $out[$var] = $unique[0];
            } else {
                $out[$var] = null; // ambiguous
            }
        }
        return $out;
    }

    /**
     * Classify one return expression as a success body.
     * Returns: null ⇒ error/throw producer (ignore);
     *          array{0:?string, 1:bool, 2:?int, 3:bool} ⇒ [?class, collection, ?status, emptyBody] where
     *          emptyBody=true marks a genuine bodyless producer and class=null+emptyBody=false marks an
     *          unresolvable payload (non-definite).
     *
     * @param array<string, ?string> $varClasses
     * @return null|array{0:?string, 1:bool, 2:?int, 3:bool}
     */
    private function classifySuccessReturn(
        Node\Expr $expr,
        array $varClasses,
        ReflectionMethod $caller,
    ): null|array {
        if (!$expr instanceof Node\Expr\StaticCall || !$expr->class instanceof Node\Name) {
            return [null, false, null, false]; // a non-Response success return — unresolvable (non-definite)
        }
        if (self::resolvedFqcn($expr->class) !== self::RESPONSE_FQCN) {
            return [null, false, null, false];
        }
        $name = strtolower($expr->name->toString());

        if (in_array($name, ['error', 'errormessage'], true)) {
            return null; // error producer — not a success body
        }

        // Resolve a payload expression to [?class, collection]; null = unresolvable.
        $payloadOf = static function (Node\Expr $payload) use ($varClasses, $caller): ?array {
            if ($payload instanceof Node\Expr\New_ && $payload->class instanceof Node\Name) {
                $cls = self::resolvedFqcn($payload->class);
                return $cls === null ? null : [$cls, false]; // case A
            }
            if ($payload instanceof Node\Expr\Variable && is_string($payload->name) && array_key_exists($payload->name, $varClasses)) {
                $cls = $varClasses[$payload->name];
                return $cls === null ? null : [$cls, false]; // case B
            }
            if ($payload instanceof Node\Expr\Array_) {
                $items = [];
                foreach ($payload->items as $item) {
                    if ($item === null || !$item->value instanceof Node\Expr\New_ || !$item->value->class instanceof Node\Name) {
                        return null; // non-`new` element / spread ⇒ unresolvable
                    }
                    $cls = self::resolvedFqcn($item->value->class);
                    if ($cls === null) {
                        return null;
                    }
                    $items[] = $cls;
                }
                if ($items === []) {
                    return null; // empty literal `[]` ⇒ element shape unknown
                }
                $unique = array_values(array_unique($items));
                return count($unique) === 1 ? [$unique[0], true] : null; // case C (homogeneous) else unresolvable
            }
            // case F: a same-class method call with a single-class declared return type.
            if ($payload instanceof Node\Expr\MethodCall && $payload->var instanceof Node\Expr\Variable
                && is_string($payload->var->name) && $payload->var->name === 'this'
                && $payload->name instanceof Node\Identifier) {
                $cls = self::calleeReturnClass($caller->getDeclaringClass()->getName(), $payload->name->toString());
                return $cls === null ? null : [$cls, false];
            }
            if ($payload instanceof Node\Expr\StaticCall && $payload->class instanceof Node\Name
                && in_array(strtolower($payload->class->getFirst()), ['self', 'static'], true)
                && $payload->name instanceof Node\Identifier) {
                $cls = self::calleeReturnClass($caller->getDeclaringClass()->getName(), $payload->name->toString());
                return $cls === null ? null : [$cls, false];
            }
            return null; // dynamic / array_map / callback / polymorphism ⇒ unresolvable
        };

        switch ($name) {
            case 'json':
                $args = $expr->getArgs();
                $status = $this->statusOf($args, 'status', 1) ?? 200; // Response::json defaults to 200
                if (!isset($args[0])) {
                    return [null, false, $status, true]; // json() with no payload ⇒ empty body
                }
                $res = $payloadOf($args[0]->value);
                return $res === null
                    ? [null, false, $status, false] // unresolvable payload
                    : [$res[0], $res[1], $status, false];
            case 'created':
                $status = 201;
                $args = $expr->getArgs();
                if (!isset($args[0])) {
                    return [null, false, $status, true]; // 201 with no body
                }
                $res = $payloadOf($args[0]->value);
                return $res === null ? [null, false, $status, false] : [$res[0], $res[1], $status, false];
            case 'ok':
                return [null, false, 200, true]; // Response::ok() carries no body
            case 'nocontent':
                return [null, false, 204, true];
            default:
                return [null, false, null, false]; // some other Response helper — unresolvable
        }
    }

    /**
     * The declared single-class return type of a same-class method (case F), or null when not a single eligible
     * class (union/none/builtin). Single hop only — no interprocedural data-flow.
     */
    private static function calleeReturnClass(string $class, string $method): ?string
    {
        if (!method_exists($class, $method)) {
            return null;
        }
        try {
            $type = (new \ReflectionMethod($class, $method))->getReturnType();
        } catch (\ReflectionException) {
            return null;
        }
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        return $type->getName();
    }

    /**
     * A literal integer from the named `$name` argument, falling back to the positional `$posIndex` argument;
     * null if absent OR present-but-non-literal. Name-agnostic of present-but-non-literal vs absent — the
     * caller (success status only) treats both as "use the default".
     *
     * @param list<Node\Arg> $args
     */
    private function statusOf(array $args, string $name, int $posIndex): ?int
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === $name) {
                return $this->intOf($arg);
            }
        }
        $positional = array_values(array_filter($args, static fn (Node\Arg $a): bool => $a->name === null));
        return isset($positional[$posIndex]) ? $this->intOf($positional[$posIndex]) : null;
    }

    /**
     * The literal integer value of an argument (LNumber, or a unary-minus over one); null when non-literal.
     */
    private function intOf(Node\Arg $arg): ?int
    {
        $v = $arg->value;
        if ($v instanceof Node\Scalar\LNumber) {
            return $v->value;
        }
        // `-404` (Unary minus over LNumber) is still literal.
        if ($v instanceof Node\Expr\UnaryMinus && $v->expr instanceof Node\Scalar\LNumber) {
            return -$v->expr->value;
        }
        return null;
    }

    /**
     * The FQCN a Name resolves to, via the NameResolver `resolvedName` attribute (replaceNodes:false). A
     * fully-qualified literal resolves to itself as a best-effort fallback; other unresolved names ⇒ null.
     */
    private static function resolvedFqcn(Node\Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }
        return $name->isFullyQualified() ? ltrim($name->toString(), '\\') : null;
    }

    /**
     * Parse + name-resolve a file ONCE, memoized by absolute path. Parse/resolution failure ⇒ null (every
     * inference for that file is then non-definite, no throw).
     *
     * @return ?array<\PhpParser\Node\Stmt>
     */
    private function resolvedStatements(string $file): ?array
    {
        if (array_key_exists($file, $this->resolvedAst)) {
            return $this->resolvedAst[$file];
        }
        $source = @file_get_contents($file);
        if ($source === false) {
            $this->resolvedAst[$file] = null;
            return null;
        }
        try {
            $stmts = $this->parser()->parse($source);
        } catch (\Throwable) {
            $stmts = null;
        }
        if ($stmts === null) {
            $this->resolvedAst[$file] = null;
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $this->resolvedAst[$file] = $traverser->traverse($stmts);
        return $this->resolvedAst[$file];
    }
}
