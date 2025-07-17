<?php

namespace SpsFW\Core\Db\Models;

use ReflectionClass;
use SpsFW\Core\Db\Migration\MigrationsSchema;

class ModelGenerator
{
    private array $paths;
    private array $typeMapping = [
        'UUID' => 'string',
        'VARCHAR' => 'string',
        'TEXT' => 'string',
        'DATE' => 'string',
        'DATETIME' => 'DateTime',
        'TIMESTAMP' => 'DateTime',
        'INT' => 'int',
        'SMALLINT' => 'int',
        'TINYINT' => 'int',
        'BIGINT' => 'int',
        'DECIMAL' => 'float',
        'DOUBLE' => 'float',
        'FLOAT' => 'float',
        'BOOLEAN' => 'bool',
        'BOOL' => 'bool',
        'JSON' => 'array',
    ];

    public function __construct(string $projectRoot)
    {
        $this->paths[] = __DIR__ . '/../../';
        $this->paths[] = $projectRoot;
    }

    /**
     * Генерирует модели для всех схем
     */
    public function generateModels(): int
    {
        $schemas = $this->findAllSchemas();
        $generatedCount = 0;

        foreach ($schemas as $schemaPath => $schemaClass) {
            if ($this->generateModelForSchema($schemaClass, $schemaPath)) {
                $generatedCount++;
            }
        }

        return $generatedCount;
    }

    /**
     * Генерирует модель для конкретной схемы
     */
    private function generateModelForSchema(string $schemaClass, string $schemaPath): bool
    {
        $schema = new $schemaClass();
        $config = $schema::MODEL_CONFIG ?? [];

        if (empty($config)) {
            return false; // Нет конфигурации модели
        }

        // Парсим SQL для извлечения структуры таблицы
        $tableStructure = $this->parseTableStructure($schema);

        // Генерируем код модели
        $modelCode = $this->generateModelCode($schema, $config, $tableStructure);

        // Сохраняем модель
        $modelPath = $this->getModelPath($schemaPath, $config['class_name']);
        file_put_contents($modelPath, $modelCode);

        echo "🏗️ Generated model for {$schemaClass}: {$modelPath}\n";
        return true;
    }

    /**
     * Парсит SQL CREATE TABLE для извлечения структуры
     */
    private function parseTableStructure(MigrationsSchema $schema): array
    {
        $versions = $schema::VERSIONS;
        $createTableSql = '';

        // Находим SQL для создания таблицы
        foreach ($versions as $version => $data) {
            if (stripos($data['up'], 'CREATE TABLE') !== false) {
                $createTableSql = $data['up'];
                break;
            }
        }

        if (empty($createTableSql)) {
            return [];
        }

        return $this->parseCreateTableSql($createTableSql);
    }

    /**
     * Парсит CREATE TABLE SQL и извлекает информацию о колонках
     */
    private function parseCreateTableSql(string $sql): array
    {
        $columns = [];

        // Регулярка для парсинга колонок
        $pattern = '/(\w+)\s+([A-Z]+(?:\([^)]+\))?)\s*(.*?)(?=,|\s*\))/i';

        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $columnName = $match[1];
            $columnType = $match[2];
            $columnConstraints = $match[3] ?? '';

            // Пропускаем системные ограничения
//            if (stripos($columnConstraints, 'PRIMARY KEY') !== false ||
//                stripos($columnConstraints, 'UNIQUE') !== false ||
//                stripos($columnConstraints, 'FOREIGN KEY') !== false) {
//                continue;
//            }

            $columns[$columnName] = [
                'type' => $this->mapSqlTypeToPhp($columnType),
                'nullable' => stripos($columnConstraints, 'NOT NULL') === false,
                'default' => $this->extractDefaultValue($columnConstraints),
                'comment' => $this->extractComment($columnConstraints)
            ];
        }

