<?php

declare(strict_types=1);

use SpsFW\Core\Bootstrap;
use SpsFW\Core\CoreUtilController;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Router\PathManager;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * A plain, dependency-free class resolvable from a prebuilt DI map entry. Used to exercise
 * Router::createControllerInstance() and the "first managed request" acceptance block without touching the host
 * framework/app class tree.
 */
class GuardFixtureNoop
{
}

/**
 * Router subclass that records whether the (expensive, real-tree-walking) scanControllers() was reached. It overrides
 * scanControllers() to set a flag and otherwise do nothing, so the guard LOGIC under test is exercised against the
 * real Router::loadRoutes()/createControllerInstance() without depending on the host discovery tree. Exposes the
 * protected routes array and createControllerInstance() so the procedural test can observe/trigger them.
 */
class GuardSpyRouter extends Router
{
    public bool $scanned = false;

    protected function scanControllers(): void
    {
        $this->scanned = true;
        // Intentionally do NOT call parent(): the test asserts on WHETHER scanControllers() was reached, independent
        // of real controller discovery. $this->routes stays empty.
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function createControllerInstancePublic(string $className): ?object
    {
        return $this->createControllerInstance($className);
    }
}

/**
 * Шаг 6a: runtime entrypoint guards across legacy / managed. For every framework entrypoint this proves that legacy
 * keeps the historic lazy behavior (full BC) and that managed fails fast (or allows only in dev) WITHOUT runtime
 * scanning / metadata-reflection / compile. The closing block proves the §11.6 acceptance criterion: the FIRST managed
 * HTTP request loads the prebuilt cache and never scans/compiles (cache file hashes unchanged, no DI/job rebuild).
 *
 * All filesystem effects live under isolated sys_get_temp_dir() trees. PathManager / DIContainer / Bootstrap static
 * singletons and the env are captured up front and restored in a finally block, so the one shared test process is left
 * in the legacy/BC default for every later suite.
 */

// --- inbound-state capture ---------------------------------------------------------------
$origMode = $_ENV['SPSFW_COMPILE_MODE'] ?? (getenv('SPSFW_COMPILE_MODE') ?: '');
$origAppEnv = isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : (getenv('APP_ENV') ?: null);
$origProjectRoot = $_ENV['SPSFW_PROJECT_ROOT'] ?? (getenv('SPSFW_PROJECT_ROOT') ?: null);

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

// env helpers ---------------------------------------------------------------------------
$setMode = static function (string $mode, ?string $appEnv = null) use (&$setMode): void {
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

// Reset every static singleton touched by Router construction / DI / Bootstrap so each scenario starts clean.
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
    $bsProp = (new ReflectionClass(Bootstrap::class))->getProperty('router');
    $bsProp->setAccessible(true);
    $bsProp->setValue(null, null);
};

// Build an isolated project tree and point PathManager at it (empty src + empty library root → discovery finds
// nothing, so no real scanning/reflection occurs; cache path == <root>/.cache).
$mkScenario = static function () use ($resetSingletons): array {
    $resetSingletons();
    $root = sys_get_temp_dir() . '/spsfw_guard_' . bin2hex(random_bytes(8));
    mkdir($root . '/src', 0777, true);
    mkdir($root . '/.cache', 0777, true);
    mkdir($root . '/libroot', 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    $_ENV['SPSFW_PROJECT_ROOT'] = $root;
    putenv('SPSFW_PROJECT_ROOT=' . $root);
    $libRoot = (new ReflectionClass(PathManager::class))->getProperty('libraryRoot');
    $libRoot->setAccessible(true);
    $libRoot->setValue(null, $root . '/libroot');
    return ['root' => $root, 'cache' => $root . '/.cache'];
};

$seedRoutes = static function (string $cache, array $routes = []): void {
    file_put_contents($cache . '/compiled_routes.php', "<?php\n\nreturn " . var_export($routes, true) . ";\n");
};
$seedDi = static function (string $cache, array $map = []): void {
    file_put_contents($cache . '/compiled_di.php', "<?php\n\nreturn " . var_export($map, true) . ";\n");
};

try {
    // ============================================================================
    // 1. Router::loadRoutes() — the implicit cache-miss path (useCache, createCache=false).
    // ============================================================================

    // managed + cache MISSING → fail-fast, NO scan, NO cache write.
    $setMode('managed');
    $s = $mkScenario();
    $threw = false;
    try {
        new GuardSpyRouter(null, true, $s['cache']);
    } catch (BaseException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'missing'), 'managed route-miss: message says missing');
        assert_true(str_contains($e->getMessage(), 'managed'), 'managed route-miss: message mentions managed');
    }
    assert_true($threw, 'managed + route cache missing → fail-fast (no runtime scan/compile)');
    assert_true(!is_file($s['cache'] . '/compiled_routes.php'), 'managed route-miss: no cache written (scan/createCache never ran)');
    $rrm($s['root']);

