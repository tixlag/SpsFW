<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\OperationIdResolver;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 2 (M2): OperationIdResolver — convention, explicit/lockfile precedence, pre-M9 deferral,
 * and compile-halting collision detection (plan §7/§19).
 */

$resolver = new OperationIdResolver(new CompileDiagnostics());

// --- controllerShort: namespace stripped, trailing "Controller" removed ---
assert_same('Auth', $resolver->controllerShort('App\\Controllers\\AuthController'), 'controllerShort strips namespace + trailing Controller');
assert_same('Exam', $resolver->controllerShort('App\\Modules\\Exam\\ExamController'), 'controllerShort handles deep namespace');
assert_same('Auth', $resolver->controllerShort('AuthController'), 'controllerShort works without a namespace');
assert_same('Bar', $resolver->controllerShort('App\\Bar'), 'controllerShort leaves a class not ending in Controller intact');

// --- convention: ControllerShort + ucfirst(method) ---
assert_same('AuthLogin', $resolver->convention('App\\AuthController', 'login'), 'convention = ControllerShort . ucfirst(method)');
assert_same('AuthMe', $resolver->convention('App\\AuthController', 'me'), 'convention capitalizes the method');
assert_same('ExamGetExam', $resolver->convention('App\\ExamController', 'getExam'), 'convention keeps already-capitalized segments');

// ============================================================================
// resolve: precedence — explicit > lockfile > convention. Lockfile is tri-state
// (string id / null / absent): array_key_exists is the test, never ??.
// ============================================================================

// ABSENT key + no explicit => convention
$plain = new OperationIdResolver(new CompileDiagnostics());
assert_same('AuthLogin', $plain->resolve('App\\AuthController', 'login'), 'absent lockfile key + no explicit => convention id');

// non-null lockfile entry honoured
$withLockfile = new OperationIdResolver(new CompileDiagnostics(), [
    'App\\AuthController::login' => 'loginUser',
    'App\\LegacyController::old' => null, // tri-state: present-but-null = "keep id-less"
]);
assert_same('loginUser', $withLockfile->resolve('App\\AuthController', 'login'), 'non-null lockfile entry honoured when no explicit id');
assert_same('customId', $withLockfile->resolve('App\\AuthController', 'login', explicit: 'customId'), 'explicit id beats a non-null lockfile entry');
assert_same('AuthMe', $withLockfile->resolve('App\\AuthController', 'me'), 'absent key + no explicit => convention');
assert_same('AuthLogin', $plain->resolve('App\\AuthController', 'login', explicit: ''), 'empty-string explicit is treated as absent (falls back)');

// ============================================================================
// Tri-state null lockfile entry (§19 pre-M9): a present-but-null value keeps the
// op id-less — NOT a fall-through to convention. This is how the 306 legacy ops
// stay null so client P is untouched; the controller-qualified id arrives only in M9.
// ============================================================================
assert_same(null, $withLockfile->resolve('App\\LegacyController', 'old'), 'present-but-null lockfile entry keeps operationId = null (no convention fall-through)');

// explicit ALWAYS wins, even over a null lockfile entry
assert_same('forcedId', $withLockfile->resolve('App\\LegacyController', 'old', explicit: 'forcedId'), 'explicit id wins over a null lockfile entry');

// ============================================================================
// assertUnique: a clean set reports no errors.
// ============================================================================
$cleanDiag = new CompileDiagnostics();
$clean = new OperationIdResolver($cleanDiag);
$clean->resolve('App\\AuthController', 'login');  // AuthLogin
$clean->resolve('App\\AuthController', 'me');     // AuthMe
$clean->resolve('App\\UserController', 'index');  // UserIndex
$clean->assertUnique();
assert_true(!$cleanDiag->hasErrors(), 'distinct convention ids produce no uniqueness errors');

// ============================================================================
// assertUnique: same ControllerShort from different namespaces + same method collides.
// This is the one real way the convention can still collide, and it must halt.
// ============================================================================
$collideDiag = new CompileDiagnostics();
$collide = new OperationIdResolver($collideDiag);
$collide->resolve('App\\UserController', 'index'); // UserIndex
$collide->resolve('Api\\UserController', 'index'); // UserIndex — collision
$collide->resolve('App\\AuthController', 'login'); // AuthLogin (distinct)
$collide->assertUnique();
assert_true($collideDiag->hasErrors(), 'two operations collapsing to the same id are reported');
assert_same(2, $collideDiag->count(), 'collision reported on BOTH colliding operations (1 error each)');

$firstError = $collideDiag->errors()[0];
assert_same('operationId', $firstError['field'], 'collision error is tagged on the operationId field');
assert_true(str_contains($firstError['cause'], 'UserIndex'), 'collision cause names the colliding id');
assert_same('App\\UserController', $firstError['controller'], 'collision attributed to the controller');
assert_true($firstError['fix'] !== null, 'collision error carries a remediation hint');

// ============================================================================
// Null (id-less) results do not participate in uniqueness, so they never collide.
// Two ops that would collide under convention both stay null via the lockfile.
// ============================================================================
$nullDiag = new CompileDiagnostics();
$nullResolver = new OperationIdResolver($nullDiag, [
    'App\\OldController::index' => null,
    'Api\\OldController::index' => null,
]);
assert_same(null, $nullResolver->resolve('App\\OldController', 'index'), 'null lockfile entry => null');
assert_same(null, $nullResolver->resolve('Api\\OldController', 'index'), 'null lockfile entry => null');
$nullResolver->assertUnique();
assert_true(!$nullDiag->hasErrors(), 'null (id-less) results are not tracked for uniqueness');

echo "OperationIdResolver passed\n";
