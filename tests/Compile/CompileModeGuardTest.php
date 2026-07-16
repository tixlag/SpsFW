<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileMode;
use SpsFW\Core\Compile\RuntimeCompileGate;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Router\PathManager;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * A Router spy that records whether controller discovery (scanControllers) was reached. Named uniquely
 * (ModeFailSpyRouter) so it never collides with the GuardSpyRouter that RuntimeEntryGuardsTest defines later in the
 * one shared test process.
 */
class ModeFailSpyRouter extends Router
{
    public static bool $scanned = false;

    protected function scanControllers(): void
    {
        self::$scanned = true;
        // Intentionally do NOT call parent(): the test asserts WHETHER discovery was reached, not what it found.
    }
}

/**
 * Шаг 6a (plan §11.4) + Шаг 6b (mode-contract closure): the single typed compile-mode source and the
 * explicit-rebuild policy gate.
 *
 *  - CompileMode is the ONLY reader of SPSFW_COMPILE_MODE; RuntimeCompileGate is the ONLY reader of APP_ENV for the
 *    rebuild decision. Both default to a backward-compatible, production-safe state (legacy / non-dev) so existing
 *    applications change nothing until they explicitly opt into 'managed'.
 *  - MODE-CONTRACT (Шаг 6b #1): an UNSET/EMPTY value ⇒ Legacy (full BC); a NON-EMPTY value that is NOT exactly
 *    'legacy' or 'managed' ⇒ an immediate, EXPLICIT InvalidArgumentException (no silent Legacy fallback). The single
 *    resolution point is CompileMode::fromString() (used by current() and the CLI/preload), and an invalid mode
 *    fails BEFORE Router discovery/compile.
 *
 * Runs in the one shared test process, so it captures the inbound env (+ the PathManager / DIContainer singletons),
 * mutates them for the cases under test, and restores them at the end (leaving the process in the legacy/BC default
 * for every later suite).
 */

$origMode = $_ENV['SPSFW_COMPILE_MODE'] ?? (getenv('SPSFW_COMPILE_MODE') ?: '');
$origAppEnv = isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : (getenv('APP_ENV') ?: null);
$origProjectRoot = $_ENV['SPSFW_PROJECT_ROOT'] ?? (getenv('SPSFW_PROJECT_ROOT') ?: null);

$setEnv = static function (string $mode, ?string $appEnv): void {
    if ($mode === '') {
        unset($_ENV['SPSFW_COMPILE_MODE']);
        putenv('SPSFW_COMPILE_MODE');
    } else {
        $_ENV['SPSFW_COMPILE_MODE'] = $mode;
        putenv('SPSFW_COMPILE_MODE=' . $mode);
    }
    if ($appEnv === null) {
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');
    } else {
        $_ENV['APP_ENV'] = $appEnv;
        putenv('APP_ENV=' . $appEnv);
    }
};

// Drop the static singletons that Router construction mutates, so the "invalid mode before discovery" scenario starts
// from a clean PathManager / DIContainer and does not leak into later suites.
$resetSingletons = static function (): void {
    $pm = new ReflectionClass(PathManager::class);
    foreach (['projectRoot', 'libraryRoot'] as $name) {
        $p = $pm->getProperty($name);
        $p->setAccessible(true);
        $p->setValue(null, null);
    }
    $diProp = (new ReflectionClass(DIContainer::class))->getProperty('instance');
    $diProp->setAccessible(true);
    $diProp->setValue(null, null);
};

$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? $rrm($path) : @unlink($path);
    }
    @rmdir($dir);
};

