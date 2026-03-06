<?php

namespace SpsFW\Core;

use OpenApi\Annotations\OpenApi as OpenApiAlias;
use OpenApi\Attributes\OpenApi;
use OpenApi\Generator;
use OpenApi\Loggers\DefaultLogger;
use OpenApi\Pipeline;
use SpsFW\Core\Router\PathManager;
use SpsFW\Core\Swagger\SetOperationIdFromMethodNameProcessor;

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
            PathManager::getSrcPath() ,
            PathManager::getLibraryRoot(),
        ];

        // Путь для сохранения YAML-файла
        $outputPath = PathManager::getProjectRoot() . '/.cache/swagger/openapi.yml';

        unlink($outputPath);
        // Убедимся, что целевая директория существует
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }
        $openapi = self::createCustomGenerator();

        // Запускаем генерацию из найденных путей
        $generatedOpenApi = $openapi->generate($scanPaths);



        // Сохраняем результат
        file_put_contents($outputPath, $generatedOpenApi->toYaml());
    }

    /**
     * @return Generator
     */
    public static function createCustomGenerator(): Generator
    {
// Создаём генератор с подавленными предупреждениями
        $openapi = new Generator(new class extends DefaultLogger {
            public function warning($message, array $context = []): void
            {
                // Игнорируем все предупреждения
                return;
            }
        });

        $defaultProcessors = [
            new \OpenApi\Processors\DocBlockDescriptions(),
            new \OpenApi\Processors\MergeIntoOpenApi(),
            new \OpenApi\Processors\MergeIntoComponents(),
            new \OpenApi\Processors\ExpandClasses(),
            new \OpenApi\Processors\ExpandInterfaces(),
            new \OpenApi\Processors\ExpandTraits(),
            new \OpenApi\Processors\ExpandEnums(),
            new \OpenApi\Processors\AugmentSchemas(),
            new \OpenApi\Processors\AugmentRequestBody(),
            new \OpenApi\Processors\AugmentProperties(),
            new \OpenApi\Processors\AugmentDiscriminators(),
            new \OpenApi\Processors\BuildPaths(),
            new \OpenApi\Processors\AugmentParameters(),
            new \OpenApi\Processors\AugmentRefs(),
            new \OpenApi\Processors\MergeJsonContent(),
            new \OpenApi\Processors\MergeXmlContent(),
//            new \OpenApi\Processors\OperationId(),
            new SetOperationIdFromMethodNameProcessor(),
            new \OpenApi\Processors\CleanUnmerged(),
            new \OpenApi\Processors\PathFilter(),
            new \OpenApi\Processors\CleanUnusedComponents(),
            new \OpenApi\Processors\AugmentTags(),
        ];

        $pipeline = new Pipeline($defaultProcessors);
        $openapi->setProcessorPipeline($pipeline);
        $openapi->setConfig([
            'operationId.hash' => false,
            'version' => OpenApiAlias::VERSION_3_1_0,

        ]);
        $openapi->setVersion(OpenApiAlias::VERSION_3_1_0);
        return $openapi;
    }
}