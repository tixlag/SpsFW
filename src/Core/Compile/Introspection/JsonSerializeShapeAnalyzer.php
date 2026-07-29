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
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Conservative static projection of a {@see \JsonSerializable::jsonSerialize()} method's wire shape (Step 9.5 §2).
 *
 * A JsonSerializable class curates its own response shape (a subset of its properties, excluding internal DB
 * columns and credentials). The schema OUTPUT projection must honor that subset. This analyzer derives the
 * EXACT wire key set statically — it NEVER emits an exhaustive fallback (§3): when the shape is not provable it
 * returns a non-provable {@see JsonSerializeProjection} and the builder emits a diagnostic asking for an explicit
 * response declaration.
 *
 * PROVABLE shapes (the key set is statically determined):
 *  - a literal array `return ['k' => $this->x, …]`;
 *  - a provable parent merged with a literal — `parent::jsonSerialize() + […]`, `array_merge(parent::jsonSerialize(), […])`,
 *    `[...parent::jsonSerialize(), 'k' => …]`, or the `$r = parent::jsonSerialize(); $r += […]; return $r;` idiom;
 *  - a single-hop delegation — `return $this->entity->jsonSerialize();` (the property's type is a JsonSerializable class).
 *
 * Each value must be statically tied to its source: a `$this->property` (bare), a scalar-value transform on a
 * property (`$this->date->format(…)` / `$this->date?->format(…)`), an array access on a property
 * (`$this->map['k']`), a property-derived `array_map(fn $x => …, $this->prop)`, a literal scalar, or a nested
 * literal array. ANY other value (a service call, a bare variable, `get_object_vars($this)`, a ternary) makes the
 * shape non-provable (§2). A runtime branch / loop / match / try in the body likewise makes it non-provable.
 *
 * Uses nikic/php-parser v5 (a direct compile dependency). Each file is parsed + name-resolved ONCE (memoized by
 * absolute path); parse failure ⇒ non-provable (no throw). The `jsonSerialize` ClassMethod is located by the
 * method's FILE + declaring-class FQCN + Reflection line range — never by short name alone — mirroring
 * {@see ResponseAstAnalyzer}. Cycle-safe: a self-referential parent/delegation chain is detected and reported
 * non-provable rather than looping.
 */
final class JsonSerializeShapeAnalyzer
{
    private ?Parser $parser = null;

    /** @var array<string, ?array<\PhpParser\Node\Stmt>> absolute file path ⇒ RESOLVED stmts (null = unparseable) */
    private array $resolvedAst = [];

    /** @var array<class-string, list<string>> class ⇒ FQCNs currently on the projection stack (cycle guard) */
    private array $stack = [];

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }

    /**
     * Statically project the wire shape of `$class`'s effective {@see jsonSerialize()}. Returns a non-provable
     * projection (with a reason) when the class has no analyzable jsonSerialize or its shape is not statically
     * determined.
     */
    public function project(string $class): JsonSerializeProjection
    {
        $method = $this->findJsonSerializeMethod($class);
        if ($method === null) {
            return JsonSerializeProjection::unprovable($class . ' has no jsonSerialize() method');
        }
        $node = $this->locateClassMethod($method);
        if ($node === null || $node->stmts === null) {
            return JsonSerializeProjection::unprovable($class . '::jsonSerialize() body is not statically analyzable');
        }
        $declaring = $method->getDeclaringClass()->getName();
        $shape = $this->analyzeBody($declaring, $node);
        if ($shape === null) {
            return JsonSerializeProjection::unprovable($class . '::jsonSerialize() does not return a statically-provable literal shape');
        }
        /** @var array<string, JsonSerializeKey> $shape */
        return new JsonSerializeProjection(provable: true, keys: array_values($shape));
    }

    /**
     * The effective jsonSerialize() ReflectionMethod for `$class` (walked through the inheritance chain the way
     * PHP dispatches it), or null when the class (and all ancestors) declare none.
     */
    private function findJsonSerializeMethod(string $class): ?ReflectionMethod
    {
        try {
            return new ReflectionClass($class)->getMethod('jsonSerialize');
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Parse + name-resolve the method's file ONCE (memoized), then locate its ClassMethod by declaring FQCN +
     * name corroborated by the Reflection line range (with a trait line-range fallback), exactly like
     * {@see ResponseAstAnalyzer::locateMethod()}. Never matches by short name alone.
     */
    private function locateClassMethod(ReflectionMethod $method): ?Node\Stmt\ClassMethod
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
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $finder = new NodeFinder();
        /** @var list<Node\Stmt\ClassLike> $classLikes */
        $classLikes = $finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class);

        foreach ($classLikes as $classLike) {
            if ($this->classLikeFqcn($classLike) !== $declaringFqcn) {
                continue;
            }
            foreach ($classLike->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod
                    && $stmt->name->toString() === 'jsonSerialize'
                    && $this->overlaps($stmt, $startLine, $endLine)) {
                    return $stmt;
                }
            }
        }
        if ($startLine !== false && $endLine !== false) {
            $match = null;
            foreach ($classLikes as $classLike) {
                foreach ($classLike->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod
                        && $stmt->name->toString() === 'jsonSerialize'
                        && $this->overlaps($stmt, $startLine, $endLine)
                        && ($match === null
                            || ($stmt->getEndLine() - $stmt->getStartLine()) < ($match->getEndLine() - $match->getStartLine()))) {
                        $match = $stmt;
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
            return null;
        }
        return $classLike->namespacedName instanceof Node\Name ? $classLike->namespacedName->toString() : null;
    }

    private function overlaps(Node\Stmt\ClassMethod $m, int|false $start, int|false $end): bool
    {
        if ($start === false || $end === false) {
            return true;
        }
        return $m->getStartLine() <= $end && $m->getEndLine() >= $start;
    }

    /**
     * @return ?array<string, JsonSerializeKey> ordered wireName ⇒ key, or null when the body is not statically provable
     */
    private function analyzeBody(string $declaring, Node\Stmt\ClassMethod $node): ?array
    {
        // Any runtime branching / loop / try at the direct body level ⇒ the wire shape is not statically fixed.
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\If_
                || $stmt instanceof Node\Stmt\Switch_
                || $stmt instanceof Node\Stmt\Match_
                || $stmt instanceof Node\Stmt\TryCatch
                || $stmt instanceof Node\Stmt\Foreach_
                || $stmt instanceof Node\Stmt\For_
                || $stmt instanceof Node\Stmt\While_
                || $stmt instanceof Node\Stmt\Do_) {
                return null;
            }
        }

        $returns = array_values(array_filter(
            $node->stmts ?? [],
            static fn (Node $s): bool => $s instanceof Node\Stmt\Return_,
        ));
        if (count($returns) !== 1) {
            return null; // zero or multiple returns ⇒ not a single statically-fixed shape
        }
        $return = $returns[0];
        assert($return instanceof Node\Stmt\Return_);
        if ($return->expr === null) {
            return null;
        }

        // Track the `$r = <shape>; $r += […]; $r['k'] = …;` idiom: linear, in source order. Expression statements
        // (`$r = …;`) are wrapped in a Stmt\Expression in nikic v5 — unwrap to the inner expression.
        /** @var array<string, ?array<string, JsonSerializeKey>> $vars var name ⇒ shape (null = dynamic) */
        $vars = [];
        foreach ($node->stmts ?? [] as $stmt) {
            $expr = $stmt instanceof Node\Stmt\Expression ? $stmt->expr : $stmt;
            if ($expr instanceof Node\Expr\Assign) {
                $name = $this->localVarName($expr->var);
                if ($name !== null) {
                    $vars[$name] = $this->shapeOfExpr($declaring, $expr->expr);
                } elseif ($expr->var instanceof Node\Expr\ArrayDimFetch && $expr->var->dim !== null) {
                    // `$r['k'] = …` augments a tracked array; a literal-string key only.
                    $v = $this->localVarName($expr->var->var);
                    if ($v !== null && array_key_exists($v, $vars) && $vars[$v] !== null && $expr->var->dim instanceof Node\Scalar\String_) {
                        $vars[$v][$expr->var->dim->value] = $this->valueKey($declaring, $expr->var->dim->value, $expr->expr);
                    } elseif ($v !== null) {
                        $vars[$v] = null;
                    }
                }
                continue;
            }
            if ($expr instanceof Node\Expr\AssignOp\Plus) {
                $v = $this->localVarName($expr->var);
                if ($v !== null && $expr->expr instanceof Node\Expr\Array_) {
                    $base = array_key_exists($v, $vars) ? $vars[$v] : [];
                    $add = $this->shapeOfArray($declaring, $expr->expr);
                    $vars[$v] = $this->mergeLeftWins($base, $add);
                } elseif ($v !== null) {
                    $vars[$v] = null;
                }
            }
        }

        $expr = $return->expr;
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && array_key_exists($expr->name, $vars)) {
            return $vars[$expr->name]; // `return $r;` — null when the tracked shape went dynamic
        }
        return $this->shapeOfExpr($declaring, $expr);
    }

    /**
     * The literal key map of an expression: a literal array, a `parent::jsonSerialize() + […]`, an array_merge of
     * provable parts, a bare parent/delegation, or null when dynamic.
     *
     * @return ?array<string, JsonSerializeKey>
     */
    private function shapeOfExpr(string $declaring, Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\Array_) {
            return $this->shapeOfArray($declaring, $expr);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Plus) {
            // `A + B` (and `array_merge` is a separate FuncCall): PHP `+` is LEFT-wins for shared keys.
            $left = $this->shapeOfExpr($declaring, $expr->left);
            $right = $this->shapeOfExpr($declaring, $expr->right);
            return $left === null || $right === null ? null : $this->mergeLeftWins($left, $right);
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->toString()) === 'array_merge') {
            // array_merge is RIGHT-wins for string keys; each argument must itself be a provable shape.
            $merged = [];
            foreach ($expr->getArgs() as $arg) {
                $part = $this->shapeOfExpr($declaring, $arg->value);
                if ($part === null) {
                    return null;
                }
                $merged = $this->mergeRightWins($merged, $part);
            }
            return $merged;
        }
        if ($this->isParentJsonSerialize($expr) && $expr instanceof Node\Expr\StaticCall) {
            return $this->parentProjection($declaring);
        }
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            // single-hop delegation: `return $this->entity->jsonSerialize();`
            if ($expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'jsonserialize'
                && $expr->var instanceof Node\Expr\PropertyFetch
                && $this->isThis($expr->var->var)
                && $expr->var->name instanceof Node\Identifier) {
                return $this->delegationProjection($declaring, $expr->var->name->toString());
            }
        }
        return null; // get_object_vars($this), a service call, a bare variable, a ternary, … ⇒ dynamic
    }

    /**
     * @return ?array<string, JsonSerializeKey>
     */
    private function shapeOfArray(string $declaring, Node\Expr\Array_ $array): ?array
    {
        $map = [];
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            // `...parent::jsonSerialize()` spread ⇒ merge the provable parent keys.
            if ($item->unpack) {
                if ($item->value instanceof Node\Expr\StaticCall && $this->isParentJsonSerialize($item->value)) {
                    $parent = $this->parentProjection($declaring);
                    if ($parent === null) {
                        return null;
                    }
                    $map = $this->mergeRightWins($map, $parent); // spread is right-wins / additive
                    continue;
                }
                return null; // spread of anything else ⇒ dynamic keys
            }
            $keyNode = $item->key;
            if (!$keyNode instanceof Node\Scalar\String_) {
                return null; // a numeric / expression / auto-indexed key ⇒ not an object shape
            }
            $wire = $keyNode->value;
            $value = $this->valueKey($declaring, $wire, $item->value);
            if ($value === null) {
                return null; // an opaque value ⇒ the shape is not provable (§2)
            }
            $map[$wire] = $value;
        }
        return $map;
    }

    /**
     * One key's source for a literal-array value: a `$this->property` (bare or transformed), a property-derived
     * array_map, a literal scalar, or a nested literal array. null when the value is not statically mappable.
     */
    private function valueKey(string $declaring, string $wire, Node\Expr $value): ?JsonSerializeKey
    {
        $prop = $this->baseProperty($value);
        if ($prop !== null) {
            return new JsonSerializeKey(wireName: $wire, propertyName: $prop);
        }
        if ($value instanceof Node\Scalar\String_) {
            return new JsonSerializeKey(wireName: $wire, literalType: 'string');
        }
        if ($value instanceof Node\Scalar\LNumber) {
            return new JsonSerializeKey(wireName: $wire, literalType: 'int');
        }
        if ($value instanceof Node\Scalar\DNumber) {
            return new JsonSerializeKey(wireName: $wire, literalType: 'float');
        }
        if ($value instanceof Node\Expr\ConstFetch && $value->name instanceof Node\Name) {
            return match (strtolower($value->name->toString())) {
                'true' => new JsonSerializeKey(wireName: $wire, literalType: 'bool'),
                'false' => new JsonSerializeKey(wireName: $wire, literalType: 'bool'),
                'null' => new JsonSerializeKey(wireName: $wire, literalType: 'null'),
                default => null,
            };
        }
        if ($value instanceof Node\Expr\Array_) {
            $nested = $this->shapeOfArray($declaring, $value);
            if ($nested === null) {
                return null;
            }
            return new JsonSerializeKey(wireName: $wire, nested: array_values($nested));
        }
        return null; // arbitrary method call / service result / bare variable / ternary ⇒ opaque (§2)
    }

    /**
     * The `$this->X` a value derives from: a bare property fetch, a method/nullsafe-method chain whose receiver
     * bottoms at `$this->X`, an array access on `$this->X`, or the array argument of an `array_map(fn …, $this->X)`.
     * null when the value is not tied to a single `$this->property`.
     */
    private function baseProperty(Node\Expr $expr): ?string
    {
        if (($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch)
            && $this->isThis($expr->var)
            && $expr->name instanceof Node\Identifier) {
            return $expr->name->toString();
        }
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            return $this->baseProperty($expr->var); // `$this->date->format(…)` ⇒ recurse to `$this->date`
        }
        if ($expr instanceof Node\Expr\ArrayDimFetch) {
            return $this->baseProperty($expr->var); // `$this->map['k']` ⇒ recurse to `$this->map`
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->toString()) === 'array_map') {
            foreach ($expr->getArgs() as $arg) {
                $p = $this->baseProperty($arg->value);
                if ($p !== null) {
                    return $p; // `array_map(fn $x => …, $this->tags)` ⇒ the property argument
                }
            }
        }
        return null;
    }

    private function isThis(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Variable && is_string($expr->name) && strtolower($expr->name) === 'this';
    }

    private function localVarName(Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Expr\Variable && is_string($expr->name) ? $expr->name : null;
    }

    private function isParentJsonSerialize(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\StaticCall
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'jsonserialize'
            && $expr->class instanceof Node\Name
            && strtolower($expr->class->getLast()) === 'parent';
    }

    /**
     * @param ?array<string, JsonSerializeKey> $left
     * @param ?array<string, JsonSerializeKey> $right
     * @return ?array<string, JsonSerializeKey>  left-wins (`+` / `+=`): existing keys keep their value/position
     */
    private function mergeLeftWins(?array $left, ?array $right): ?array
    {
        if ($left === null || $right === null) {
            return null;
        }
        $out = $left;
        foreach ($right as $k => $v) {
            if (!array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string, JsonSerializeKey> $left
     * @param array<string, JsonSerializeKey> $right
     * @return array<string, JsonSerializeKey>  right-wins (array_merge / spread): existing keys take the new value
     */
    private function mergeRightWins(array $left, array $right): array
    {
        $out = $left;
        foreach ($right as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * The provable shape of the parent's effective jsonSerialize() (the keys `parent::jsonSerialize()` yields),
     * or null when there is no parent / the parent shape is not provable / the chain is cyclic.
     *
     * @return ?array<string, JsonSerializeKey>
     */
    private function parentProjection(string $declaring): ?array
    {
        $parent = (new ReflectionClass($declaring))->getParentClass();
        if ($parent === false) {
            return null;
        }
        return $this->projectionKeys($parent->getName(), 'cyclic jsonSerialize parent chain at ' . $declaring);
    }

    /**
     * The provable shape of a delegated `$this->$prop->jsonSerialize()` (single hop), or null when the property
     * is not a class / not JsonSerializable / not provable / cyclic.
     *
     * @return ?array<string, JsonSerializeKey>
     */
    private function delegationProjection(string $declaring, string $prop): ?array
    {
        try {
            $reflection = new ReflectionClass($declaring);
            if (!$reflection->hasProperty($prop)) {
                return null;
            }
        } catch (\ReflectionException) {
            return null;
        }
        $type = $this->firstClassName($reflection->getProperty($prop)->getType());
        if ($type === null) {
            return null;
        }
        return $this->projectionKeys($type, 'cyclic jsonSerialize delegation chain at ' . $declaring);
    }

    /**
     * Recurse into {@see project()} for a parent/delegation target with a cycle guard; return its key map or null.
     *
     * @return ?array<string, JsonSerializeKey>
     */
    private function projectionKeys(string $class, string $cycleReason): ?array
    {
        if (isset($this->stack[$class])) {
            return null; // cyclic ⇒ not provable
        }
        $this->stack[$class] = true;
        try {
            $projection = $this->project($class);
        } finally {
            unset($this->stack[$class]);
        }
        if (!$projection->provable) {
            return null;
        }
        $map = [];
        foreach ($projection->keys as $key) {
            $map[$key->wireName] = $key;
        }
        return $map;
    }

    private function firstClassName(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $nested) {
                $name = $this->firstClassName($nested);
                if ($name !== null) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * Parse + name-resolve a file ONCE, memoized by absolute path; failure ⇒ null (no throw).
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
            return $this->resolvedAst[$file] = null;
        }
        try {
            $stmts = $this->parser()->parse($source);
        } catch (\Throwable) {
            $stmts = null;
        }
        if ($stmts === null) {
            return $this->resolvedAst[$file] = null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        return $this->resolvedAst[$file] = $traverser->traverse($stmts);
    }
}
