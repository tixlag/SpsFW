<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionMethod;

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
 * SUCCESS inference ({@see inferSuccess()}) walks the action's OWN `return` statements and recognizes, via the
 * resolved `Response` class, the success producers `Response::json($data, $status=200)` / `Response::created($data)`
 * (201) / `Response::ok()` (200) / `Response::noContent()` (204). Recognized payload shapes:
 *   A. `Response::json(new Dto())`                       ⇒ Dto (single)
 *   B. `$d = new Dto(); … return Response::json($d);`    ⇒ Dto (single)
 *   C. `Response::json([new Dto(), …])` homogeneous      ⇒ [Dto]
 *   D. several success returns of the SAME Dto           ⇒ Dto
 *   E. `Response::error(...)` / `throw`                  ⇒ ignored (not a success body)
 * A callee resolved only through its return type (`Response::json($this->method())` / `Response::json(self::make())`)
 * is NOT inferred — it stays NON-DEFINITE (no interprocedural data-flow). Anything else (a loop-built/mutable
 * array, divergent DTOs across branches, `array_map`, callbacks, polymorphism, a bare non-Response return,
 * a dynamic `Response::{$method}(...)` name) ⇒ NON-DEFINITE (the compiler asks for `returns`).
 *
 * SCOPING: only the action's DIRECT body is analyzed. Returns and assignments nested inside a closure, an
 * arrow function, or an anonymous class declared within the action are SEPARATE scopes and never collected —
 * a helper closure's `return` or its `$x = new Dto()` is not the action's. {@see findInBody()} stops at those
 * boundaries (NodeFinder would descend into them). A dynamic `Response` method name (`Response::{$m}()`) is
 * detected before any `toString()` and treated as non-definite (it never crashes the compiler).
 *
 * STATUS is tracked INDEPENDENTLY of the body shape and is TRI-state per branch:
 *   - `Response::json()` with no status argument ⇒ the 200 default (a known status);
 *   - `Response::json($x, 201)` with a LITERAL int status ⇒ that status;
 *   - `Response::json($x, $var)` with a NON-LITERAL status expression ⇒ INDETERMINATE (NOT 200).
 * The reported `status` is the UNAMBIGUOUS status — set only when every status-bearing branch agrees — and
 * `statusConflict` flags branches that DISAGREE. `statusIndeterminate` flags any branch whose status is a
 * non-literal expression. `statusConflict`/`statusIndeterminate` are surfaced for the compiler to diagnose
 * (asking for an explicit multi-response declaration or successStatus); this is order-independent — it never
 * collapses to the last branch's status.
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

        // Payloads come only from RESOLVED success branches; statuses come from EVERY status-bearing branch (a
        // literal status is known even when the payload shape is not). $unresolvable marks a branch that carried
        // a payload argument the analyzer could not resolve ⇒ non-definite (distinct from a genuine empty body).
        $payloads = []; // list<array{0:?string, 1:bool}> — (?class, collection), resolved branches only
        $statuses = []; // list<int> — every status-bearing success branch (a literal/known status only)
        $hadSuccessReturn = false;
        $unresolvable = false;
        $statusIndeterminate = false; // any branch carries a NON-LITERAL status expression
        foreach ($this->returns($methodNode) as $return) {
            $expr = $return->expr;
            if ($expr === null) {
                continue; // bare `return;` — no body
            }
            $classified = $this->classifySuccessReturn($expr, $varClasses);
            if ($classified === null) {
                continue; // error/throw producer — ignored as success
            }
            $hadSuccessReturn = true;
            [$class, $collection, $status, $emptyBody, $branchIndeterminate] = $classified;
            if ($branchIndeterminate) {
                $statusIndeterminate = true;
            }
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
            return new SuccessInference(definite: false, status: $status, statusConflict: $statusConflict, statusIndeterminate: $statusIndeterminate, reason: 'no analyzable success return');
        }
        if ($unresolvable) {
            return new SuccessInference(definite: false, status: $status, statusConflict: $statusConflict, statusIndeterminate: $statusIndeterminate, reason: 'at least one success return has a non-derivable payload');
        }
        if ($payloads === []) {
            // Every success branch was a genuine bodyless producer (Response::noContent()/ok()) ⇒ definite empty.
            return new SuccessInference(definite: true, status: $status, statusConflict: $statusConflict, statusIndeterminate: $statusIndeterminate);
        }
        $first = $payloads[0];
        foreach ($payloads as $p) {
            if ($p !== $first) {
                return new SuccessInference(definite: false, status: $status, statusConflict: $statusConflict, statusIndeterminate: $statusIndeterminate, reason: 'divergent success payloads across branches');
            }
        }
        [$class, $collection] = $first;
        return new SuccessInference(definite: true, class: $class, collection: $collection, status: $status, statusConflict: $statusConflict, statusIndeterminate: $statusIndeterminate);
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
        /** @var list<Node\Stmt\Return_> $out */
        return $this->findInBody($method, [Node\Stmt\Return_::class]);
    }

    /**
     * Map `$var = new Dto()` assignments in the method's OWN body to their single class (case B). A variable ever
     * assigned more than one distinct class (or a non-`new`) is excluded (conservative). Assignments nested in a
     * closure / arrow function / anonymous class belong to those separate scopes and are NOT collected.
     *
     * @return array<string, ?string> var name ⇒ class FQCN (null = ambiguous)
     */
    private function collectVariableClasses(Node\Stmt\ClassMethod $method): array
    {
        /** @var list<Node\Expr\Assign> $assigns */
        $assigns = $this->findInBody($method, [Node\Expr\Assign::class]);
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
     * Find nodes of the given types that belong DIRECTLY to the action's body — NOT those nested inside a
     * closure, an arrow function, or an anonymous class declared within it. Those are separate scopes: a helper
     * closure's `return` or its inner `$x = new Dto()` is not the action's own control flow / state, so it must
     * never be collected. {@link NodeFinder::findInstanceOf} would descend into them; this walker returns
     * {@see NodeTraverser::DONT_TRAVERSE_CHILDREN} at those boundaries. The root `ClassMethod` itself is never a
     * nested scope, so its own statements ARE walked.
     *
     * @param list<class-string<Node>> $types
     * @return list<Node>
     */
    private function findInBody(Node\Stmt\ClassMethod $method, array $types): array
    {
        if ($method->stmts === null) {
            return [];
        }
        $visitor = new class($types) extends NodeVisitorAbstract {
            /** @var list<class-string<Node>> */
            private array $types;
            /** @var list<Node> */
            public array $found = [];
            public function __construct(array $types) { $this->types = $types; }
            public function enterNode(Node $node)
            {
                // A nested closure / arrow function / anonymous class is a SEPARATE scope — stop here.
                if ($node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                    || ($node instanceof Node\Stmt\Class_ && $node->name === null)) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                foreach ($this->types as $type) {
                    if ($node instanceof $type) {
                        $this->found[] = $node;
                    }
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$method]);
        return $visitor->found;
    }

    /**
     * Classify one return expression as a success body.
     * Returns: null ⇒ error/throw producer (ignore);
     *          array{0:?string, 1:bool, 2:?int, 3:bool, 4:bool} ⇒
     *          [?class, collection, ?status, emptyBody, statusIndeterminate] where emptyBody=true marks a genuine
     *          bodyless producer, class=null+emptyBody=false marks an unresolvable payload (non-definite), and
     *          statusIndeterminate=true marks a branch whose status is a non-literal expression (status is then
     *          null and the compiler must diagnose it).
     *
     * @param array<string, ?string> $varClasses
     * @return null|array{0:?string, 1:bool, 2:?int, 3:bool, 4:bool}
     */
    private function classifySuccessReturn(
        Node\Expr $expr,
        array $varClasses,
    ): null|array {
        if (!$expr instanceof Node\Expr\StaticCall || !$expr->class instanceof Node\Name) {
            return [null, false, null, false, false]; // a non-Response success return — unresolvable (non-definite)
        }
        if (self::resolvedFqcn($expr->class) !== self::RESPONSE_FQCN) {
            return [null, false, null, false, false];
        }
        // A dynamic method name (Response::{$method}(), Response::$method()) is not an Identifier ⇒ unresolvable.
        // Checked BEFORE toString() so it never crashes the compiler.
        if (!$expr->name instanceof Node\Identifier) {
            return [null, false, null, false, false];
        }
        $name = strtolower($expr->name->toString());

        if (in_array($name, ['error', 'errormessage'], true)) {
            return null; // error producer — not a success body
        }

        // Resolve a payload expression to [?class, collection]; null = unresolvable.
        $payloadOf = static function (Node\Expr $payload) use ($varClasses): ?array {
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
            return null; // $this->method() / self::method() / array_map / callback / polymorphism ⇒ unresolvable
        };

        switch ($name) {
            case 'json':
                $args = $expr->getArgs();
                [$status, $statusIndeterminate] = $this->jsonStatus($args);
                $payloadArg = $this->findPayloadArg($args);
                if ($payloadArg === null) {
                    return [null, false, $status, true, $statusIndeterminate]; // json() with no payload ⇒ empty body
                }
                $res = $payloadOf($payloadArg->value);
                return $res === null
                    ? [null, false, $status, false, $statusIndeterminate] // unresolvable payload
                    : [$res[0], $res[1], $status, false, $statusIndeterminate];
            case 'created':
                $args = $expr->getArgs();
                $payloadArg = $this->findPayloadArg($args);
                if ($payloadArg === null) {
                    return [null, false, 201, true, false]; // 201 with no body
                }
                $res = $payloadOf($payloadArg->value);
                return $res === null ? [null, false, 201, false, false] : [$res[0], $res[1], 201, false, false];
            case 'ok':
                return [null, false, 200, true, false]; // Response::ok() carries no body
            case 'nocontent':
                return [null, false, 204, true, false];
            default:
                return [null, false, null, false, false]; // some other Response helper — unresolvable
        }
    }

    /**
     * The success status of a `Response::json(...)` call as a TRI-state:
     *   - no status argument passed ⇒ [200, false] (Response::json's default — a KNOWN status);
     *   - a LITERAL int status ⇒ [that int, false];
     *   - a present-but-NON-LITERAL status expression ⇒ [null, true] (INDETERMINATE — NOT silently 200).
     *
     * @param list<Node\Arg> $args
     * @return array{0: ?int, 1: bool} [?literal status, indeterminate]
     */
    private function jsonStatus(array $args): array
    {
        $statusArg = $this->findStatusArg($args);
        if ($statusArg === null) {
            return [200, false]; // absent ⇒ the Response::json default
        }
        $literal = $this->intOf($statusArg);
        return $literal === null ? [null, true] : [$literal, false];
    }

    /**
     * The payload argument (`data`, the first param of Response::json/created): the NAMED `data` argument when
     * present, else the first POSITIONAL argument. null when neither is passed (e.g. `Response::json(status: 201)`).
     *
     * @param list<Node\Arg> $args
     */
    private function findPayloadArg(array $args): ?Node\Arg
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === 'data') {
                return $arg;
            }
        }
        return self::positionalArg($args, 0);
    }

    /**
     * The status argument (`status`, the second param of Response::json): the NAMED `status` argument when
     * present, else the SECOND positional argument. null when no status argument was passed.
     *
     * @param list<Node\Arg> $args
     */
    private function findStatusArg(array $args): ?Node\Arg
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === 'status') {
                return $arg;
            }
        }
        return self::positionalArg($args, 1);
    }

    /**
     * The positional-only argument at `$index` (named arguments skipped), or null.
     *
     * @param list<Node\Arg> $args
     */
    private static function positionalArg(array $args, int $index): ?Node\Arg
    {
        $positional = array_values(array_filter($args, static fn (Node\Arg $a): bool => $a->name === null));
        return $positional[$index] ?? null;
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
