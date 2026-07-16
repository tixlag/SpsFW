<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Router\DICacheBuilder;
use SpsFWTest\CompileFixtures\Clean\FlowCreateDto;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/clean/FlowCreateDto.php';

/**
 * Шаг 5: DICacheBuilder compile-only API (plan §11.5). It builds and RETURNS the DI map + job registry WITHOUT
 * writing the production cache and WITHOUT calling setCompiledMap() on the production singleton. The legacy
 * compile() stays a compatibility wrapper that DOES write + mutate (and now requires a container).
 */

$tmpCache = sys_get_temp_dir() . '/spsfw_di_' . bin2hex(random_bytes(8));
mkdir($tmpCache, 0777, true);

// ============================================================================
// The compile-only path needs NO container and writes NO production files.
// ============================================================================
$builder = new DICacheBuilder(null, $tmpCache); // null container — the engine path
$diag = new CompileDiagnostics();
$result = $builder->compileOnly([FlowCreateDto::class], $diag);

assert_true(array_key_exists('compiled', $result), 'compileOnly returns a compiled map');
assert_true(array_key_exists('jobs', $result), 'compileOnly returns a job registry');
assert_true(array_key_exists(FlowCreateDto::class, $result['compiled']), 'compileOnly analyzed the fixture DTO');
assert_true(!$diag->hasErrors(), 'compileOnly on a clean fixture reports no errors');

// NO production cache files were written.
assert_true(!is_file($tmpCache . '/compiled_di.php'), 'compileOnly writes NO compiled_di.php');
assert_true(!is_file($tmpCache . '/job_registry.php'), 'compileOnly writes NO job_registry.php');

// ============================================================================
// The production singleton is NOT mutated. Proof: the fixture is unresolvable via the singleton BEFORE, and
// STAYS unresolvable AFTER compileOnly built its map — because setCompiledMap() was never called on the singleton.
// ============================================================================
$singleton = DIContainer::getInstance($tmpCache);
assert_same(null, $singleton->get(FlowCreateDto::class), 'before: fixture is not resolvable via the production singleton');

// compileOnly just built a map containing FlowCreateDto — but it must NOT push it into the singleton.
$builder2 = new DICacheBuilder(null, $tmpCache);
$builder2->compileOnly([FlowCreateDto::class], new CompileDiagnostics());

assert_same(null, $singleton->get(FlowCreateDto::class), 'after: singleton STILL not mutated — compileOnly did not call setCompiledMap()');
assert_same($singleton, DIContainer::getInstance($tmpCache), 'the production singleton keeps its identity');

// ============================================================================
// The legacy compile() is the ONLY path that writes the production cache + mutates the singleton, and it now
// REQUIRES a container (it throws without one). This keeps the engine path and the production path distinct.
// ============================================================================
$legacy = new DICacheBuilder(null, $tmpCache);
$threw = false;
try {
    $legacy->compile([FlowCreateDto::class]);
} catch (BaseException $e) {
    $threw = true;
    assert_true(str_contains($e->getMessage(), 'DIContainer'), 'compile() message explains it needs a container');
}
assert_true($threw, 'legacy compile() WITHOUT a container throws (only it writes cache + mutates the singleton)');

// ============================================================================
// The compile-only path is ROBUST: a single unanalyzable class is reported as an ERROR diagnostic and skipped,
// instead of aborting the whole build (the legacy path throws on the first such class).
// ============================================================================
$robustDiag = new CompileDiagnostics();
$robust = new DICacheBuilder(null, $tmpCache);
$out = $robust->compileOnly([FlowCreateDto::class, 'NoSuch\\Class\\At\\All'], $robustDiag);
assert_true($robustDiag->hasErrors(), 'compileOnly reports an ERROR for an unanalyzable class');
assert_true(array_key_exists(FlowCreateDto::class, $out['compiled']), 'compileOnly still analyzed the GOOD class alongside the bad one');

// cleanup
$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($dir);
};
$rrm($tmpCache);
echo "DICacheBuilderCompileOnly passed\n";
