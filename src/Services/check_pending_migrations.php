<?php

use SpsFW\Core\Db\Migration\SchemaMigrationGenerator;

require_once __DIR__ . '/../../vendor/autoload.php';


$projectRoot = dirname(__DIR__);
$generator = new SchemaMigrationGenerator($projectRoot);

try {
    // Получаем статус миграций через Phinx
    $output = shell_exec('vendor/bin/phinx status -c phinx.php 2>&1');

    if ($output === null) {
        echo "❌ Error: Could not check migration status\n";
        exit(1);
    }

    // Проверяем наличие pending миграций
    if (str_contains($output, 'down')) {
        echo "⚠️  Found pending migrations:\n";
        echo $output;
        echo "\n💡 Run 'composer migration:run' to apply them\n";
        exit(0);
    } else {
        echo "✅ All migrations are up to date\n";
        exit(0);
    }

} catch (Exception $e) {
    echo "❌ Error checking migrations: " . $e->getMessage() . "\n";
    exit(1);
}