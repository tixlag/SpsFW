<?php

namespace SpsFW\Core;

use OpenApi\Annotations\OpenApi as OpenApiAlias;
use OpenApi\Attributes\OpenApi;
use OpenApi\Generator;
use OpenApi\Loggers\DefaultLogger;
use SpsFW\Core\Router\PathManager;

class DocsUtil
{
    // TODO доделать сохранине щзутфзш
    /**
     * Генерирует OpenAPI документацию, используя относительные пути.
     */
    public static function updateDocs(): void
    {

        // Пути для сканирования аннотаций
        $scanPaths = [
            PathManager::getLibraryRoot(),
            PathManager::getSrcPath() ,
        ];

        // Путь для сохранения YAML-файла
        $outputPath = PathManager::getProjectRoot() . '/.cache/swagger/openapi.yml';

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

        $openapi->setConfig([
            'operationId.hash' => false,
            'vaersion' => OpenApiAlias::VERSION_3_1_0
        ]);

        // Запускаем генерацию из найденных путей
        $generatedOpenApi = $openapi->generate($scanPaths);



        // Сохраняем результат
        file_put_contents($outputPath, $generatedOpenApi->toYaml());
    }
}