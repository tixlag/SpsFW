<?php

declare(strict_types=1);

$tests = glob(__DIR__ . '/*/*Test.php') ?: [];
sort($tests);

foreach ($tests as $test) {
    require $test;
}

echo count($tests) . " test file(s) passed\n";
