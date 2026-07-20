<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Compile\RuleSource;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 7 (M5): the route-cache rule-graph PRODUCER SWITCH + the Metadata-mode PARITY GATE.
 *
 * Coverage (plan §15, req 3/4/5):
 *  - Legacy mode emits Router::extractValidationRules() byte-for-byte (pure rollback, NO gate) — the M5 switch is OFF
 *    by default; the old graph is reproduced exactly so flipping back loses nothing.
 *  - Metadata mode emits the DtoSchemaBuilder graph and, before publishing, proves it === the legacy OA source — incl.
 *    key ORDER and value TYPES (required stored as the array [true]), nested DTOs, collections, promoted params.
 *  - Any divergence ⇒ a FATAL CompileDiagnostics ERROR naming controller/method/dto/field + the divergent path; the
 *    Coordinator blocks publication (proven generically by CoordinatorFlowTest), leaving the old set byte-identical.
 *  - The legacy $legacyRuleSource seam is honored in BOTH modes (it is the emit in Legacy, the oracle in Metadata),
 *    so the gate is exercisable without contriving a divergence in production DTOs (DtoSchemaBuilder never diverges).
 *  - End-to-end through the Coordinator: Legacy and Metadata produce byte-identical compiled_routes, while rule_source
 *    reaches the manifest AND changes the fingerprint (different source ⇒ cache invalidation).
 */

// --- inline fixtures (compileController takes a ReflectionClass; no filesystem discovery needed) ---
final class RsNestedDto
{
    #[OA\Property(type: 'string', required: [true])]
    public string $tag;
}
final class RsBodyDto
{
    #[OA\Property(property: 'email', type: 'string', format: 'email', required: [true])]
    public string $email;

    #[OA\Property(property: 'note', type: 'string', nullable: true, default: 'hi')]
    public ?string $note;

    #[OA\Property(ref: RsNestedDto::class)]
    public RsNestedDto $meta;

    #[OA\Property(property: 'tags', ref: RsNestedDto::class, type: 'array')]
    public array $tags;

