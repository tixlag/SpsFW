<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\CompileMode;
use SpsFW\Core\Compile\OpenApiSource;
use SpsFW\Core\Compile\RuleSource;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 8 (M6): the {@see OpenApiSource} PRIMARY-openapi producer switch — a 4th first-class typed
 * ApplicationContext compile input (independent of {@see CompileMode}, {@see RuleSource}, diagnosticPolicy).
 *
 * Verifies the explicit-error resolution contract (mirroring RuleSource), the ApplicationContext default +
 * aliases, and that it is a DISTINCT axis. NO env is read inside OpenApiSource — resolution is the caller's
 * job ({@see OpenApiSource::fromString()}); the Coordinator records `openapi_source` in config_inputs
 * (covered by OpenApiPrimarySourceTest + FingerprinterTest).
 */

// ============================================================================
// fromString: empty/unset ⇒ Legacy (full backward compatibility); exact strings ⇒ the case; anything else ⇒ throw.
// ============================================================================
assert_same(OpenApiSource::Legacy, OpenApiSource::fromString(''), 'empty string ⇒ Legacy (BC default)');
assert_same(OpenApiSource::Legacy, OpenApiSource::fromString('legacy'), '"legacy" ⇒ Legacy');
assert_same(OpenApiSource::Metadata, OpenApiSource::fromString('metadata'), '"metadata" ⇒ Metadata');

$invalid = null;
try {
    OpenApiSource::fromString('meta-data');
} catch (\InvalidArgumentException $e) {
    $invalid = $e;
}
assert_true($invalid instanceof \InvalidArgumentException, 'an invalid openapi source throws (no silent Legacy fallback)');
assert_true(str_contains($invalid->getMessage(), 'meta-data'), 'the invalid value is named in the error');
assert_true(str_contains($invalid->getMessage(), 'legacy'), 'the error lists the allowed values');
assert_true(str_contains($invalid->getMessage(), 'metadata'), 'the error lists the allowed values');

// ============================================================================
// isLegacy / isMetadata helpers.
// ============================================================================
assert_true(OpenApiSource::Legacy->isLegacy(), 'Legacy->isLegacy()');
assert_true(!OpenApiSource::Legacy->isMetadata(), 'Legacy->isMetadata() is false');
assert_true(OpenApiSource::Metadata->isMetadata(), 'Metadata->isMetadata()');
assert_true(!OpenApiSource::Metadata->isLegacy(), 'Metadata->isLegacy() is false');

// ============================================================================
// Backed string value (this is what the Coordinator records in the manifest config_inputs).
// ============================================================================
assert_same('legacy', OpenApiSource::Legacy->value, 'Legacy->value is "legacy"');
assert_same('metadata', OpenApiSource::Metadata->value, 'Metadata->value is "metadata"');

// ============================================================================
// ApplicationContext: the default openApiSource is Legacy; the aliases are the enum cases; it is a typed field.
// ============================================================================
$default = new ApplicationContext(projectRoot: '/p', cachePath: '/c', discoveryPaths: []);
assert_same(OpenApiSource::Legacy, $default->openApiSource, 'ApplicationContext defaults to OpenApiSource::Legacy (the M6 switch is OFF by default)');
assert_same(OpenApiSource::Legacy, ApplicationContext::OPENAPI_SOURCE_LEGACY, 'OPENAPI_SOURCE_LEGACY alias is the Legacy case');
assert_same(OpenApiSource::Metadata, ApplicationContext::OPENAPI_SOURCE_METADATA, 'OPENAPI_SOURCE_METADATA alias is the Metadata case');

$metadata = new ApplicationContext(projectRoot: '/p', cachePath: '/c', discoveryPaths: [], openApiSource: ApplicationContext::OPENAPI_SOURCE_METADATA);
assert_same(OpenApiSource::Metadata, $metadata->openApiSource, 'ApplicationContext accepts openApiSource: Metadata via the alias');

// ============================================================================
// 4th INDEPENDENT axis: openApiSource is decoupled from CompileMode + RuleSource. The defaults compose
// (Legacy mode + Legacy ruleSource + Legacy openApiSource = the all-rollback default), and the Metadata
// openApiSource composes with ANY mode / ruleSource combination without forcing them.
// ============================================================================
assert_same(
    [CompileMode::Legacy, RuleSource::Legacy, OpenApiSource::Legacy],
    [$default->mode, $default->ruleSource, $default->openApiSource],
    'the three Legacy defaults compose (all independent axes default to Legacy)',
);
$combo = new ApplicationContext(
    projectRoot: '/p',
    cachePath: '/c',
    discoveryPaths: [],
    mode: ApplicationContext::MODE_MANAGED,
    ruleSource: ApplicationContext::RULE_SOURCE_METADATA,
    openApiSource: ApplicationContext::OPENAPI_SOURCE_METADATA,
);
assert_same(
    [CompileMode::Managed, RuleSource::Metadata, OpenApiSource::Metadata],
    [$combo->mode, $combo->ruleSource, $combo->openApiSource],
    'managed + metadata ruleSource + metadata openApiSource all compose (today\'s N opt-in)',
);
// Legacy openApiSource is independent of metadata ruleSource — exactly today's N PRODUCTION config.
$todayN = new ApplicationContext(
    projectRoot: '/p',
    cachePath: '/c',
    discoveryPaths: [],
    mode: ApplicationContext::MODE_MANAGED,
    ruleSource: ApplicationContext::RULE_SOURCE_METADATA,
    openApiSource: ApplicationContext::OPENAPI_SOURCE_LEGACY,
);
assert_same(OpenApiSource::Legacy, $todayN->openApiSource, 'N today: ruleSource=Metadata while openApiSource stays Legacy (independent axes)');

echo "OpenApiSource passed\n";
