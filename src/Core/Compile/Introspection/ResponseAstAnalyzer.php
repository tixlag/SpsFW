<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\Parser;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Conservative AST inference of a controller action's success body and error status codes (M8b).
 *
 * Uses nikic/php-parser v5 (a direct compile dependency). Each controller file is parsed ONCE and the
 * AST memoized by absolute path — no runtime overhead beyond compile. Parse failure ⇒ every inference
 * is non-definite (no throw): the compiler then requires an explicit `Route::returns`.
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
 * Anything else (`$unknownArray`, a loop-built/mutable array, divergent DTOs across branches, `array_map`,
 * callbacks, polymorphism, a bare non-Response return) ⇒ NON-DEFINITE (the compiler asks for `returns`).
 *
 * ERROR inference ({@see inferErrors()}) scans `Response::error(...)` / `Response::errorMessage(...)`:
 * a literal status ⇒ that code; a non-literal (computed) status ⇒ {@see ErrorInference::$hasDynamic}
 * (the emitter then adds an OpenAPI `default`). Bare `throw` is NOT dynamic — it is covered by the
 * always-present 500.
 *
 * The analyzer never claims `definite` when it is not: any ambiguity collapses to non-definite.
 */
final class ResponseAstAnalyzer
{
    private const RESPONSE_FQCN = 'SpsFW\Core\Http\Response';

    private ?Parser $parser = null;

