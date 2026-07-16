<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileMode;
use SpsFW\Core\Compile\RuntimeCompileGate;
use SpsFW\Core\Exceptions\BaseException;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 6a (plan §11.4): the single typed compile-mode source and the explicit-rebuild policy gate.
 *
 *  - CompileMode is the ONLY reader of SPSFW_COMPILE_MODE; RuntimeCompileGate is the ONLY reader of APP_ENV for the
 *    rebuild decision. Both default to a backward-compatible, production-safe state (legacy / non-dev) so existing
 *    applications change nothing until they explicitly opt into 'managed'.
 *
 * Runs in the one shared test process, so it captures the inbound env, mutates it for the cases under test, and
 * restores it at the end (leaving the process in the legacy/BC default for every later suite).
 */

$origMode = $_ENV['SPSFW_COMPILE_MODE'] ?? (getenv('SPSFW_COMPILE_MODE') ?: '');
$origAppEnv = isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : (getenv('APP_ENV') ?: null);

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

try {
    // ============================================================================
    // CompileMode: default is Legacy (full BC); 'managed' opts in; unknown/empty/garbage → Legacy (fail-safe).
    // ============================================================================
    $setEnv('', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'default mode (env unset) is Legacy — full BC');

    $setEnv('managed', null);
    assert_same(CompileMode::Managed, CompileMode::current(), 'SPSFW_COMPILE_MODE=managed resolves to Managed');
    assert_true(CompileMode::current()->isManaged(), 'Managed::isManaged() true');
    assert_true(!CompileMode::current()->isLegacy(), 'Managed::isLegacy() false');

    $setEnv('legacy', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'SPSFW_COMPILE_MODE=legacy resolves to Legacy');
    assert_true(CompileMode::current()->isLegacy(), 'Legacy::isLegacy() true');
    assert_true(!CompileMode::current()->isManaged(), 'Legacy::isManaged() false');

    // Garbage / wrong case / whitespace must NOT silently enable managed — fall back to the safe default.
    $setEnv('MANAGED', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'uppercase MANAGED not matched → Legacy (fail-safe)');
    $setEnv('production', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'unknown mode string → Legacy (fail-safe)');
    $setEnv(' managed ', null);
    assert_same(CompileMode::Legacy, CompileMode::current(), 'whitespace-padded value not trimmed → Legacy');

    // current() re-reads on every call: a process that flips the env observes the change with no cached stale value.
    $setEnv('legacy', null);
    assert_true(CompileMode::current()->isLegacy(), 'mode read #1 is legacy');
    $setEnv('managed', null);
    assert_true(CompileMode::current()->isManaged(), 'mode read #2 is managed (no stale cached value between calls)');

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
    // Restore the process to the legacy/BC default so later suites observe their original environment.
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
}
