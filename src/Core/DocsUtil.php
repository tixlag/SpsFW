<?php

namespace SpsFW\Core;

use OpenApi\Generator;
use OpenApi\Loggers\DefaultLogger;

class DocsUtil
{
    protected static string $cacheDir = __DIR__ . '/../../../../../../var/cache';
    /**
     * Генерирует OpenAPI документацию, используя относительные пути.
     * Поднимается на 2 уровня вверх от текущего файла для определения корня проекта.
     */
    public static function updateDocs(): void
    {
        // Получаем путь к корневой директории проекта (две директории вверх от __FILE__)
        $frameworkPath = dirname(__DIR__, 1);
        $projectPath = dirname(__DIR__, 3);

        // Пути для сканирования аннотаций
        $scanPaths = [
            $frameworkPath . '/Sps/',
            $frameworkPath . '/src/',
        ];

        // Путь для сохранения YAML-файла
        $outputPath = self::$cacheDir. '/openapi.yaml';

        // Убедимся, что целевая директория существует
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        // Создаём генератор с подавленными предупреждениями
        $openapi = new Generator(new class extends DefaultLogger {
            public function warning($message, array $context = []): void
            {
                // Игнорируем все предупреждения
                return;
            }
        });

        // Запускаем генерацию из найденных путей
        $generatedOpenApi = $openapi->generate($scanPaths);

        // Сохраняем результат
        file_put_contents($outputPath, $generatedOpenApi->toYaml());
    }
}