    // legacy + cache MISSING → scan + write (historic lazy behavior, full BC).
    $setMode('legacy');
    $s = $mkScenario();
    $router = new GuardSpyRouter(null, true, $s['cache']);
    assert_true($router->scanned, 'legacy route-miss: scanControllers() ran (lazy rebuild)');
    assert_true(is_file($s['cache'] . '/compiled_routes.php'), 'legacy route-miss: route cache written (BC)');
    assert_same([], $router->getRoutes(), 'legacy route-miss: empty discovery → empty routes');
    $rrm($s['root']);

    // managed + cache HIT → load from cache, NO scan.
    $setMode('managed');
    $s = $mkScenario();
    $seedRoutes($s['cache'], ['GET:/ping' => ['controller' => 'X', 'method' => 'ping', 'pattern' => '#^/ping$#', 'params' => [], 'middlewares' => [], 'access_rules' => []]]);
    $router = new GuardSpyRouter(null, true, $s['cache']);
    assert_true(!$router->scanned, 'managed route-hit: cache loaded, scanControllers() NOT called');
    assert_true(array_key_exists('GET:/ping', $router->getRoutes()), 'managed route-hit: route served from cache');
    $rrm($s['root']);

    // managed + cache INVALID → fail-fast (corrupt cache is a deployment failure), NO rebuild.
    $setMode('managed');
    $s = $mkScenario();
    file_put_contents($s['cache'] . '/compiled_routes.php', "<?php\nthrow new \Error('corrupt cache');\n");
    $threw = false;
    try {
        new GuardSpyRouter(null, true, $s['cache']);
    } catch (BaseException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'invalid'), 'managed invalid-cache: message says invalid');
    }
    assert_true($threw, 'managed + corrupt route cache → fail-fast (no runtime rebuild)');
    $rrm($s['root']);

    // legacy + cache INVALID → rebuild (scan + overwrite). Historic behavior preserved.
    $setMode('legacy');
    $s = $mkScenario();
    file_put_contents($s['cache'] . '/compiled_routes.php', "<?php\nthrow new \Error('corrupt cache');\n");
    $router = new GuardSpyRouter(null, true, $s['cache']);
    assert_true($router->scanned, 'legacy invalid-cache: scanControllers() ran (rebuild)');
    assert_true(is_array(require $s['cache'] . '/compiled_routes.php'), 'legacy invalid-cache: cache overwritten with a valid array');
    $rrm($s['root']);

    // ============================================================================
    // 1b. Explicit rebuild (Router::loadRoutes(createCache: true)) — the RuntimeCompileGate path.
    // ============================================================================

    // managed + non-dev → forbidden (the preload owns the artifacts).
    $setMode('managed', 'prod');
    $s = $mkScenario();
    $seedRoutes($s['cache']);
    $router = new GuardSpyRouter(null, true, $s['cache']);      // constructor: managed HIT, no scan
    assert_true(!$router->scanned, 'managed non-dev rebuild: construction served cache (hit)');
    $router->scanned = false;
    $threw = false;
    try {
        $router->loadRoutes(createCache: true);
    } catch (BaseException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'route'), 'managed non-dev rebuild: gate names route');
    }
    assert_true($threw, 'managed + prod explicit route rebuild → forbidden');
    assert_true(!$router->scanned, 'managed non-dev rebuild: gate threw before scanControllers()');

    // managed + dev → allowed (dev escape hatch).
    $setMode('managed', 'dev');
    $s = $mkScenario();
    $seedRoutes($s['cache']);
    $router = new GuardSpyRouter(null, true, $s['cache']);
    $router->scanned = false;
    $router->loadRoutes(createCache: true);
    assert_true($router->scanned, 'managed + dev explicit route rebuild → scanControllers() ran (allowed)');

    // legacy → always allowed.
    $setMode('legacy');
    $s = $mkScenario();
    $seedRoutes($s['cache']);
    $router = new GuardSpyRouter(null, true, $s['cache']);
    $router->scanned = false;
    $router->loadRoutes(createCache: true);
    assert_true($router->scanned, 'legacy explicit route rebuild → scanControllers() ran (BC, always allowed)');
    $rrm($s['root']);

    // ============================================================================
    // 2. Router::createControllerInstance() — lazy DI compile.
    // ============================================================================

    // managed + DI cache MISSING → fail-fast, NO lazy compile.
    $setMode('managed', 'prod');
    $s = $mkScenario();
    $seedRoutes($s['cache']);                                   // route cache hit so construction succeeds
    $router = new GuardSpyRouter(null, true, $s['cache']);
    assert_true(!is_file($s['cache'] . '/compiled_di.php'), 'managed DI-miss: precondition — no DI cache');
    $threw = false;
    try {
        $router->createControllerInstancePublic(GuardFixtureNoop::class);
    } catch (BaseException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'DI cache missing'), 'managed DI-miss: message names missing DI cache');
    }
    assert_true($threw, 'managed + missing DI cache → fail-fast (no runtime lazy DI compile)');
    assert_true(!is_file($s['cache'] . '/compiled_di.php'), 'managed DI-miss: DI cache still absent (compileDI never ran)');
    assert_true(!is_file($s['cache'] . '/job_registry.php'), 'managed DI-miss: no job registry written');
    $rrm($s['root']);

    // legacy + DI cache MISSING → lazy compile (historic behavior).
    $setMode('legacy');
    $s = $mkScenario();
    $seedRoutes($s['cache']);
    $router = new GuardSpyRouter(null, true, $s['cache']);
    assert_true(!is_file($s['cache'] . '/compiled_di.php'), 'legacy DI-miss: precondition — no DI cache');
    $didRun = false;
    try {
        $router->createControllerInstancePublic(GuardFixtureNoop::class);
        $didRun = true;
    } catch (\Throwable $e) {
        // surface unexpected failures with context
        throw $e;
    }
    assert_true($didRun, 'legacy DI-miss: no exception (lazy compile ran)');
    assert_true(is_file($s['cache'] . '/compiled_di.php'), 'legacy DI-miss: DI cache built lazily');
    assert_true(is_file($s['cache'] . '/job_registry.php'), 'legacy DI-miss: job registry built');
    $rrm($s['root']);

    // ============================================================================
    // 3. Bootstrap::getRouter() — DI rebuild on bootstrap.
    // ============================================================================

    // managed → Bootstrap does NOT rebuild DI (the preload already built it).
    $setMode('managed', 'prod');
    $s = $mkScenario();
    $seedRoutes($s['cache']);                                   // Router() construction: managed HIT, no scan
    assert_true(!is_file($s['cache'] . '/compiled_di.php'), 'managed Bootstrap: precondition — no DI cache');
    $router = Bootstrap::getRouter();
    assert_true(!is_file($s['cache'] . '/compiled_di.php'), 'managed Bootstrap: DI cache NOT rebuilt (preload owns it)');
    assert_same($router, Bootstrap::getRouter(), 'managed Bootstrap: router singleton stable across calls');
    $rrm($s['root']);

    // legacy → Bootstrap rebuilds DI (historic behavior).
    $setMode('legacy');
    $s = $mkScenario();
    $seedRoutes($s['cache']);
    assert_true(!is_file($s['cache'] . '/compiled_di.php'), 'legacy Bootstrap: precondition — no DI cache');
    Bootstrap::getRouter();
    assert_true(is_file($s['cache'] . '/compiled_di.php'), 'legacy Bootstrap: DI cache rebuilt (BC)');
    $rrm($s['root']);

    // ============================================================================
    // 4. CoreUtilController HTTP rebuild endpoints — RuntimeCompileGate as the first line of each.
    // (legacy/dev allowance is proven by the gate unit test + the loadRoutes(createCache) cases above; here we prove
    //  the gate is wired into every endpoint so a managed non-dev request is refused before any rebuild work.)
    // ============================================================================
    $setMode('managed', 'prod');
    $ctrl = (new ReflectionClass(CoreUtilController::class))->newInstanceWithoutConstructor();
    foreach (['updateRoutes', 'updateOnlyRoutes', 'coreUpdate', 'updateDocs'] as $method) {
        $threw = false;
        try {
            (new ReflectionMethod(CoreUtilController::class, $method))->invoke($ctrl);
        } catch (BaseException $e) {
            $threw = true;
            assert_true(str_contains($e->getMessage(), 'managed'), "CoreUtilController::{$method} gate message mentions managed");
        }
        assert_true($threw, "CoreUtilController::{$method} → forbidden in managed+prod (gate wired as the first line)");
    }

    // ============================================================================
    // 6. DocsUtil::updateDocs() — independent production OpenAPI build.
    // ============================================================================
    // managed + non-dev → refused at the gate (never reaches swagger-php).
    $setMode('managed', 'prod');
    $s = $mkScenario();
    $threw = false;
    try {
        DocsUtil::updateDocs();
    } catch (BaseException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'OpenAPI documentation'), 'DocsUtil managed-gate names OpenAPI documentation');
        assert_true(str_contains($e->getMessage(), 'managed'), 'DocsUtil managed-gate mentions managed');
    }
    assert_true($threw, 'managed + prod: DocsUtil independent build forbidden');
    assert_true(!is_file($s['root'] . '/.cache/swagger/openapi.yml'), 'managed DocsUtil: no openapi written (refused before swagger-php)');
    $rrm($s['root']);

    // legacy → the gate is a no-op and the (unchanged) swagger-php flow runs over the isolated empty discovery dirs.
    $setMode('legacy');
    $s = $mkScenario();
    $gateFired = false;
    $otherError = null;
    try {
        DocsUtil::updateDocs();
    } catch (BaseException $e) {
        $gateFired = str_contains($e->getMessage(), 'managed');
        if (!$gateFired) {
            $otherError = $e;
        }
    } catch (\Throwable $e) {
        $otherError = $e;
    }
    assert_true(!$gateFired, 'legacy DocsUtil: managed gate did NOT fire (allowed, BC)');
    assert_true(
        $otherError === null,
        'legacy DocsUtil: swagger-php flow ran cleanly over isolated empty discovery — ' . ($otherError?->getMessage() ?? ''),
    );
    assert_true(is_file($s['root'] . '/.cache/swagger/openapi.yml'), 'legacy DocsUtil: openapi.yml produced (swagger-php flow unchanged)');
    $rrm($s['root']);

    // ============================================================================
    // ACCEPTANCE (plan §11.6): the FIRST managed HTTP request loads the prebuilt cache and does NOT
    // scan / metadata-reflect / compile. Pre-seed BOTH caches (the preload contract), construct the router and
    // resolve a controller; assert no scan ran, the route + DI cache files are byte-identical afterwards, and the
    // DI compile produced no job_registry / staging side-effects.
    // ============================================================================
    $setMode('managed', 'prod');
    $s = $mkScenario();
    $seedRoutes($s['cache'], [
        'GET:/health' => [
            'controller' => GuardFixtureNoop::class,
            'httpMethod' => 'GET',
            'method' => 'health',
            'rawPath' => '/health',
            'pattern' => '#^/health$#',
            'params' => [],
            'middlewares' => [],
            'access_rules' => [],
            'dtos' => [],
            'php_ini_settings' => [],
        ],
    ]);
    $seedDi($s['cache'], [GuardFixtureNoop::class => ['class' => GuardFixtureNoop::class, 'args' => []]]);

    $routesHashBefore = md5_file($s['cache'] . '/compiled_routes.php');
    $diHashBefore = md5_file($s['cache'] . '/compiled_di.php');

    // Constructor → Router::loadRoutes() → managed HIT → require + return, no scan.
    $router = new GuardSpyRouter(null, true, $s['cache']);
    assert_true(!$router->scanned, 'first managed request: route cache loaded, scanControllers() NOT called');

    // createControllerInstance → DI cache exists → guard no-op → resolve from the prebuilt map (no DICacheBuilder).
    $instance = $router->createControllerInstancePublic(GuardFixtureNoop::class);
    assert_true($instance instanceof GuardFixtureNoop, 'first managed request: controller resolved from the prebuilt DI map');
    assert_true(!$router->scanned, 'first managed request: no scan during controller resolution');

    assert_true(!is_file($s['cache'] . '/job_registry.php'), 'first managed request: no job registry built (no DI compile)');
    assert_same($routesHashBefore, md5_file($s['cache'] . '/compiled_routes.php'), 'first managed request: route cache file UNCHANGED');
    assert_same($diHashBefore, md5_file($s['cache'] . '/compiled_di.php'), 'first managed request: DI cache file UNCHANGED');
    $rrm($s['root']);

    echo "RuntimeEntryGuards passed\n";
} finally {
    // Restore the process to the legacy/BC default for every later suite in the shared run.
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
    // Drop cached singletons so later suites recompute paths/DI against the restored environment.
    $resetSingletons();
}
