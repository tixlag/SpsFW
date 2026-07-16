<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 1 (M1) + Шаг 5: ApplicationContext pins mode validation AND the independent diagnostic-policy validation.
 * Only legacy|managed are valid modes; only parity|strict are valid policies; an unknown value must throw
 * immediately (it is a programming error in the compilation owner, not a runtime condition). Mode and policy are
 * DECOUPLED — they can be combined freely, and the policy alone decides whether warnings block publication.
 */
$legacy = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src']);
assert_same(ApplicationContext::MODE_LEGACY, $legacy->mode, 'default mode is legacy');
assert_same(ApplicationContext::POLICY_PARITY, $legacy->diagnosticPolicy, 'default diagnostic policy is parity');

$managed = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: ApplicationContext::MODE_MANAGED);
assert_same(ApplicationContext::MODE_MANAGED, $managed->mode, 'managed mode accepted');

// unknown mode must throw InvalidArgumentException immediately
$threw = false;
try {
    new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: 'bogus');
} catch (\InvalidArgumentException $e) {
    $threw = true;
    assert_true(str_contains($e->getMessage(), 'bogus'), 'invalid-mode message names the bad value');
    assert_true(str_contains($e->getMessage(), 'legacy'), 'invalid-mode message lists legacy');
    assert_true(str_contains($e->getMessage(), 'managed'), 'invalid-mode message lists managed');
}
assert_true($threw, 'unknown ApplicationContext mode throws InvalidArgumentException');

// empty string is not a valid mode either
$threwEmpty = false;
try {
    new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src'], mode: '');
} catch (\InvalidArgumentException $e) {
    $threwEmpty = true;
}
assert_true($threwEmpty, 'empty mode throws InvalidArgumentException');

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
