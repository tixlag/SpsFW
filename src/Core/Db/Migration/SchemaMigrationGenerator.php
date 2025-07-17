<?php

namespace SpsFW\Core\Db\Migration;

use ReflectionClass;
use SpsFW\Core\Db\Models\ModelGenerator;

/**
 * Расширенный генератор миграций с поддержкой генерации моделей
 */
class SchemaMigrationGenerator
{
    private array $paths;
    private string $versionFile;
    private string $migrationHistoryFile;
    private ModelGenerator $modelGenerator;

    public function __construct(string $projectRoot)
    {
        $this->paths[] = __DIR__ . '/../../';
        $this->paths[] = $projectRoot;
        $this->versionFile = $projectRoot . '/../db/.schema_versions.json';
        $this->migrationHistoryFile = $projectRoot . '/../db/.migration_history.json';
        $this->modelGenerator = new ModelGenerator($projectRoot);

        if (!file_exists($this->versionFile)) {
            mkdir($projectRoot . '/../db/', 0777, true);
        }
    }

    /**
     * Проверяет все схемы и генерирует миграции и модели при необходимости
     */
    public function generateMigrations(): int
    {
        $schemas = $this->findAllSchemas();
        $currentVersions = $this->loadVersions();
        $migrationHistory = $this->loadMigrationHistory();
        $generatedCount = 0;

        foreach ($schemas as $schemaPath => $schemaClass) {
            $schema = new $schemaClass();
            $schemaHistory = $migrationHistory[$schemaClass] ?? [];

            // Получаем все версии из схемы
            $allVersions = $schema::VERSIONS;

            foreach ($allVersions as $version => $migrationData) {
                // Проверяем, была ли уже сгенерирована миграция для этой версии
                if (!isset($schemaHistory[$version])) {
                    $migrationFile = $this->generateMigrationForVersion($schema, $schemaPath, $version, $migrationData);

                    // Сохраняем информацию о сгенерированной миграции
                    $schemaHistory[$version] = [
                        'generated_at' => date('Y-m-d H:i:s'),
                        'file' => $migrationFile,
                        'description' => $migrationData['description']
                    ];

                $generatedCount++;
                    echo "📄 Generated migration for {$schemaClass} v{$version}: {$migrationFile}\n";
                }
            }

            // Обновляем историю миграций для этой схемы
            $migrationHistory[$schemaClass] = $schemaHistory;

            // Обновляем текущую версию
            $currentVersions[$schemaClass] = $schema->getLastVersion();
        }

        $this->saveVersions($currentVersions);
        $this->saveMigrationHistory($migrationHistory);

        // Генерируем модели
        echo "\n🏗️ Generating models...\n";
        $modelCount = $this->modelGenerator->generateModels();
        echo "Generated {$modelCount} models\n";

        return $generatedCount;
    }

    /**
     * Генерирует только модели без миграций
     */
    public function generateModelsOnly(): int
    {
        echo "🏗️ Generating models...\n";
        $modelCount = $this->modelGenerator->generateModels();
        echo "Generated {$modelCount} models\n";
        return $modelCount;
    }

