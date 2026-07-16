<?php

declare(strict_types=1);

use SpsFW\Core\Compile\DevCompileRunner;
use SpsFW\Core\Config;
use SpsFW\Core\Router\PathManager;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Step 5 fix-pass, required test #7: the generic framework CLI DEFAULT writes nothing.
 *
 *   - no flags ⇒ DRY-RUN (build + validate + report) ⇒ writes NOTHING (no compiled_* files, no staging, no lock,
 *     no manifest); the run report says "dry-run";
 *   - `--publish` is REFUSED without an application bootstrap (exit 2) and writes nothing — a generic framework CLI
 *     must not publish a DI cache built without the application's DI bindings;
 *   - a dry-run that finds an ERROR exits NON-ZERO (so CI/probes notice), and under `--strict` a WARNING does too.
 *
 * The CLI resolves its cache from SPSFW_PROJECT_ROOT (which must hold a composer.json); a throwaway temp project is
 * pointed at so the test asserts writes into an ISOLATED .cache, never the framework's. PathManager's projectRoot is
 * a private static cache, so it is reset (reflection) for the test and restored afterward.
 */

$tmpRoot = sys_get_temp_dir() . '/spsfw_cli_' . bin2hex(random_bytes(8));
mkdir($tmpRoot, 0777, true);
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

// A throwaway project (composer.json present so PathManager accepts it); src empty so app discovery contributes nothing
// (the library controllers are always discovered — the dry-run compiles them but must write nothing).
$mkProject = static function () use ($tmpRoot): string {
    $root = $tmpRoot . '/proj_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    mkdir($root . '/src', 0777, true);
    mkdir($root . '/.cache', 0777, true);
    // PathManager::getProjectRoot() runs realpath() on the configured root, so return the resolved path — otherwise a
    // symlinked sys_get_temp_dir() (/tmp) would make the cache land at a different prefix than $root . '/.cache'.
    return realpath($root);
};

// Reset/restore PathManager::$projectRoot + the SPSFW_PROJECT_ROOT env + Config::$bootstrapped around each main() call.
$pm = new ReflectionClass(PathManager::class);
$projectRootProp = $pm->getProperty('projectRoot');
$cfg = new ReflectionClass(Config::class);
$bootProp = $cfg->getProperty('bootstrapped');
$origProjectRoot = $projectRootProp->getValue();
$origEnv = $_ENV['SPSFW_PROJECT_ROOT'] ?? null;
$origBoot = $bootProp->getValue();

$pointAt = static function (string $root) use ($projectRootProp): void {
    $projectRootProp->setValue(null, null); // force PathManager to re-read SPSFW_PROJECT_ROOT
    $_ENV['SPSFW_PROJECT_ROOT'] = $root;
    putenv('SPSFW_PROJECT_ROOT=' . $root);
};
$restore = static function () use ($projectRootProp, $bootProp, $origProjectRoot, $origBoot, $origEnv): void {
    $projectRootProp->setValue(null, $origProjectRoot);
    $bootProp->setValue(null, $origBoot);
    if ($origEnv === null) {
        unset($_ENV['SPSFW_PROJECT_ROOT']);
        putenv('SPSFW_PROJECT_ROOT');
    } else {
        $_ENV['SPSFW_PROJECT_ROOT'] = $origEnv;
        putenv('SPSFW_PROJECT_ROOT=' . $origEnv);
    }
};

$cacheIsEmpty = static function (string $cache): bool {
    if (!is_dir($cache)) {
        return true;
    }
    foreach (scandir($cache) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        return false; // any entry ⇒ not empty
    }
    return true;
};

// ============================================================================
// 1. DEFAULT = dry-run ⇒ writes NOTHING; the report says dry-run.
// ============================================================================
$proj1 = $mkProject();
$bootProp->setValue(null, false); // the bare CLI is never bootstrapped
$pointAt($proj1);
ob_start();
$exit1 = DevCompileRunner::main(['bin/spsfw-compile.php']);
$out1 = ob_get_clean();
assert_true($cacheIsEmpty($proj1 . '/.cache'), 'default CLI run writes nothing to .cache');
assert_true(str_contains($out1, 'dry-run'), 'default CLI run is a dry-run (report says so)');
assert_true(!is_file($proj1 . '/.cache/compiled_routes.php'), 'default dry-run: no route cache');
assert_true(!is_file($proj1 . '/.cache/.compile_manifest.php'), 'default dry-run: no manifest');

// ============================================================================
// 2. --publish WITHOUT bootstrap ⇒ REFUSED (exit 2) and writes nothing.
// ============================================================================
$proj2 = $mkProject();
$bootProp->setValue(null, false); // explicitly NOT bootstrapped
$pointAt($proj2);
ob_start();
$exit2 = DevCompileRunner::main(['bin/spsfw-compile.php', '--publish']);
ob_end_clean();
assert_same(2, $exit2, '--publish without bootstrap is refused with exit code 2');
assert_true($cacheIsEmpty($proj2 . '/.cache'), '--publish (refused) writes nothing');

// ============================================================================
// 3. A dry-run that finds an ERROR exits NON-ZERO. A fresh dup-route controller is WRITTEN into the temp project's
//    src (a unique class, not the shared fixture — RouteMetadataCompiler require_once's each *Controller.php it
//    discovers, so reusing the already-loaded fixture class from another path would throw "cannot redeclare"). Two
//    methods on the same METHOD:path guarantee an ERROR regardless of the always-discovered library controllers.
// ============================================================================
$proj3 = $mkProject();
file_put_contents($proj3 . '/src/CliDupController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace TempCliProbe\Dup;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;

final class CliDupController
{
    #[Route('/cli/dup', [HttpMethod::GET])]
    public function a(): void
    {
    }

    #[Route('/cli/dup', [HttpMethod::GET])]
    public function b(): void
    {
    }
}
PHP);
$bootProp->setValue(null, false);
$pointAt($proj3);
ob_start();
$exit3 = DevCompileRunner::main(['bin/spsfw-compile.php']);
ob_end_clean();
assert_true($exit3 !== 0, 'a dry-run with an ERROR exits non-zero');
assert_true($cacheIsEmpty($proj3 . '/.cache'), 'error dry-run still writes nothing');

// ============================================================================
// 4. With bootstrap, a clean --publish is NOT refused (exit != 2) — the guard keys on bootstrap, not on the flag.
//    Use the empty-src project so only library controllers are compiled; bootstrap is faked via reflection.
// ============================================================================
$proj4 = $mkProject();
$bootProp->setValue(null, true); // application bootstrap ran (preload called Config::init)
$pointAt($proj4);
ob_start();
$exit4 = DevCompileRunner::main(['bin/spsfw-compile.php', '--publish']);
ob_end_clean();
assert_true($exit4 !== 2, 'with bootstrap, --publish is NOT refused (exit != 2)');
$bootProp->setValue(null, false);

$restore();
$rrm($tmpRoot);
echo "DevCompileCli passed\n";