        return $columns;
    }

    /**
     * Маппинг SQL типов в PHP типы
     */
    private function mapSqlTypeToPhp(string $sqlType): string
    {
        $baseType = preg_replace('/\([^)]+\)/', '', strtoupper($sqlType));
        return $this->typeMapping[$baseType] ?? 'mixed';
    }

    /**
     * Извлекает значение по умолчанию
     */
    private function extractDefaultValue(string $constraints): ?string
    {
        if (preg_match('/DEFAULT\s+([^\s,]+)/i', $constraints, $matches)) {
            return $matches[1] === 'NULL' ? null : $matches[1];
        }
        return null;
    }

    /**
     * Извлекает комментарий
     */
    private function extractComment(string $constraints): ?string
    {
        if (preg_match('/COMMENT\s+[\'"]([^\'"]+)[\'"]/i', $constraints, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Генерирует код модели
     */
    private function generateModelCode(MigrationsSchema $schema, array $config, array $tableStructure): string
    {
        $reflection = new ReflectionClass($schema);
        $namespace = $reflection->getNamespaceName();
        $className = $config['class_name'];
        $extends = $config['extends'] ?? 'SpsFW\Core\Db\Models\BaseModel';
        $implements = !empty($config['implements']) ? ' implements ' . implode(', ', $config['implements']) : '';
        $tableName = $schema::TABLE_NAME;

        // Генерируем конструктор
        $constructorParams = $this->generateConstructorParams($tableStructure, $config);
        $constructorBody = $this->generateConstructorBody($tableStructure, $config);

        // Генерируем DocBlock для конструктора
        $docBlock = $this->generateConstructorDocBlock($tableStructure, $config);

        // Генерируем методы для связей
        $relationMethods = $this->generateRelationMethods($config['relations'] ?? []);

        // Генерируем jsonSerialize если нужно
        $jsonSerializeMethod = $this->generateJsonSerializeMethod($tableStructure, $config);

        // Генерируем дополнительные методы
        $additionalMethods = $this->generateAdditionalMethods($tableStructure, $config);

        return <<<PHP
<?php
namespace {$namespace};

use DateTime;
use JsonSerializable;
use {$extends};

/**
 * Auto-generated model for {$tableName} table
 * Generated: {date('Y-m-d H:i:s')}
 */
class {$className} extends {$this->getClassBaseName($extends)}{$implements}
{
    public const string TABLE = '{$tableName}';
    
    {$docBlock}
    public function __construct(
    {$constructorParams}
    ) {
        {$constructorBody}
    }
    
    {$relationMethods}
    {$additionalMethods}
    {$jsonSerializeMethod}
}
PHP;
    }

    /**
     * Генерирует DocBlock для конструктора
     */
    private function generateConstructorDocBlock(array $tableStructure, array $config): string
    {
        $params = [];

        // Добавляем специальные параметры для UserAbstract
        $extends = $config['extends'] ?? '';
        if (strpos($extends, 'UserAbstract') !== false) {
            $params[] = '     * @param string|null $id';
        }

        foreach ($tableStructure as $columnName => $columnInfo) {
            if ($columnName === 'id') continue; // Уже добавлен выше

            $phpType = $columnInfo['type'];
            $nullable = $columnInfo['nullable'] ? '|null' : '';
            $paramName = $this->convertColumnNameToProperty($columnName);

            $params[] = "     * @param {$phpType}{$nullable} \${$paramName}";
        }

        // Добавляем accessRules для UserAbstract
        if (strpos($extends, 'UserAbstract') !== false) {
            $params[] = '     * @param array|null $accessRules';
        }

        return "/**\n" . implode("\n", $params) . "\n     */";
    }

    /**
     * Генерирует параметры конструктора
     */
    private function generateConstructorParams(array $tableStructure, array $config): string
    {
        $params = [];
        $extends = $config['extends'] ?? '';

        // Для UserAbstract добавляем специальные параметры
        if (strpos($extends, 'UserAbstract') !== false) {
            $params[] = '?string $id';
        }

        foreach ($tableStructure as $columnName => $columnInfo) {
            if ($columnName === 'id') continue; // Уже добавлен выше

            $phpType = $columnInfo['type'];
            $nullable = $columnInfo['nullable'] ? '?' : '';
            $paramName = $this->convertColumnNameToProperty($columnName);

            $visibility = $this->getPropertyVisibility($columnName, $config);
            $defaultValue = $this->getDefaultValue($columnInfo);

            $param = "{$visibility} {$nullable}{$phpType} \${$paramName}";

            if ($defaultValue !== null) {
                $param .= " = {$defaultValue}";
            } elseif ($columnInfo['nullable']) {
                $param .= " = null";
            }

            $params[] = $param;
        }

        // Добавляем accessRules для UserAbstract
        if (strpos($extends, 'UserAbstract') !== false) {
            $params[] = '?array $accessRules = null';
        }

        return implode(",\n        ", $params);
    }

    /**
     * Генерирует тело конструктора
     */
    private function generateConstructorBody(array $tableStructure, array $config): string
    {
        $extends = $config['extends'] ?? '';

        if (strpos($extends, 'UserAbstract') !== false) {
            return 'parent::__construct($id, $accessRules, $hashedPassword);';
        }

        return 'parent::__construct($id);';
    }

    /**
     * Генерирует методы для связей
     */
    private function generateRelationMethods(array $relations): string
    {
        $methods = [];

        foreach ($relations as $relationName => $relationConfig) {
            if ($relationConfig['type'] === 'many_to_many') {
                $methods[] = $this->generateManyToManyMethods($relationName, $relationConfig);
            }
        }

        return implode("\n\n    ", $methods);
    }

    /**
     * Генерирует методы для связи many-to-many
     */
    private function generateManyToManyMethods(string $relationName, array $config): string
    {
        $methodName = $this->convertColumnNameToProperty($relationName);
        $capitalizedName = ucfirst($methodName);

        return <<<PHP
    /**
     * Возвращает значение правила, если установлено
     * @return array
     */
    public function get{$capitalizedName}Value(int \${$relationName}Id): mixed
    {
        return \$this->{$methodName}[\${$relationName}Id] ?? null;
    }
    
    /**
     * Устанавливает значение правила
     * @param int \${$relationName}Id
     * @param mixed \$value
     */
    public function set{$capitalizedName}Value(int \${$relationName}Id, mixed \$value): void
    {
        \$this->{$methodName}[\${$relationName}Id] = \$value;
    }
    
    /**
     * Добавляет правила
     */
    public function add{$capitalizedName}(array \${$methodName}): self
    {
        \$this->{$methodName} += \${$methodName};
        return \$this;
    }
    
    /**
     * Устанавливает значение правила
     * @param int \${$relationName}Id
     * @param mixed \$value
     */
    public function set{$capitalizedName}(array \${$methodName}): void
    {
        \$this->{$methodName} = \${$methodName};
    }
PHP;
    }

    /**
     * Генерирует дополнительные методы
     */
    private function generateAdditionalMethods(array $tableStructure, array $config): string
    {
        $methods = [];

        foreach ($tableStructure as $columnName => $columnInfo) {
            $visibility = $this->getPropertyVisibility($columnName, $config);

            if ($visibility === 'private(set)') {
                $propertyName = $this->convertColumnNameToProperty($columnName);
                $methodName = 'set' . ucfirst($propertyName);
                $phpType = $columnInfo['type'];
                $nullable = $columnInfo['nullable'] ? '?' : '';

                $methods[] = <<<PHP
public function {$methodName}({$nullable}{$phpType} \${$propertyName}): void
    {
        \$this->{$propertyName} = \${$propertyName};
    }
PHP;
            }
        }

        return implode("\n\n    ", $methods);
    }

    /**
     * Генерирует метод jsonSerialize
     */
    private function generateJsonSerializeMethod(array $tableStructure, array $config): string
    {
        if (!in_array('JsonSerializable', $config['implements'] ?? [])) {
            return '';
        }

        $excludeFields = $config['json_serialize']['exclude'] ?? [];
        $fields = [];

        foreach ($tableStructure as $columnName => $columnInfo) {
            $propertyName = $this->convertColumnNameToProperty($columnName);

            if (!in_array($propertyName, $excludeFields)) {
                $fields[] = "            '{$propertyName}' => \$this->{$propertyName}";
            }
        }

        // Добавляем связи
        foreach ($config['relations'] ?? [] as $relationName => $relationConfig) {
            $propertyName = $this->convertColumnNameToProperty($relationName);
            $fields[] = "            '{$propertyName}' => \$this->{$propertyName}";
        }

        $fieldsString = implode(",\n", $fields);

        return <<<PHP
    
    public function jsonSerialize(): mixed
    {
        return [
{$fieldsString},
        ];
    }
PHP;
    }

    /**
     * Определяет, должно ли свойство быть readonly
     */
    private function shouldBeReadonly(string $columnName, array $config): bool
    {
        $writeableFields = $config['writeable_fields'] ?? [];
        return !in_array($columnName, $writeableFields) && $columnName !== 'id';
    }

    /**
     * Получает видимость свойства
     */
    private function getPropertyVisibility(string $columnName, array $config): string
    {
        $writeableFields = $config['not_constructor_fields'] ?? [];
        $privateSetFields = $config['private_set_fields'] ?? [];

        if (in_array($columnName, $privateSetFields)) {
            return 'private(set)';
        }

        if (in_array($columnName, $writeableFields) || $columnName === 'id' || $columnName === 'uuid') {
            return '';
        }

        return 'readonly';
    }

    /**
     * Конвертирует имя колонки в имя свойства
     */
    private function convertColumnNameToProperty(string $columnName): string
    {
        return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
    }

    /**
     * Получает значение по умолчанию для PHP
     */
    private function getDefaultValue(array $columnInfo): ?string
    {
        $default = $columnInfo['default'];

        if ($default === null) {
            return null;
        }

        if ($default === 'NULL') {
            return 'null';
        }

        if (is_numeric($default)) {
            return $default;
        }

        return "'{$default}'";
    }

    /**
     * Получает базовое имя класса
     */
    private function getClassBaseName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Получает параметры для родительского конструктора
     */
    private function getParentConstructorParams(array $config): string
    {
        $extends = $config['extends'] ?? '';

        if (strpos($extends, 'UserAbstract') !== false) {
            return '$id, $accessRules, $hashedPassword';
        }

        return '$id';
    }

    /**
     * Получает путь для сохранения модели
     */
    private function getModelPath(string $schemaPath, string $className): string
    {
        $dir = dirname($schemaPath);
        return $dir . '/' . $className . '.php';
    }

    /**
     * Находит все файлы схем
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

                    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch) &&
                        preg_match('/class\s+(\w+Schema)/', $content, $classMatch)) {

                        $fullClassName = $nsMatch[1] . '\\' . $classMatch[1];

                        if (strpos($content, 'extends MigrationsSchema') !== false &&
                            strpos($content, 'MODEL_CONFIG') !== false) {
                            $schemas[$file->getPathname()] = $fullClassName;
                        }
                    }
                }
            }
        }

        return $schemas;
    }
}