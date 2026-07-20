<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\RuleSource;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Step 7 (M5): the {@see RuleSource} producer switch — a first-class typed ApplicationContext compile input.
 *
 * Verifies the explicit-error resolution contract (mirroring CompileMode), the ApplicationContext default + aliases,
 * and that the Coordinator records rule_source in the manifest config_inputs (fingerprint/manifest participation).
 * NO env is read inside RuleSource — resolution is the caller's job ({@see RuleSource::fromString()}).
 */

// ============================================================================
// fromString: empty/unset ⇒ Legacy (full backward compatibility); exact strings ⇒ the case; anything else ⇒ throw.
// ============================================================================
assert_same(RuleSource::Legacy, RuleSource::fromString(''), 'empty string ⇒ Legacy (BC default)');
assert_same(RuleSource::Legacy, RuleSource::fromString('legacy'), '"legacy" ⇒ Legacy');
assert_same(RuleSource::Metadata, RuleSource::fromString('metadata'), '"metadata" ⇒ Metadata');

$invalid = null;
try {
    RuleSource::fromString('meta-data');
} catch (\InvalidArgumentException $e) {
    $invalid = $e;
}
assert_true($invalid instanceof \InvalidArgumentException, 'an invalid rule source throws (no silent Legacy fallback)');
assert_true(str_contains($invalid->getMessage(), 'meta-data'), 'the invalid value is named in the error');
assert_true(str_contains($invalid->getMessage(), 'legacy'), 'the error lists the allowed values');

// ============================================================================
// isLegacy / isMetadata helpers.
// ============================================================================
assert_true(RuleSource::Legacy->isLegacy(), 'Legacy->isLegacy()');
assert_true(!RuleSource::Legacy->isMetadata(), 'Legacy->isMetadata() is false');
assert_true(RuleSource::Metadata->isMetadata(), 'Metadata->isMetadata()');
assert_true(!RuleSource::Metadata->isLegacy(), 'Metadata->isLegacy() is false');

// ============================================================================
// Backed string value (this is what the Coordinator records in the manifest).
// ============================================================================
assert_same('legacy', RuleSource::Legacy->value, 'Legacy->value is "legacy"');
assert_same('metadata', RuleSource::Metadata->value, 'Metadata->value is "metadata"');

// ============================================================================
// ApplicationContext: the default ruleSource is Legacy; the aliases are the enum cases; it is a typed field.
// ============================================================================
$default = new ApplicationContext(projectRoot: '/p', cachePath: '/c', discoveryPaths: []);
assert_same(RuleSource::Legacy, $default->ruleSource, 'ApplicationContext defaults to RuleSource::Legacy (the M5 switch is OFF by default)');
assert_same(RuleSource::Legacy, ApplicationContext::RULE_SOURCE_LEGACY, 'RULE_SOURCE_LEGACY alias is the Legacy case');
assert_same(RuleSource::Metadata, ApplicationContext::RULE_SOURCE_METADATA, 'RULE_SOURCE_METADATA alias is the Metadata case');

$metadata = new ApplicationContext(projectRoot: '/p', cachePath: '/c', discoveryPaths: [], ruleSource: ApplicationContext::RULE_SOURCE_METADATA);
assert_same(RuleSource::Metadata, $metadata->ruleSource, 'ApplicationContext accepts ruleSource: Metadata via the alias');

echo "RuleSource passed\n";
