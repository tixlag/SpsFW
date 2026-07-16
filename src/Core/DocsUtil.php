<?php

namespace SpsFW\Core;

use OpenApi\Annotations\OpenApi as OpenApiAlias;
use OpenApi\Attributes\OpenApi;
use OpenApi\Generator;
use OpenApi\Loggers\DefaultLogger;
use OpenApi\Pipeline;
use SpsFW\Core\Compile\RuntimeCompileGate;
use SpsFW\Core\Router\PathManager;
use SpsFW\Core\Swagger\SetOperationIdFromMethodNameProcessor;

class DocsUtil
{
    // TODO доделать сохранине щзутфзш
    /**
     * Генерирует OpenAPI документацию, используя относительные пути.
     *
     * In managed compile mode (outside dev) this independent swagger-php production build is FORBIDDEN — the
     * application preload (Coordinator) owns the OpenAPI artifact. {@see RuntimeCompileGate} throws, pointing at the
     * preload. Legacy behaves exactly as before.
     */
    public static function updateDocs(): void
    {
        RuntimeCompileGate::assertAllowed('OpenAPI documentation');

        // Путь для сохранения YAML-файла
        $outputPath = PathManager::getProjectRoot() . '/.cache/swagger/openapi.yml';

        unlink($outputPath);
        // Убедимся, что целевая директория существует
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        // Тот же legacy swagger-php продюсер, что использует Coordinator как parity-compatibility producer (см.
        // {@see produceLegacyOpenApiYaml()}): единый генератор/конфиг → побайтово тот же документ.
        file_put_contents($outputPath, self::produceLegacyOpenApiYaml([PathManager::getSrcPath(), PathManager::getLibraryRoot()]));
    }

    /**
     * Build the legacy swagger-php OpenAPI document as a YAML string — the SAME output {@see updateDocs()} writes —
     * WITHOUT the managed-mode gate, the unlink, or the file write. This is the parity-compatibility producer
     * (plan §11.2, Step 6b #5): until M6 the Coordinator keeps the PRIMARY .cache/swagger/openapi.yml (the spec Orval
     * reads) byte-identical with the historic swagger-php build, while the new graph emitter ships the SECONDARY
     * openapi.generated.yml. It does NOT go through {@see updateDocs()} (which is gated in managed) and leaves nothing
     * stale. The scan set ([src, libraryRoot]) is supplied by the caller; in production it matches legacy exactly.
     *
     * @param list<string> $scanPaths directories/files swagger-php scans (dirs only in production)
     */
    public static function produceLegacyOpenApiYaml(array $scanPaths): string
    {
        return self::createCustomGenerator()->generate($scanPaths)->toYaml();
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