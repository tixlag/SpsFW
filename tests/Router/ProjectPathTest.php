<?php

declare(strict_types=1);

use SpsFW\Core\Router\PathManager;

require_once dirname(__DIR__) . '/bootstrap.php';

$projectRoot = sys_get_temp_dir() . '/spsfw-project-path-' . bin2hex(random_bytes(4));
mkdir($projectRoot . '/.cache', 0777, true);
file_put_contents($projectRoot . '/composer.json', '{}');

$reflection = new ReflectionClass(PathManager::class);
$projectRootProperty = $reflection->getProperty('projectRoot');
$projectRootProperty->setValue(null, null);

$previous = getenv('SPSFW_PROJECT_ROOT');
putenv('SPSFW_PROJECT_ROOT=' . $projectRoot);
$_ENV['SPSFW_PROJECT_ROOT'] = $projectRoot;

try {
    assert_same($projectRoot, PathManager::getProjectRoot(), 'explicit project root is used for symlink installs');
    assert_same($projectRoot . '/.cache', PathManager::getCachePath(), 'cache stays in the application project');
} finally {
    $projectRootProperty->setValue(null, null);
    if ($previous === false) {
        putenv('SPSFW_PROJECT_ROOT');
        unset($_ENV['SPSFW_PROJECT_ROOT']);
    } else {
        putenv('SPSFW_PROJECT_ROOT=' . $previous);
        $_ENV['SPSFW_PROJECT_ROOT'] = $previous;
    }
    unlink($projectRoot . '/composer.json');
    rmdir($projectRoot . '/.cache');
    rmdir($projectRoot);
}

echo "Project path contract passed\n";
