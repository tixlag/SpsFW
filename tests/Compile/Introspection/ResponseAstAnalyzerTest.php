<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Introspection\ResponseAstAnalyzer;
use SpsFW\Tests\Compile\Introspection\Fixtures\ResponseAstFixtureController;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/ResponseAstFixtureController.php';

/**
 * M8b: ResponseAstAnalyzer — conservative AST inference of the success body and error statuses from a
 * controller action. One assertion per case A–F, plus the non-definite guards (divergent / opaque / mapped),
 * literal success statuses (201 positional & named, 204), and error inference (literal 404/409, dynamic).
 */
$analyzer = new ResponseAstAnalyzer();
$fixture = ResponseAstFixtureController::class;

$success = static function (string $method) use ($analyzer, $fixture): \SpsFW\Core\Compile\Introspection\SuccessInference {
    return $analyzer->inferSuccess(new ReflectionMethod($fixture, $method));
};

$user = 'SpsFW\Tests\Compile\Introspection\Fixtures\AstUserDto';
$item = 'SpsFW\Tests\Compile\Introspection\Fixtures\AstItemDto';

// A — direct new.
$a = $success('caseA');
assert_true($a->definite, 'A: direct Response::json(new Dto()) is definite');
assert_same($user, $a->class, 'A: resolves AstUserDto');
assert_same(false, $a->collection, 'A: single object, not a collection');

// B — local variable assigned a DTO.
$b = $success('caseB');
assert_true($b->definite, 'B: var-assigned DTO is definite');
assert_same($user, $b->class, 'B: resolves AstUserDto via the variable assignment');

// C — homogeneous literal list.
$c = $success('caseC');
assert_true($c->definite, 'C: homogeneous literal list is definite');
assert_same($item, $c->class, 'C: resolves AstItemDto');
assert_same(true, $c->collection, 'C: detected as a collection');

// D — same DTO across branches.
$d = $success('caseD');
assert_true($d->definite, 'D: same-DTO branches converge');
assert_same($user, $d->class, 'D: resolves AstUserDto');

// E — error/throw branches ignored; success body still derived.
$e = $success('caseE');
assert_true($e->definite, 'E: error branch ignored, success still definite');
assert_same($user, $e->class, 'E: resolves AstUserDto past the Response::error branch');

// F — callee return type (single hop, same class).
$f = $success('caseF');
assert_true($f->definite, 'F: callee declared return type inferred');
assert_same($user, $f->class, 'F: resolves AstUserDto via makeUser(): AstUserDto');

// Non-definite guards.
assert_true(!$success('divergent')->definite, 'divergent success branches ⇒ non-definite');
assert_true(!$success('opaque')->definite, 'loop-built array ⇒ non-definite');
assert_true(!$success('mapped')->definite, 'array_map / callback ⇒ non-definite');

// Literal success statuses.
assert_same(201, $success('created')->status, '201 via the positional json status arg');
$cn = $success('createdNamed');
assert_true($cn->definite && $cn->status === 201, '201 via the named status: arg');
assert_same(204, $success('noContent')->status, '204 via Response::noContent()');
assert_same(null, $success('noContent')->class, '204 ⇒ empty body (no class)');

// An error-only method has no analyzable success return.
assert_true(!$success('notFound')->definite, 'error-only method ⇒ no success body inferred');

// Error inference.
$errors = static function (string $method) use ($analyzer, $fixture): \SpsFW\Core\Compile\Introspection\ErrorInference {
    return $analyzer->inferErrors(new ReflectionMethod($fixture, $method));
};
assert_same([404], $errors('notFound')->literalStatuses, 'literal statusCode:404 inferred');
assert_same([409], $errors('errorMessageLiteral')->literalStatuses, 'literal errorMessage status inferred');
assert_true(!$errors('notFound')->hasDynamic, 'a literal error code is not dynamic');
assert_true($errors('dynamicError')->hasDynamic, 'a computed error status is dynamic');
assert_same([], $errors('caseA')->literalStatuses, 'no error calls ⇒ no literal statuses');

// Parse-failure / not-analyzable safety: a method whose body the analyzer cannot locate must yield a
// non-definite result WITHOUT throwing (the eval-defined class has no resolvable ClassMethod in any file).
eval('namespace SpsFW\Tests\Compile\Introspection\Fixtures; final class EvalOnlyController { public function go(): \\SpsFW\\Core\\Http\\Response { return \\SpsFW\\Core\\Http\\Response::json(new AstUserDto()); } }');
$evalInf = $analyzer->inferSuccess(new ReflectionMethod('SpsFW\Tests\Compile\Introspection\Fixtures\EvalOnlyController', 'go'));
assert_true(!$evalInf->definite, 'an unanalyzable method is non-definite (no throw)');

echo "Response AST analyzer passed\n";
