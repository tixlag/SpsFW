<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 1 (M1): pins ApplicationContext mode validation. Only legacy|managed are valid; an unknown mode
 * must throw immediately (it is a programming error in the compilation owner, not a runtime condition).
 */
$legacy = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src']);
assert_same(ApplicationContext::MODE_LEGACY, $legacy->mode, 'default mode is legacy');

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

echo "ApplicationContext passed\n";
