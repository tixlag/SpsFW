<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\CompileException;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 1 (M1): pins CompileDiagnostics — the error collector that all builders will emit through from M2 on.
 * Asserts the record shape, count, multi-line render, and throwOnErrors() contract (code = error count).
 */
$diag = new CompileDiagnostics();

assert_true(!$diag->hasErrors(), 'fresh collector has no errors');
assert_same(0, $diag->count(), 'fresh collector count is zero');
assert_same([], $diag->errors(), 'fresh collector errors list is empty');

// full-form error carries every optional location field — positional plan contract
// error(controller, method, dto, field, cause, fix)
$diag->error('App\\Ctl', 'show', 'App\\Dto', 'id', 'boom', 'do x');
assert_true($diag->hasErrors(), 'error recorded');
assert_same(1, $diag->count(), 'count reflects the single error');

$error = $diag->errors()[0];
assert_same('App\\Ctl', $error['controller'], 'controller stored');
assert_same('show', $error['method'], 'method stored');
assert_same('App\\Dto', $error['dto'], 'dto stored');
assert_same('id', $error['field'], 'field stored');
assert_same('boom', $error['cause'], 'cause stored');
assert_same('do x', $error['fix'], 'fix stored');

// cause-only call leaves the optional location fields null
$diag->error(null, null, null, null, 'just cause');
$second = $diag->errors()[1];
assert_same(null, $second['controller'], 'optional controller defaults null');
assert_same(null, $second['dto'], 'optional dto defaults null');
assert_same(null, $second['fix'], 'optional fix defaults null');
assert_same(2, $diag->count(), 'count reflects the second error');

// render: 1-based index, location fields listed, fix appended, location-less errors marked
$rendered = $diag->render();
assert_true(str_contains($rendered, '[1]'), 'render uses a 1-based index');
assert_true(str_contains($rendered, 'controller=App\\Ctl, method=show, dto=App\\Dto, field=id'), 'render lists present location fields');
assert_true(str_contains($rendered, '(fix: do x)'), 'render appends the fix hint');
assert_true(str_contains($rendered, '(no location)'), 'render marks location-less errors');

// throwOnErrors raises CompileException carrying the error count
$threw = false;
try {
    $diag->throwOnErrors();
} catch (CompileException $e) {
    $threw = true;
    assert_same(2, $e->getCode(), 'exception code equals the error count');
    assert_true(str_contains($e->getMessage(), '2 compile error(s)'), 'exception message names the count');
    assert_true(str_contains($e->getMessage(), 'boom'), 'exception message includes the rendered report');
}
assert_true($threw, 'throwOnErrors raises CompileException when errors are present');

// throwOnErrors on an empty collector is a no-op
$empty = new CompileDiagnostics();
$empty->throwOnErrors();
assert_true(!$empty->hasErrors(), 'empty collector stays empty after throwOnErrors');

echo "CompileDiagnostics passed\n";