    /**
     * Генерирует миграцию для конкретной версии схемы
     */
    private function generateMigrationForVersion(MigrationsSchema $schema, string $schemaPath, string $version, array $migrationData): string
    {
        $upCode = $migrationData['up'];
        $downCode = $migrationData['down'] ?? '';

        $migrationDir = dirname($schemaPath) . '/migrations';

        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0755, true);
        }

        // Генерируем уникальный timestamp
        $timestamp = $this->generateUniqueTimestamp($migrationDir);
        $tableName = $schema->getTableName();

        // Формируем имя класса и файла
        $className = 'V' . $timestamp;
        $fileName = $timestamp . '.php';
        $fullPath = $migrationDir . '/' . $fileName;

        $migrationContent = $this->generateMigrationContent($className, $upCode, $downCode, $schema, $version, $migrationData);
        file_put_contents($fullPath, $migrationContent);

        return $fileName;
    }

    /**
     * Генерирует уникальный timestamp для миграции
     */
    private function generateUniqueTimestamp(string $migrationDir): string
    {
        do {
            $timestamp = date('YmdHis');
            $fileName = $timestamp . '.php';
            $fullPath = $migrationDir . '/' . $fileName;

            if (!file_exists($fullPath)) {
                sleep(1);
                return $timestamp;
        }

            // Если файл существует, ждем секунду и пытаемся снова

        } while (true);
    }

    /**
     * Генерирует содержимое файла миграции
     */
    private function generateMigrationContent(string $className, string $upCode, string $downCode, MigrationsSchema $schema, string $version, array $migrationData): string
    {
        $reflection = new ReflectionClass($schema);
        $namespace = $reflection->getNamespaceName() . '\\migrations';
        // Экранируем двойные кавычки в SQL-запросах
        $upCodeEscaped = str_replace('"', '\\"', $upCode);
        $downCodeEscaped = str_replace('"', '\\"', $downCode);

        return <<<PHP
<?php

namespace $namespace ;

use Phinx\Migration\AbstractMigration;

/**
 * Auto-generated migration from schema
 * Table: {$schema->getTableName()}
 * Version: {$version}
 * Description: {$migrationData['description']}
 * Generated: {date('Y-m-d H:i:s')}
 */
class $className extends AbstractMigration
{
    public function up()
    {
        \$this->query("$upCodeEscaped");
    }
    
    public function down()
    {
        \$this->query("$downCodeEscaped");
    }
}
PHP;
    }

    /**
     * Находит все файлы схем в проекте
     */
    private function findAllSchemas(): array
    {
        $schemas = [];

        foreach ($this->paths as $path) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() &&
                    $file->getExtension() === 'php' &&
                    str_contains($file->getFilename(), 'Schema.php')) {

                    $content = file_get_contents($file->getPathname());

                    // Ищем namespace и class
                    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch) &&
                        preg_match('/class\s+(\w+Schema)/', $content, $classMatch)) {

                        $fullClassName = $nsMatch[1] . '\\' . $classMatch[1];

                        // Проверяем, что класс реализует MigrationsSchema
                        if (strpos($content, 'extends MigrationsSchema') !== false) {
                            $schemas[$file->getPathname()] = $fullClassName;
                        }
                    }
                }
            }
        }

        return $schemas;
    }

    /**
     * Загружает сохраненные версии схем
     */
    private function loadVersions(): array
    {
        if (!file_exists($this->versionFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->versionFile), true) ?: [];
    }

    /**
     * Сохраняет версии схем
     */
    private function saveVersions(array $versions): void
    {
        file_put_contents($this->versionFile, json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Загружает историю миграций
     */
    private function loadMigrationHistory(): array
    {
        if (!file_exists($this->migrationHistoryFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->migrationHistoryFile), true) ?: [];
    }

    /**
     * Сохраняет историю миграций
     */
    private function saveMigrationHistory(array $history): void
    {
        file_put_contents($this->migrationHistoryFile, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Получает список всех миграций для конкретной схемы в хронологическом порядке
     */
    public function getMigrationHistoryForSchema(string $schemaClass): array
    {
        $history = $this->loadMigrationHistory();
        return $history[$schemaClass] ?? [];
    }

    /**
     * Получает полную историю всех миграций
     */
    public function getFullMigrationHistory(): array
    {
        return $this->loadMigrationHistory();
    }

    /**
     * Проверяет, какие миграции еще не были сгенерированы
     */
    public function getPendingMigrations(): array
    {
        $schemas = $this->findAllSchemas();
        $migrationHistory = $this->loadMigrationHistory();
        $pending = [];

        foreach ($schemas as $schemaPath => $schemaClass) {
            $schema = new $schemaClass();
            $schemaHistory = $migrationHistory[$schemaClass] ?? [];
            $allVersions = $schema::VERSIONS;

            foreach ($allVersions as $version => $migrationData) {
                if (!isset($schemaHistory[$version])) {
                    $pending[$schemaClass][$version] = $migrationData;
                }
            }
        }

        return $pending;
    }

    /**
     * Принудительно регенерирует миграцию для конкретной версии схемы
     */
    public function regenerateMigration(string $schemaClass, string $version): bool
    {
        $schemas = $this->findAllSchemas();
        $schemaPath = array_search($schemaClass, $schemas);

        if (!$schemaPath) {
            return false;
        }

        $schema = new $schemaClass();
        $allVersions = $schema::VERSIONS;

        if (!isset($allVersions[$version])) {
            return false;
        }

        $migrationData = $allVersions[$version];
        $migrationFile = $this->generateMigrationForVersion($schema, $schemaPath, $version, $migrationData);

        // Обновляем историю
        $migrationHistory = $this->loadMigrationHistory();
        $migrationHistory[$schemaClass][$version] = [
            'generated_at' => date('Y-m-d H:i:s'),
            'file' => $migrationFile,
            'description' => $migrationData['description'],
            'regenerated' => true
        ];

        $this->saveMigrationHistory($migrationHistory);

        echo "🔄 Regenerated migration for {$schemaClass} v{$version}: {$migrationFile}\n";

        return true;
    }
}