    public function __construct(
        #[OA\Property(property: 'id', type: 'integer', minimum: 0)] public int $id = 0,
    ) {}
}
final class RsFixtureController
{
    #[Route('/api/rs/create', [HttpMethod::POST])]
    public function create(#[JsonBody] RsBodyDto $dto): array { return []; }
}

$legacyOracle = static fn (string $dto): array => Router::extractValidationRules($dto);
$metaOracle = static fn (string $dto): array => (new DtoSchemaBuilder())->ruleGraphFor($dto)->rules;
$controllerRefl = new ReflectionClass(RsFixtureController::class);

// ============================================================================
// 1. LEGACY (default): the emitted graph is Router::extractValidationRules byte-for-byte; NO gate runs.
// ============================================================================
$diag = new CompileDiagnostics();
$compiler = new RouteMetadataCompiler($diag, ruleSource: RuleSource::Legacy);
$route = $compiler->compileController($controllerRefl)[0];
assert_same($legacyOracle(RsBodyDto::class), $route->dtos[0]['rules']->rules, 'Legacy mode emits Router::extractValidationRules byte-for-byte');
assert_same(0, $diag->errorCount(), 'Legacy mode runs NO parity gate (pure rollback)');
// the emitted graph is non-trivial (so the parity claim below is meaningful, not vacuous)
assert_true(isset($route->dtos[0]['rules']->rules['email']['required']), 'fixture has a required scalar rule');
assert_true(isset($route->dtos[0]['rules']->rules['meta']['nested_rules']['tag']), 'fixture has a nested-DTO rule');
assert_true(isset($route->dtos[0]['rules']->rules['tags']['type']), 'fixture has a collection rule');

// ============================================================================
// 2. METADATA: the emitted graph is the DtoSchemaBuilder graph AND === the legacy OA source — parity holds.
//    Covers required:[true] (array), nullable+default, nested DTO recursion, collection, promoted ctor param.
// ============================================================================
$diagM = new CompileDiagnostics();
$compilerM = new RouteMetadataCompiler($diagM, ruleSource: RuleSource::Metadata);
$routeM = $compilerM->compileController($controllerRefl)[0];
assert_same($metaOracle(RsBodyDto::class), $routeM->dtos[0]['rules']->rules, 'Metadata mode emits the DtoSchemaBuilder graph');
assert_same($legacyOracle(RsBodyDto::class), $routeM->dtos[0]['rules']->rules, 'Metadata graph === legacy OA source (strict parity incl. key order + value types)');
assert_same(0, $diagM->errorCount(), 'parity holds ⇒ no error recorded');

// ============================================================================
// 3. DEFAULT ruleSource (constructor omitted) is Legacy — the switch is OFF until a caller opts into Metadata.
// ============================================================================
$diagDef = new CompileDiagnostics();
$routeDef = (new RouteMetadataCompiler($diagDef))->compileController($controllerRefl)[0];
assert_same($legacyOracle(RsBodyDto::class), $routeDef->dtos[0]['rules']->rules, 'default ruleSource is Legacy (emits the OA source)');
assert_same(0, $diagDef->errorCount(), 'default (Legacy) runs no gate');

// ============================================================================
// 4. The $legacyRuleSource seam is honored in Legacy mode (it IS the emit) — proving the source is a switchable input.
// ============================================================================
$injected = static fn (string $dto): array => ['injected' => ['type' => 'string']];
$diagInj = new CompileDiagnostics();
$routeInj = (new RouteMetadataCompiler($diagInj, ruleSource: RuleSource::Legacy, legacyRuleSource: $injected))->compileController($controllerRefl)[0];
assert_same(['injected' => ['type' => 'string']], $routeInj->dtos[0]['rules']->rules, 'Legacy mode emits the injectable legacy source verbatim');
assert_same(0, $diagInj->errorCount(), 'Legacy mode runs no gate even with an injected source');

// ============================================================================
// 5. METADATA + divergence ⇒ FATAL ERROR with controller/method/dto/field attribution + the pinpointed path. The
//    injected "legacy" source adds email.minimum:99 that the faithful DtoSchemaBuilder graph lacks, so the gate fires.
//    Assembly still proceeds (the route carries the METADATA graph); publication — not assembly — is what the gate
//    blocks (the Coordinator's error gate, exercised generically in CoordinatorFlowTest, leaves the old set untouched).
// ============================================================================
$divergentLegacy = static function (string $dto): array {
    $rules = Router::extractValidationRules($dto);
    if (isset($rules['email'])) {
        $rules['email']['minimum'] = 99; // metadata lacks this ⇒ present-in-legacy-only at email.minimum
    }
    return $rules;
};
$diagDiv = new CompileDiagnostics();
$compilerDiv = new RouteMetadataCompiler($diagDiv, ruleSource: RuleSource::Metadata, legacyRuleSource: $divergentLegacy);
$routeDiv = $compilerDiv->compileController($controllerRefl)[0];
assert_true($diagDiv->hasErrors(), 'a metadata/legacy divergence is a FATAL error');
$err = $diagDiv->errors()[0];
assert_same(RsFixtureController::class, $err['controller'], 'error attributed to the controller');
assert_same('create', $err['method'], 'error attributed to the method');
assert_same(RsBodyDto::class, $err['dto'], 'error attributed to the DTO');
assert_same('dto', $err['field'], 'error attributed to the validated parameter ($dto)');
assert_true(str_contains($err['cause'], 'parity'), 'cause names the parity violation');
assert_true(str_contains($err['cause'], 'email.minimum'), 'cause pinpoints the divergent path (email.minimum)');
// assembly proceeds with the metadata graph; the Coordinator would block publication downstream
assert_same($metaOracle(RsBodyDto::class), $routeDiv->dtos[0]['rules']->rules, 'on a gate violation the assembled IR still carries the metadata graph (publication is what is blocked)');

// a clean parity (no injected divergence) records no error even in Metadata mode — re-asserted in isolation
$diagClean = new CompileDiagnostics();
(new RouteMetadataCompiler($diagClean, ruleSource: RuleSource::Metadata))->compileController($controllerRefl);
assert_same(0, $diagClean->errorCount(), 'Metadata mode with matching sources records no error');

// ============================================================================
// 6. End-to-end through the Coordinator: a temp fixture with REAL OA rules compiles in both Legacy and Metadata.
//    compiled_routes must be byte-identical (the parity claim at the IR level), while rule_source reaches the manifest
//    AND changes the fingerprint (different source ⇒ different fingerprint ⇒ cache invalidation).
// ============================================================================
$coordDir = sys_get_temp_dir() . '/rs_coord_' . bin2hex(random_bytes(4));
mkdir($coordDir, 0777, true);
file_put_contents($coordDir . '/RsCoordController.php', <<<'PHP'
<?php
namespace RsCoordFixture;
use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Http\HttpMethod;
final class RsCoordNestedDto {
    #[OA\Property(type: 'string', required: [true])]
    public string $tag;
}
final class RsCoordDto {
    #[OA\Property(property: 'email', type: 'string', format: 'email', required: [true])]
    public string $email;
    #[OA\Property(property: 'note', type: 'string', nullable: true, default: 'hi')]
    public ?string $note;
    #[OA\Property(ref: RsCoordNestedDto::class)]
    public RsCoordNestedDto $meta;
}
final class RsCoordController {
    #[Route('/api/rs/coord', [HttpMethod::POST])]
    public function create(#[JsonBody] RsCoordDto $dto): void {}
}
PHP);

$mkCache = static function (): string {
    $d = sys_get_temp_dir() . '/rs_cache_' . bin2hex(random_bytes(8));
    mkdir($d, 0777, true);
    return $d;
};
$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $p = $dir . '/' . $entry;
        is_dir($p) && !is_link($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($dir);
};
$caches = [];

$cacheL = $mkCache();
$caches[] = $cacheL;
$ctxL = new ApplicationContext(
    projectRoot: $cacheL, cachePath: $cacheL, discoveryPaths: [$coordDir],
    mode: ApplicationContext::MODE_MANAGED, ruleSource: ApplicationContext::RULE_SOURCE_LEGACY,
);
$resL = (new Coordinator($ctxL))->compile();
assert_true($resL->published, 'Coordinator Legacy managed compile publishes');
assert_same(0, $resL->errorCount, 'Coordinator Legacy compile: no errors');

$cacheM = $mkCache();
$caches[] = $cacheM;
$ctxM = new ApplicationContext(
    projectRoot: $cacheM, cachePath: $cacheM, discoveryPaths: [$coordDir],
    mode: ApplicationContext::MODE_MANAGED, ruleSource: ApplicationContext::RULE_SOURCE_METADATA,
);
$resM = (new Coordinator($ctxM))->compile();
assert_true($resM->published, 'Coordinator Metadata managed compile publishes (parity holds on the fixture)');
assert_same(0, $resM->errorCount, 'Coordinator Metadata compile: no errors (parity holds)');

// compiled_routes byte-identical between the two modes — the dtos rules are === end-to-end through the Coordinator.
$routesL = require $cacheL . '/compiled_routes.php';
$routesM = require $cacheM . '/compiled_routes.php';
assert_same($routesL, $routesM, 'Legacy and Metadata compiled_routes are byte-identical (dtos rules === through the Coordinator)');

// rule_source reaches the manifest config_inputs (first-class input, recorded verbatim).
$manifestL = require $cacheL . '/.compile_manifest.php';
$manifestM = require $cacheM . '/.compile_manifest.php';
assert_same('legacy', $manifestL['config_inputs']['rule_source'], 'manifest records rule_source=legacy');
assert_same('metadata', $manifestM['config_inputs']['rule_source'], 'manifest records rule_source=metadata');

// rule_source participates in the FINGERPRINT: same sources, different rule_source ⇒ different fingerprint ⇒ the
// cache is invalidated when the source flips (even though the artifacts stay byte-identical under parity).
assert_true($manifestL['fingerprint'] !== $manifestM['fingerprint'], 'rule_source participates in the fingerprint (flip ⇒ invalidation)');

unlink($coordDir . '/RsCoordController.php');
rmdir($coordDir);
foreach ($caches as $c) {
    $rrm($c);
}

echo "RouteMetadataCompilerRuleSource passed\n";