try {
    // ============================================================================
    // CompileMode: default is Legacy (full BC); 'managed' opts in; NON-EMPTY unknown ⇒ explicit error (no fallback).
    // ============================================================================
    $setEnv('', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'default mode (env unset/empty) is Legacy — full BC');

    $setEnv('managed', null);
    assert_same(CompileMode::Managed, CompileMode::current(), 'SPSFW_COMPILE_MODE=managed resolves to Managed');
    assert_true(CompileMode::current()->isManaged(), 'Managed::isManaged() true');
    assert_true(!CompileMode::current()->isLegacy(), 'Managed::isLegacy() false');

    $setEnv('legacy', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'SPSFW_COMPILE_MODE=legacy resolves to Legacy');
    assert_true(CompileMode::current()->isLegacy(), 'Legacy::isLegacy() true');
    assert_true(!CompileMode::current()->isManaged(), 'Legacy::isManaged() false');

    // The SINGLE resolution point: fromString. empty ⇒ Legacy; 'legacy'/'managed' ⇒ the matching case; anything else
    // ⇒ InvalidArgumentException. current() and the CLI/preload resolve through it, so the rule lives in one place.
    assert_same(CompileMode::Legacy, CompileMode::fromString(''), 'fromString: empty ⇒ Legacy');
    assert_same(CompileMode::Legacy, CompileMode::fromString('legacy'), 'fromString: legacy ⇒ Legacy');
    assert_same(CompileMode::Managed, CompileMode::fromString('managed'), 'fromString: managed ⇒ Managed');

    // A NON-EMPTY value that is NOT exactly 'legacy'/'managed' is an EXPLICIT error — no silent Legacy fallback. A
    // typo ('maanged'), wrong case ('MANAGED'), an unrelated value ('production'), or padded whitespace must surface
    // as a deployment failure, never quietly degrade to lazy behavior.
    foreach (['MANAGED', 'production', ' managed ', 'maanged'] as $bad) {
        $setEnv($bad, null);
        $threw = false;
        try {
            CompileMode::current();
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            assert_true(str_contains($e->getMessage(), $bad), "invalid mode '$bad' named in the error message");
            assert_true(str_contains($e->getMessage(), 'legacy'), "invalid-mode error lists 'legacy'");
            assert_true(str_contains($e->getMessage(), 'managed'), "invalid-mode error lists 'managed'");
        }
        assert_true($threw, "non-empty unknown mode '$bad' ⇒ InvalidArgumentException (no Legacy fallback)");
    }

    // current() re-reads on every call: a process that flips the env observes the change with no cached stale value.
    $setEnv('legacy', null);
    assert_true(CompileMode::current()->isLegacy(), 'mode read #1 is legacy');
    $setEnv('managed', null);
    assert_true(CompileMode::current()->isManaged(), 'mode read #2 is managed (no stale cached value between calls)');

    // ============================================================================
    // INVALID MODE FAILS BEFORE ROUTER DISCOVERY/COMPILE (plan Шаг 6b #1).
    // Router::loadRoutes() resolves the mode as its FIRST statement (CompileMode::current()), strictly before
    // scanControllers(); an invalid value therefore surfaces with ZERO controller discovery / cache writing /
    // reflection. Proved end-to-end via a spy Router whose scanControllers flips a static flag.
    // ============================================================================
    $setEnv('bogus', 'prod');
    $resetSingletons();
    $root = sys_get_temp_dir() . '/spsfw_modefail_' . bin2hex(random_bytes(8));
    mkdir($root . '/.cache', 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    $_ENV['SPSFW_PROJECT_ROOT'] = $root;
    putenv('SPSFW_PROJECT_ROOT=' . $root);
    $libRootProp = (new ReflectionClass(PathManager::class))->getProperty('libraryRoot');
    $libRootProp->setAccessible(true);
    $libRootProp->setValue(null, $root); // empty library root ⇒ no real controller dirs (moot: discovery never runs)

    ModeFailSpyRouter::$scanned = false;
    $caughtRouter = false;
    try {
        new ModeFailSpyRouter(null, true, $root . '/.cache');
    } catch (\InvalidArgumentException $e) {
        $caughtRouter = true;
        assert_true(str_contains($e->getMessage(), 'bogus'), 'Router construction surfaces the invalid mode value');
    }
    assert_true($caughtRouter, 'invalid SPSFW_COMPILE_MODE makes Router construction throw (not silently degrade)');
    assert_true(!ModeFailSpyRouter::$scanned, 'invalid mode fails BEFORE scanControllers() — no discovery/compile ran');
    assert_true(!is_file($root . '/.cache/compiled_routes.php'), 'invalid mode: no route cache written (no compile)');
    $rrm($root);
    $resetSingletons();

    // ============================================================================
    // RuntimeCompileGate::isDev(): the sole APP_ENV reader. Default (unset / any non-dev value) is NOT dev.
    // ============================================================================
    $setEnv('legacy', null);
    assert_true(!RuntimeCompileGate::isDev(), 'isDev false when APP_ENV unset (production-safe default)');
    $setEnv('legacy', 'prod');
    assert_true(!RuntimeCompileGate::isDev(), 'isDev false when APP_ENV=prod');
    $setEnv('legacy', 'dev');
    assert_true(RuntimeCompileGate::isDev(), 'isDev true only when APP_ENV=dev');

    // ============================================================================
    // assertAllowed(): legacy → always allowed (no-op, BC); managed → allowed ONLY in dev.
    // ============================================================================
    $setEnv('legacy', null);
    RuntimeCompileGate::assertAllowed('route');                 // no exception
    RuntimeCompileGate::assertAllowed('OpenAPI documentation'); // no exception
    $setEnv('legacy', 'prod');
    RuntimeCompileGate::assertAllowed('route');                 // legacy + prod still allowed
    assert_true(true, 'legacy + prod: explicit rebuild allowed (full backward compatibility)');

    $setEnv('managed', 'dev');
    RuntimeCompileGate::assertAllowed('route');                 // managed + dev allowed
    assert_true(true, 'managed + dev: explicit rebuild allowed');

    // managed + non-dev → BaseException pointing at the preload/Coordinator.
    $setEnv('managed', 'prod');
    $threw = false;
    try {
        RuntimeCompileGate::assertAllowed('route');
    } catch (BaseException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'managed'), 'managed-gate message mentions managed mode');
        assert_true(str_contains($e->getMessage(), 'route'), 'managed-gate message names the context (route)');
        assert_true(
            str_contains($e->getMessage(), 'preload') || str_contains($e->getMessage(), 'Coordinator'),
            'managed-gate message points at the preload / Coordinator',
        );
    }
    assert_true($threw, 'managed + prod: explicit route rebuild forbidden');

    $setEnv('managed', null); // APP_ENV unset = non-dev
    $threwOpenApi = false;
    try {
        RuntimeCompileGate::assertAllowed('OpenAPI documentation');
    } catch (BaseException $e) {
        $threwOpenApi = true;
        assert_true(str_contains($e->getMessage(), 'OpenAPI documentation'), 'managed-gate names OpenAPI documentation');
    }
    assert_true($threwOpenApi, 'managed + APP_ENV unset: explicit OpenAPI rebuild forbidden');

    echo "CompileModeGuard passed\n";
} finally {
    // Restore the process to the legacy/BC default so later suites observe their original environment + clean singletons.
    if ($origMode === '') {
        unset($_ENV['SPSFW_COMPILE_MODE']);
        putenv('SPSFW_COMPILE_MODE');
    } else {
        $_ENV['SPSFW_COMPILE_MODE'] = $origMode;
        putenv('SPSFW_COMPILE_MODE=' . $origMode);
    }
    if ($origAppEnv === null) {
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');
    } else {
        $_ENV['APP_ENV'] = $origAppEnv;
        putenv('APP_ENV=' . $origAppEnv);
    }
    if ($origProjectRoot === null) {
        unset($_ENV['SPSFW_PROJECT_ROOT']);
        putenv('SPSFW_PROJECT_ROOT');
    } else {
        $_ENV['SPSFW_PROJECT_ROOT'] = $origProjectRoot;
        putenv('SPSFW_PROJECT_ROOT=' . $origProjectRoot);
    }
    $resetSingletons();
}
