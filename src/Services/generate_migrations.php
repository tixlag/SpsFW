<?php

use SpsFW\Core\Db\Migration\SchemaMigrationGenerator;
use SpsFW\Core\Router\PathManager;

require_once __DIR__ . '/../../vendor/autoload.php';


$projectRoot = PathManager::getProjectRoot();
$generator = new SchemaMigrationGenerator($projectRoot);

try {
    echo "🔍 Scanning for schema files...\n";
    $generatedCount = $generator->generateMigrations();

    if ($generatedCount > 0) {
        echo "✅ Generated {$generatedCount} migration(s)\n";
    } else {
        echo "✅ No new migrations needed - all schemas are up to date\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

