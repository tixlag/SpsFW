<?php

declare(strict_types=1);

/**
 * Test runner: `composer test` → `php tests/run.php`.
 *
 * Discovery is RECURSIVE: every file matching *Test.php anywhere under tests/, sorted
 * deterministically. Replaces the previous two-level glob (tests/*/*Test.php) so that
 * nested suites such as tests/Compile/Metadata/..., tests/Compile/Introspection/...
 * are auto-discovered without extending the runner each time.
 *
 * Each test file is plain PHP using assert_same()/assert_true() from tests/bootstrap.php
 * (which each test requires itself). No PHPUnit.
 */
$root = __DIR__;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

$tests = [];
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $tests[] = $file->getPathname();
    }
}
sort($tests);

foreach ($tests as $test) {
    require $test;
}

echo count($tests) . " test file(s) passed\n";
