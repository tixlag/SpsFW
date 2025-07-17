<?php
namespace SpsFW\Core\Db\Migration;

/**
 * Менеджер для управления историей миграций и их применения
 */
class MigrationManager
{
    private SchemaMigrationGenerator $generator;
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->generator = new SchemaMigrationGenerator($projectRoot);
    }

    /**
     * Показывает статус всех миграций
     */
    public function showStatus(): void
    {
        echo "=== Migration Status ===\n\n";

        $history = $this->generator->getFullMigrationHistory();
        $pending = $this->generator->getPendingMigrations();

        if (empty($history) && empty($pending)) {
            echo "No migrations found.\n";
            return;
        }

        // Показываем существующие миграции
        foreach ($history as $schemaClass => $versions) {
            echo "📋 Schema: {$schemaClass}\n";

            foreach ($versions as $version => $info) {
                $status = isset($info['regenerated']) ? " (regenerated)" : "";
                echo "  ✅ v{$version}: {$info['description']} - {$info['file']}{$status}\n";
                echo "     Generated: {$info['generated_at']}\n";
            }
            echo "\n";
        }

        // Показываем ожидающие миграции
        if (!empty($pending)) {
            echo "🔄 Pending migrations:\n";
            foreach ($pending as $schemaClass => $versions) {
                echo "  Schema: {$schemaClass}\n";
                foreach ($versions as $version => $info) {
                    echo "    ⏳ v{$version}: {$info['description']}\n";
                }
            }
        }
    }

    /**
     * Генерирует только модели
     */
    public function generateModelsOnly(): void
    {
        echo "🚀 Generating models...\n\n";

        $count = $this->generator->generateModelsOnly();

        if ($count > 0) {
            echo "\n✅ Generated {$count} model(s).\n";
        } else {
            echo "✅ All models are up to date.\n";
        }
    }

    /**
     * Генерирует все отсутствующие миграции
     */
    public function generateAll(): void
    {
        echo "🚀 Generating migrations...\n\n";

        $count = $this->generator->generateMigrations();

        if ($count > 0) {
            echo "\n✅ Generated {$count} migration(s).\n";
        } else {
            echo "✅ All migrations are up to date.\n";
        }
    }

    /**
     * Показывает детальную информацию о конкретной схеме
     */
    public function showSchemaDetails(string $schemaClass): void
    {
        $history = $this->generator->getMigrationHistoryForSchema($schemaClass);

        if (empty($history)) {
            echo "No migrations found for schema: {$schemaClass}\n";
            return;
        }

        echo "=== Schema Details: {$schemaClass} ===\n\n";

        foreach ($history as $version => $info) {
            echo "Version: {$version}\n";
            echo "Description: {$info['description']}\n";
            echo "File: {$info['file']}\n";
            echo "Generated: {$info['generated_at']}\n";

            if (isset($info['regenerated'])) {
                echo "Status: Regenerated\n";
            }

            echo "---\n";
        }
    }

    /**
     * Регенерирует конкретную миграцию
     */
    public function regenerate(string $schemaClass, string $version): void
    {
        echo "🔄 Regenerating migration {$schemaClass} v{$version}...\n";

        if ($this->generator->regenerateMigration($schemaClass, $version)) {
            echo "✅ Migration regenerated successfully.\n";
        } else {
            echo "❌ Failed to regenerate migration. Check schema class and version.\n";
        }
    }

    /**
     * Создает файл для развертывания миграций в новом окружении
     */
    public function createDeploymentScript(): void
    {
        $history = $this->generator->getFullMigrationHistory();

        if (empty($history)) {
            echo "No migrations to deploy.\n";
            return;
        }

        $deploymentScript = "#!/bin/bash\n";
        $deploymentScript .= "# Auto-generated deployment script for migrations\n";
        $deploymentScript .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";

        $deploymentScript .= "echo \" Starting migration deployment...\"\n\n";

        // Собираем все миграции в хронологическом порядке
        $allMigrations = [];

        foreach ($history as $schemaClass => $versions) {
            foreach ($versions as $version => $info) {
                $allMigrations[] = [
                    'schema' => $schemaClass,
                    'version' => $version,
                    'file' => $info['file'],
                    'description' => $info['description'],
                    'generated_at' => $info['generated_at']
                ];
            }
        }

        // Сортируем по времени генерации
        usort($allMigrations, function($a, $b) {
            return strcmp($a['generated_at'], $b['generated_at']);
        });

        foreach ($allMigrations as $migration) {
            $deploymentScript .= "echo \"📄 Applying migration: {$migration['schema']} v{$migration['version']}\"\n";
            $deploymentScript .= "echo \"   Description: {$migration['description']}\"\n";
            $deploymentScript .= "vendor/bin/phinx migrate -t $(basename {$migration['file']} .php)\n";
            $deploymentScript .= "if [ \$? -ne 0 ]; then\n";
            $deploymentScript .= "    echo \"❌ Migration failed: {$migration['file']}\"\n";
            $deploymentScript .= "    exit 1\n";
            $deploymentScript .= "fi\n\n";
        }

        $deploymentScript .= "echo \"✅ All migrations applied successfully!\"\n";

        $scriptPath = $this->projectRoot . '/../db/deploy_migrations.sh';
        file_put_contents($scriptPath, $deploymentScript);
        chmod($scriptPath, 0755);

        echo "📄 Deployment script created: {$scriptPath}\n";
        echo "📄 Total migrations: " . count($allMigrations) . "\n";
    }

    /**
     * Экспортирует историю миграций в читаемый формат
     */
    public function exportHistory(string $format = 'json'): void
    {
        $history = $this->generator->getFullMigrationHistory();

        if (empty($history)) {
            echo "No migration history to export.\n";
            return;
        }

        $exportPath = $this->projectRoot . '/../db/migration_history_export.' . $format;

        switch ($format) {
            case 'json':
                file_put_contents($exportPath, json_encode($history, JSON_PRETTY_PRINT));
                break;

            case 'csv':
                $csvData = "Schema,Version,Description,File,Generated At\n";
                foreach ($history as $schemaClass => $versions) {
                    foreach ($versions as $version => $info) {
                        $csvData .= "\"{$schemaClass}\",\"{$version}\",\"{$info['description']}\",\"{$info['file']}\",\"{$info['generated_at']}\"\n";
                    }
                }
                file_put_contents($exportPath, $csvData);
                break;

            case 'md':
                $mdData = "# Migration History\n\n";
                $mdData .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

                foreach ($history as $schemaClass => $versions) {
                    $mdData .= "## {$schemaClass}\n\n";

                    foreach ($versions as $version => $info) {
                        $mdData .= "### Version {$version}\n\n";
                        $mdData .= "- **Description:** {$info['description']}\n";
                        $mdData .= "- **File:** {$info['file']}\n";
                        $mdData .= "- **Generated:** {$info['generated_at']}\n\n";
                    }
                }

                file_put_contents($exportPath, $mdData);
                break;

            default:
                echo "❌ Unsupported format: {$format}\n";
                return;
        }

        echo "✅ Migration history exported to: {$exportPath}\n";
    }
}