<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Introspection\ResponseAstAnalyzer;
use SpsFW\Core\Compile\Introspection\SuccessInference;
use SpsFW\Tests\Compile\Introspection\Fixtures\ResponseAstAliasFixtureController;
use SpsFW\Tests\Compile\Introspection\Fixtures\ResponseAstAltController;
use SpsFW\Tests\Compile\Introspection\Fixtures\ResponseAstFixtureController;
use SpsFW\Tests\Compile\Introspection\Fixtures\ResponseAstTraitConsumer;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/ResponseAstFixtureController.php';
require_once __DIR__ . '/fixtures/ResponseAstAliasFixtureController.php';

/**
 * M8b + fix-pass: ResponseAstAnalyzer — conservative AST inference of the SUCCESS body from a controller action.
 * One assertion per case A–F, plus the non-definite guards (divergent / opaque / mapped), literal success
 * statuses (201 positional & named, 204), the statusConflict guard (same DTO, different statuses — D3), and the
 * D5 location guarantees (same-named method in a different class, a trait-defined method, and a Response alias).
 */
$analyzer = new ResponseAstAnalyzer();
$fixture = ResponseAstFixtureController::class;

$success = static function (string $class, string $method) use ($analyzer): SuccessInference {
    return $analyzer->inferSuccess(new ReflectionMethod($class, $method));
};
$fixSuccess = static function (string $method) use ($analyzer, $fixture): SuccessInference {
    return $analyzer->inferSuccess(new ReflectionMethod($fixture, $method));
};

$user = 'SpsFW\Tests\Compile\Introspection\Fixtures\AstUserDto';
$item = 'SpsFW\Tests\Compile\Introspection\Fixtures\AstItemDto';

// A — direct new.
$a = $fixSuccess('caseA');
assert_true($a->definite, 'A: direct Response::json(new Dto()) is definite');
assert_same($user, $a->class, 'A: resolves AstUserDto');
assert_same(false, $a->collection, 'A: single object, not a collection');

// B — local variable assigned a DTO.
$b = $fixSuccess('caseB');
assert_true($b->definite, 'B: var-assigned DTO is definite');
assert_same($user, $b->class, 'B: resolves AstUserDto via the variable assignment');

// C — homogeneous literal list.
$c = $fixSuccess('caseC');
assert_true($c->definite, 'C: homogeneous literal list is definite');
assert_same($item, $c->class, 'C: resolves AstItemDto');
assert_same(true, $c->collection, 'C: detected as a collection');

// D — same DTO across branches.
$d = $fixSuccess('caseD');
assert_true($d->definite, 'D: same-DTO branches converge');
assert_same($user, $d->class, 'D: resolves AstUserDto');

// E — error/throw branches ignored; success body still derived.
$e = $fixSuccess('caseE');
assert_true($e->definite, 'E: error branch ignored, success still definite');
assert_same($user, $e->class, 'E: resolves AstUserDto past the Response::error branch');

// F — callee return type (single hop, same class).
$f = $fixSuccess('caseF');
assert_true($f->definite, 'F: callee declared return type inferred');
assert_same($user, $f->class, 'F: resolves AstUserDto via makeUser(): AstUserDto');

// Non-definite guards.
assert_true(!$fixSuccess('divergent')->definite, 'divergent success branches ⇒ non-definite');
assert_true(!$fixSuccess('opaque')->definite, 'loop-built array ⇒ non-definite');
assert_true(!$fixSuccess('mapped')->definite, 'array_map / callback ⇒ non-definite');

// Literal success statuses.
assert_same(201, $fixSuccess('created')->status, '201 via the positional json status arg');
$cn = $fixSuccess('createdNamed');
assert_true($cn->definite && $cn->status === 201, '201 via the named status: arg');
$nc = $fixSuccess('noContent');
assert_same(204, $nc->status, '204 via Response::noContent()');
assert_same(null, $nc->class, '204 ⇒ empty body (no class)');
assert_true($nc->definite, '204 ⇒ definite empty body');

// An error-only method has no analyzable success return.
assert_true(!$fixSuccess('notFound')->definite, 'error-only method ⇒ no success body inferred');

// D3 — same DTO, different success statuses: the SCHEMA is definite, but the STATUS is not (statusConflict=true,
// status=null). Order-independent — never collapses to the last branch's status.
$conflict = $fixSuccess('sameDtoDifferentStatus');
assert_true($conflict->definite, 'same-DTO-different-status: schema is still definite');
assert_same($user, $conflict->class, 'same-DTO-different-status: resolves AstUserDto');
assert_same(null, $conflict->status, 'differing branch statuses ⇒ no unambiguous status');
assert_true($conflict->statusConflict, 'differing branch statuses ⇒ statusConflict=true');

// D5 — a same-named method in a DIFFERENT class resolves to THAT class's body (FQCN location, not short name).
assert_same($user, $success(ResponseAstFixtureController::class, 'caseA')->class, 'Fixture::caseA ⇒ AstUserDto');
assert_same($item, $success(ResponseAstAltController::class, 'caseA')->class, 'Alt::caseA ⇒ AstItemDto (same name, different class, never mixed)');

// D5 — a method defined in a used TRAIT resolves via the trait's FQCN (reflection reports the trait).
assert_same($user, $success(ResponseAstTraitConsumer::class, 'traitMethod')->class, 'a trait-defined method resolves via the trait FQCN');

// D5 — Response recognized through an ALIAS (NameResolver, not the literal short name).
assert_same(
    'SpsFW\Tests\Compile\Introspection\Fixtures\AliasUserDto',
    $success(ResponseAstAliasFixtureController::class, 'aliased')->class,
    'a `use ...Response as ApiResponse` alias resolves to the Response FQCN',
);

// Parse-failure / not-analyzable safety: a method whose body the analyzer cannot locate must yield a
// non-definite result WITHOUT throwing (the eval-defined class has no resolvable ClassMethod in any file).
eval('namespace SpsFW\Tests\Compile\Introspection\Fixtures; final class EvalOnlyController { public function go(): \\SpsFW\\Core\\Http\\Response { return \\SpsFW\\Core\\Http\\Response::json(new AstUserDto()); } }');
$evalInf = $analyzer->inferSuccess(new ReflectionMethod('SpsFW\Tests\Compile\Introspection\Fixtures\EvalOnlyController', 'go'));
assert_true(!$evalInf->definite, 'an unanalyzable method is non-definite (no throw)');

echo "Response AST analyzer passed\n";
