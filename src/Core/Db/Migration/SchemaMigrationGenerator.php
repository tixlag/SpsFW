<?php

namespace SpsFW\Core\Db\Migration;

class SchemaMigrationGenerator
{
    private array $paths;
    private string $versionFile;

    public function __construct(string $projectRoot)
    {
        $this->paths[] = __DIR__ . '/../../';
        $this->paths[] = $projectRoot;
        $this->versionFile = $projectRoot . '/.schema_versions.json';
    }

    /**
     * Проверяет все схемы и генерирует миграции при необходимости
     */
    public function generateMigrations(): int
    {
        $schemas = $this->findAllSchemas();
        $currentVersions = $this->loadVersions();
        $generatedCount = 0;

        foreach ($schemas as $schemaPath => $schemaClass) {
            $schema = new $schemaClass();
            $currentVersion = $schema->getLastVersion();
            $storedVersion = $currentVersions[$schemaClass] ?? null;

            if ($storedVersion !== $currentVersion) {
                $this->generateMigrationForSchema($schema, $schemaPath);
                $currentVersions[$schemaClass] = $currentVersion;
                $generatedCount++;

                echo "📄 Generated migration for {$schemaClass} (v{$currentVersion})\n";
            }
        }

        $this->saveVersions($currentVersions);
        return $generatedCount;
    }

    /**
     * Находит все файлы схем в проекте
     */
    private function findAllSchemas(): array
    {
        $schemas = [];

        foreach ($this->paths as $path) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path )
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
     * Генерирует миграцию для конкретной схемы
     */
    private function generateMigrationForSchema(MigrationsSchema $schema, string $schemaPath): void
    {
        $upCode = $schema->getLastUp();
        $downCode = $schema->getLastDown();
        $migrationDir = dirname($schemaPath) . '/migrations';

        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0755, true);
        }

        // Генерируем уникальный timestamp
        sleep(1); // Добавляем небольшую задержку для уникальности
        $timestamp = date('YmdHis');
        $tableName = $schema->getTableName();

        // Самый простой подход - используем только timestamp для уникальности
        $uniqueTimestamp = $timestamp;

        $className = 'V' . $uniqueTimestamp;
        $fileName = $uniqueTimestamp . '.php';

        // Проверяем, что файл с таким именем не существует
        $fullPath = $migrationDir . '/' . $fileName;

        while (file_exists($fullPath)) {
            sleep(1); // Добавляем небольшую задержку для уникальности
            $timestamp = date('YmdHis');
            $newUniqueTimestamp = $timestamp;
            $className = 'V' . $newUniqueTimestamp;
            $fileName = $newUniqueTimestamp .'.php';
            $fullPath = $migrationDir . '/' . $fileName;

        }

        $migrationContent = $this->generateMigrationContent($className, $upCode, $downCode, $schema);

        file_put_contents($fullPath, $migrationContent);
    }

    /**
     * Генерирует содержимое файла миграции
     */
    private function generateMigrationContent(string $className, string $upCode, string $downCode, MigrationsSchema $schema): string
    {
        // Экранируем двойные кавычки в SQL-запросах
        $upCodeEscaped = str_replace('"', '\\"', $upCode);
        $downCodeEscaped = str_replace('"', '\\"', $downCode);

        return <<<PHP
<?php

use Phinx\Migration\AbstractMigration;

/**
 * Auto-generated migration from schema
 * Table: {$schema->getTableName()}
 * Version: {$schema->getLastVersion()}
 * Description: {$schema->getLastVersionDescription()}
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
        file_put_contents($this->versionFile, json_encode($versions, JSON_PRETTY_PRINT));
    }
}