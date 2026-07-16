<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 1 (M1) + Шаг 5 + Шаг 6b (mode-contract closure): ApplicationContext pins the TYPED mode (CompileMode, not a
 * free string) AND the independent diagnostic-policy validation. Only parity|strict are valid policies, and an
 * unknown policy throws immediately. The mode is now a typed enum, so the constructor CANNOT receive an invalid
 * mode at all — a raw string is a TypeError (the detailed unknown-value error lives at the SINGLE resolution point
 * CompileMode::fromString/current, exercised in CompileModeGuardTest). Mode and policy are DECOUPLED — they combine
 * freely, and the policy alone decides whether warnings block publication.
 */
$legacy = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src']);
assert_same(ApplicationContext::MODE_LEGACY, $legacy->mode, 'default mode is legacy');
assert_same(ApplicationContext::POLICY_PARITY, $legacy->diagnosticPolicy, 'default diagnostic policy is parity');

$managed = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: ApplicationContext::MODE_MANAGED);
assert_same(ApplicationContext::MODE_MANAGED, $managed->mode, 'managed mode accepted');

// The mode is a TYPED CompileMode: a raw string is rejected with a TypeError at construction (mode is NEVER a free
// string anymore). The empty⇒Legacy / unknown⇒InvalidArgumentException contract lives ONLY at the env/CLI resolution
// point (CompileMode::fromString / ::current), not here — the constructor receives an already-resolved enum case.
$threwBogus = false;
try {
    new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: 'bogus');
} catch (\TypeError $e) {
    $threwBogus = true;
}
assert_true($threwBogus, 'a raw non-enum mode is a TypeError (mode is a typed CompileMode, never a free string)');

$threwEmpty = false;
try {
    new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: '');
} catch (\TypeError $e) {
    $threwEmpty = true;
}
assert_true($threwEmpty, 'an empty-string mode is a TypeError too (empty⇒Legacy applies only at the fromString/env resolution point)');

// ============================================================================
// Step 5: diagnostic policy — an INDEPENDENT axis from ownership mode.
// ============================================================================
$strict = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], diagnosticPolicy: ApplicationContext::POLICY_STRICT);
assert_same(ApplicationContext::POLICY_STRICT, $strict->diagnosticPolicy, 'strict policy accepted');
assert_true($strict->warningsBlock(), 'strict policy blocks on warnings');

$parity = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], diagnosticPolicy: ApplicationContext::POLICY_PARITY);
assert_true(!$parity->warningsBlock(), 'parity policy tolerates warnings');

// DECOUPLED: managed+parity and legacy+strict are both legitimate — mode does not constrain policy.
$managedParity = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: ApplicationContext::MODE_MANAGED, diagnosticPolicy: ApplicationContext::POLICY_PARITY);
assert_same(ApplicationContext::MODE_MANAGED, $managedParity->mode, 'managed+parity: mode is managed');
assert_same(ApplicationContext::POLICY_PARITY, $managedParity->diagnosticPolicy, 'managed+parity: policy is parity');
assert_true(!$managedParity->warningsBlock(), 'managed+parity tolerates warnings (the migration safety valve)');

$legacyStrict = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: ApplicationContext::MODE_LEGACY, diagnosticPolicy: ApplicationContext::POLICY_STRICT);
assert_same(ApplicationContext::MODE_LEGACY, $legacyStrict->mode, 'legacy+strict: mode is legacy');
assert_true($legacyStrict->warningsBlock(), 'legacy+strict blocks on warnings');

// unknown policy must throw InvalidArgumentException immediately
$threwPolicy = false;
try {
    new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], diagnosticPolicy: 'bogus');
} catch (\InvalidArgumentException $e) {
    $threwPolicy = true;
    assert_true(str_contains($e->getMessage(), 'bogus'), 'invalid-policy message names the bad value');
    assert_true(str_contains($e->getMessage(), 'parity'), 'invalid-policy message lists parity');
    assert_true(str_contains($e->getMessage(), 'strict'), 'invalid-policy message lists strict');
}
assert_true($threwPolicy, 'unknown ApplicationContext diagnostic policy throws InvalidArgumentException');

$threwPolicyEmpty = false;
try {
    new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], diagnosticPolicy: '');
} catch (\InvalidArgumentException $e) {
    $threwPolicyEmpty = true;
}
assert_true($threwPolicyEmpty, 'empty diagnostic policy throws InvalidArgumentException');

echo "ApplicationContext passed\n";