    /** @var array<string, ?array<\PhpParser\Node\Stmt>> absolute file path ⇒ parsed stmts (null = unparseable) */
    private array $fileAst = [];

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }

    public function inferSuccess(ReflectionMethod $method): SuccessInference
    {
        $ctx = $this->methodContext($method);
        if ($ctx === null) {
            return new SuccessInference(definite: false, reason: 'method body is not statically analyzable');
        }
        [$methodNode, $resolve, $useMap] = $ctx;

        $varClasses = $this->collectVariableClasses($methodNode, $resolve);

        $payloads = []; // list<array{0:?string, 1:bool}> — (?class, collection)
        $status = null;
        $hadSuccessReturn = false;
        $ambiguous = false;
        foreach ($this->returns($methodNode) as $return) {
            $expr = $return->expr;
            if ($expr === null) {
                continue; // bare `return;` — no body
            }
            $classified = $this->classifySuccessReturn($expr, $resolve, $useMap, $varClasses, $method);
            if ($classified === null) {
                continue; // error/throw producer — ignored as success
            }
            $hadSuccessReturn = true;
            if ($classified === false) {
                $ambiguous = true; // success return whose payload is not statically resolvable
                continue;
            }
            [$class, $collection, $st] = $classified;
            if ($st !== null) {
                $status = $st;
            }
            $payloads[] = [$class, $collection];
        }

        if (!$hadSuccessReturn) {
            return new SuccessInference(definite: false, status: $status, reason: 'no analyzable success return');
        }
        if ($ambiguous) {
            return new SuccessInference(definite: false, status: $status, reason: 'at least one success return has a non-derivable payload');
        }
        if ($status === 204) {
            // 204 ⇒ empty body, definite regardless of payload (Response::noContent()).
            return new SuccessInference(definite: true, status: 204);
        }
        if ($payloads === []) {
            return new SuccessInference(definite: false, status: $status, reason: 'no payload-bearing success return');
        }
        $first = $payloads[0];
        foreach ($payloads as $p) {
            if ($p !== $first) {
                return new SuccessInference(definite: false, status: $status, reason: 'divergent success payloads across branches');
            }
        }
        [$class, $collection] = $first;
        if ($class === null) {
            return new SuccessInference(definite: false, status: $status, reason: 'success payload is not a class instance');
        }
        return new SuccessInference(definite: true, class: $class, collection: $collection, status: $status);
    }

    public function inferErrors(ReflectionMethod $method): ErrorInference
    {
        $ctx = $this->methodContext($method);
        if ($ctx === null) {
            return new ErrorInference();
        }
        [$methodNode, $resolve] = $ctx;

        $finder = new NodeFinder();
        $calls = $finder->find(
            $methodNode,
            static fn (Node $n): bool => ($n instanceof Node\Expr\StaticCall)
                && $n->class instanceof Node\Name
                && in_array(strtolower($n->name->toString()), ['error', 'errormessage'], true),
        );

        $literals = [];
        $hasDynamic = false;
        foreach ($calls as $call) {
            assert($call instanceof Node\Expr\StaticCall);
            if ($resolve($call->class) !== self::RESPONSE_FQCN) {
                continue;
            }
            $which = strtolower($call->name->toString());
            $statusArg = $this->statusArg($call, $which);
            if (is_int($statusArg)) {
                $literals[$statusArg] = true;
            } elseif ($statusArg === 'dynamic') {
                $hasDynamic = true;
            }
            // $statusArg === null ⇒ default-status error (400 errorMessage / 500 error) ⇒ already in policy.
        }
        $codes = array_keys($literals);
        sort($codes);
        return new ErrorInference(literalStatuses: $codes, hasDynamic: $hasDynamic);
    }

    /**
     * Load + parse the method's file (cached), locate its ClassMethod node, and build a name resolver.
     * Returns [ClassMethod, resolver(Closure), useMap] or null when not analyzable.
     *
     * @return array{0: Node\Stmt\ClassMethod, 1: \Closure(Node\Name):string, 2: array<string,string>}|null
     */
    private function methodContext(ReflectionMethod $method): ?array
    {
        $file = $method->getFileName();
        if ($file === false) {
            return null;
        }
        $stmts = $this->parsedStatements($file);
        if ($stmts === null) {
            return null;
        }
        [$namespace, $useMap] = $this->collectImports($stmts);
        $resolve = function (Node\Name $name) use ($namespace, $useMap): string {
            return $this->resolveName($name, $namespace, $useMap);
        };
        $methodNode = $this->findClassMethod($stmts, $method->getDeclaringClass()->getShortName(), $method->getName());
        if ($methodNode === null) {
            return null;
        }
        return [$methodNode, $resolve, $useMap];
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $stmts
     * @return array{0: string, 1: array<string,string>} [namespace, useMap(alias|short ⇒ FQCN)]
     */
    private function collectImports(array $stmts): array
    {
        $namespace = '';
        $useMap = [];
        $nsStmts = $stmts;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $namespace = $stmt->name?->toString() ?? '';
                $nsStmts = $stmt->stmts;
                break;
            }
        }
        foreach ($nsStmts as $stmt) {
            $this->collectUse($stmt, $useMap);
        }
        return [$namespace, $useMap];
    }

    /**
     * @param array<string,string> $useMap
     */
    private function collectUse(Node\Stmt $stmt, array &$useMap): void
    {
        if ($stmt instanceof Node\Stmt\Use_) {
            foreach ($stmt->uses as $use) {
                $alias = $use->alias?->name ?? $use->name->getLast();
                $useMap[$alias] = $use->name->toString();
            }
            return;
        }
        // Group use (`use Foo\Bar\{Baz, Qux as Q};`)
        if ($stmt instanceof Node\Stmt\GroupUse) {
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $use) {
                $alias = $use->alias?->name ?? $use->name->getLast();
                $useMap[$alias] = $prefix . '\\' . $use->name->toString();
            }
        }
    }

    private function resolveName(Node\Name $name, string $namespace, array $useMap): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }
        $parts = $name->getParts();
        $first = $parts[0];
        if (isset($useMap[$first])) {
            $base = $useMap[$first];
            return count($parts) > 1 ? $base . '\\' . implode('\\', array_slice($parts, 1)) : $base;
        }
        return ($namespace !== '' ? $namespace . '\\' : '') . implode('\\', $parts);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    private function findClassMethod(array $stmts, string $classShort, string $methodName): ?Node\Stmt\ClassMethod
    {
        $finder = new NodeFinder();
        /** @var list<Node\Stmt\ClassMethod> $candidates */
        $candidates = $finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class);
        $byName = array_values(array_filter($candidates, static fn (Node\Stmt\ClassMethod $m): bool => $m->name->toString() === $methodName));
        if ($byName === []) {
            return null;
        }
        if (count($byName) === 1) {
            return $byName[0];
        }
        // Disambiguate by the declaring class short name (the enclosing ClassLike name).
        foreach ($byName as $m) {
            if (self::enclosingClassShort($m) === $classShort) {
                return $m;
            }
        }
        return $byName[0];
    }

    private static function enclosingClassShort(Node $node): ?string
    {
        for ($p = $node; $p !== null; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\Stmt\ClassLike && $p->name !== null) {
                return $p->name->toString();
            }
        }
        return null;
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
     * Map `$var = new Dto()` assignments in the method to their single class (case B). A variable ever
     * assigned more than one distinct class (or a non-`new`) is excluded (conservative).
     *
     * @param \Closure(Node\Name):string $resolve
     * @return array<string, ?string> var name ⇒ class FQCN (null = ambiguous)
     */
    private function collectVariableClasses(Node\Stmt\ClassMethod $method, \Closure $resolve): array
    {
        $finder = new NodeFinder();
        /** @var list<Node\Expr\Assign> $assigns */
        $assigns = $method->stmts === null ? [] : $finder->findInstanceOf($method, Node\Expr\Assign::class);
        $classes = []; // var ⇒ list<class>
        foreach ($assigns as $assign) {
            if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }
            if ($assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name) {
                $classes[$assign->var->name][] = $resolve($assign->expr->class);
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
     * Returns: null ⇒ error/throw producer (ignore); false ⇒ success but non-derivable (ambiguous);
     *          [?class, collection, ?status] ⇒ derived.
     *
     * @param \Closure(Node\Name):string $resolve
     * @param array<string,string> $useMap
     * @param array<string,?string> $varClasses
     * @return null|false|array{0:?string, 1:bool, 2:?int}
     */
    private function classifySuccessReturn(
        Node\Expr $expr,
        \Closure $resolve,
        array $useMap,
        array $varClasses,
        ReflectionMethod $caller,
    ): null|false|array {
        if (!$expr instanceof Node\Expr\StaticCall || !$expr->class instanceof Node\Name) {
            return false; // a non-Response success return — leave to native inference / ask for returns
        }
        if ($resolve($expr->class) !== self::RESPONSE_FQCN) {
            return false;
        }
        $name = strtolower($expr->name->toString());

        if (in_array($name, ['error', 'errormessage'], true)) {
            return null; // error producer — not a success body
        }

        // Resolve a payload expression to [?class, collection]; false = ambiguous.
        $payloadOf = static function (Node\Expr $payload) use ($resolve, $varClasses, $caller, $useMap): null|false|array {
            if ($payload instanceof Node\Expr\New_ && $payload->class instanceof Node\Name) {
                return [$resolve($payload->class), false]; // case A
            }
            if ($payload instanceof Node\Expr\Variable && is_string($payload->name) && array_key_exists($payload->name, $varClasses)) {
                $cls = $varClasses[$payload->name];
                return $cls === null ? false : [$cls, false]; // case B
            }
            if ($payload instanceof Node\Expr\Array_) {
                $items = [];
                foreach ($payload->items as $item) {
                    if ($item === null || !$item->value instanceof Node\Expr\New_ || !$item->value->class instanceof Node\Name) {
                        return false; // non-`new` element / spread ⇒ ambiguous
                    }
                    $items[] = $resolve($item->value->class);
                }
                if ($items === []) {
                    return false; // empty literal `[]` ⇒ element shape unknown
                }
                $unique = array_values(array_unique($items));
                return count($unique) === 1 ? [$unique[0], true] : false; // case C (homogeneous) else ambiguous
            }
            // case F: a same-class method call with a single-class declared return type.
            if ($payload instanceof Node\Expr\MethodCall && $payload->var instanceof Node\Expr\Variable
                && is_string($payload->var->name) && $payload->var->name === 'this'
                && $payload->name instanceof Node\Identifier) {
                $cls = self::calleeReturnClass($caller->getDeclaringClass()->getName(), $payload->name->toString());
                return $cls === null ? false : [$cls, false];
            }
            if ($payload instanceof Node\Expr\StaticCall && $payload->class instanceof Node\Name
                && in_array(strtolower($payload->class->getFirst()), ['self', 'static'], true)
                && $payload->name instanceof Node\Identifier) {
                $cls = self::calleeReturnClass($caller->getDeclaringClass()->getName(), $payload->name->toString());
                return $cls === null ? false : [$cls, false];
            }
            return false; // dynamic / array_map / callback / polymorphism ⇒ ambiguous
        };

        switch ($name) {
            case 'json':
                $args = $expr->getArgs();
                if (!isset($args[0])) {
                    return false;
                }
                $status = $this->namedOrPositionalInt($args, 'status', 1);
                $res = $payloadOf($args[0]->value);
                return $res === false || $res === null
                    ? ($status === null ? false : [null, false, $status])
                    : [$res[0], $res[1], $status];
            case 'created':
                $args = $expr->getArgs();
                $status = 201;
                if (!isset($args[0])) {
                    return [null, false, $status]; // 201 with no body
                }
                $res = $payloadOf($args[0]->value);
                return $res === false || $res === null ? [null, false, $status] : [$res[0], $res[1], $status];
            case 'ok':
                $args = $expr->getArgs();
                $status = 200;
                if (!isset($args[0])) {
                    return [null, false, $status]; // 200 with no body
                }
                $res = $payloadOf($args[0]->value);
                return $res === false || $res === null ? [null, false, $status] : [$res[0], $res[1], $status];
            case 'nocontent':
                return [null, false, 204];
            default:
                return false; // some other Response helper — leave to native/returns
        }
    }

    /**
     * The declared single-class return type of a same-class method (case F), or null when not a single
     * eligible class (union/none/builtin). Single hop only — no interprocedural data-flow.
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
     * The literal status of a Response::error / errorMessage call:
     *   error:       3rd positional or named `statusCode:` ⇒ int|'dynamic'|null(default 500 path)
     *   errorMessage:2nd positional or named `status:`     ⇒ int|'dynamic'|null(default 400 path)
     *
     * @return int|string|null int literal, 'dynamic' for a non-literal arg, null for no arg (default)
     */
    private function statusArg(Node\Expr\StaticCall $call, string $which): int|string|null
    {
        $named = $which === 'error' ? 'statusCode' : 'status';
        $posIndex = $which === 'error' ? 2 : 1; // 0-based: error($e,$msg,$code); errorMessage($msg,$status,…)
        $value = $this->namedOrPositionalInt($call->getArgs(), $named, $posIndex);
        if ($value === null) {
            $present = $this->hasNamedOrPositional($call->getArgs(), $named, $posIndex);
            return $present ? 'dynamic' : null;
        }
        return $value;
    }

    /**
     * A literal integer from the named `$name` argument, falling back to the positional `$posIndex` argument;
     * null if absent OR present-but-non-literal.
     *
     * @param list<Node\Arg> $args
     */
    private function namedOrPositionalInt(array $args, string $name, int $posIndex): ?int
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
     * Whether a named `$name` or positional `$posIndex` argument is present at all (used to tell "present but
     * non-literal" from "absent").
     *
     * @param list<Node\Arg> $args
     */
    private function hasNamedOrPositional(array $args, string $name, int $posIndex): bool
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === $name) {
                return true;
            }
        }
        $positional = array_values(array_filter($args, static fn (Node\Arg $a): bool => $a->name === null));
        return isset($positional[$posIndex]);
    }

    /**
     * The literal integer value of an argument (LNumber, or a unary-minus over one); null when non-literal.
     * Name-agnostic — the caller resolves named vs positional.
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
     * @return ?array<\PhpParser\Node\Stmt>
     */
    private function parsedStatements(string $file): ?array
    {
        if (!array_key_exists($file, $this->fileAst)) {
            $source = @file_get_contents($file);
            if ($source === false) {
                $this->fileAst[$file] = null;
                return null;
            }
            try {
                $this->fileAst[$file] = $this->parser()->parse($source);
            } catch (\Throwable) {
                $this->fileAst[$file] = null;
            }
        }
        return $this->fileAst[$file];
    }
}
