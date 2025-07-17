<?php

use SpsFW\Core\Db\Migration\MigrationManager;

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../../../../../vendor/autoload.php';
}


$projectRoot = dirname(__DIR__, 5) . '/src';
$manager = new MigrationManager($projectRoot);

// Парсим аргументы командной строки
$command = $argv[1] ?? 'help';
$options = array_slice($argv, 2);

switch ($command) {
    case 'status':
        echo "📊 Checking migration status...\n\n";
        $manager->showStatus();
        break;

    case 'generate':
        $manager->generateAll();
        break;

    case 'models':
        $manager->generateModelsOnly();
        break;

    case 'details':
        if (empty($options[0])) {
            echo "❌ Please specify schema class name.\n";
            echo "Usage: php migration_cli.php details [SchemaClassName]\n";
            exit(1);
        }
        $manager->showSchemaDetails($options[0]);
        break;

    case 'regenerate':
        if (empty($options[0]) || empty($options[1])) {
            echo "❌ Please specify schema class and version.\n";
            echo "Usage: php migration_cli.php regenerate [SchemaClassName] [version]\n";
            exit(1);
        }
        $manager->regenerate($options[0], $options[1]);
        break;

    case 'deploy':
        echo "🚀 Creating deployment script...\n";
        $manager->createDeploymentScript();
        break;

    case 'export':
        $format = $options[0] ?? 'json';
        echo "📤 Exporting migration history ({$format})...\n";
        $manager->exportHistory($format);
        break;

    case 'help':
    default:
        echo "🔧 Migration Management CLI\n\n";
        echo "Available commands:\n";
        echo "  status           - Show migration status for all schemas\n";
        echo "  generate         - Generate all pending migrations\n";
        echo "  details [schema] - Show detailed info for specific schema\n";
        echo "  regenerate [schema] [version] - Regenerate specific migration\n";
        echo "  deploy           - Create deployment script for new environment\n";
        echo "  export [format]  - Export migration history (json|csv|md)\n";
        echo "  help             - Show this help message\n\n";
        echo "Examples:\n";
        echo "  php migration_cli.php status\n";
        echo "  php migration_cli.php generate\n";
        echo "  php migration_cli.php details 'App\\Database\\UserSchema'\n";
        echo "  php migration_cli.php regenerate 'App\\Database\\UserSchema' '1.0'\n";
        echo "  php migration_cli.php deploy\n";
        echo "  php migration_cli.php export md\n";
        break;
}

echo "\n";