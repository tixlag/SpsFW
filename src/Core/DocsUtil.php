<?php

namespace SpsFW\Core;

use OpenApi\Loggers\DefaultLogger;
use SpsFW\Core\Http\Response;

class DocsUtil
{

    /** В зависимости от того, где развернуто окружение, формируем маршруты и сохраняем их
     * @return void
     */
    public static function updateDocs(): void
    {

        if (defined( SPS_DEVELOPMENT_VERSION) && !SPS_DEVELOPMENT_VERSION  && is_dir("/var/www/lk.sps38.pro/")) {
            $openapi = new \OpenApi\Generator(new class extends DefaultLogger {
                public function warning($message, array $context = array()): void
                {
                    return;
                }
            })->generate([ '/var/www/lk.sps38.pro/www/src/src/', '/var/www/lk.sps38.pro/www/src/Sps/']);

            file_put_contents("/var/www/lk.sps38.pro/www/src/src/Swagger/View/openapi.yaml", $openapi->toYaml());
            file_put_contents("/var/www/dev.sps38.pro/www/src/src/Api/Swagger/View/openapi.yaml", $openapi->toYaml());

        } elseif (is_dir("/var/www/dev.sps38.pro/")) {
            $openapi = new \OpenApi\Generator(new class extends DefaultLogger {
                public function warning($message, array $context = array()): void
                {
                    return;
                }
            })->generate(['/var/www/dev.sps38.pro/www/src/src/', '/var/www/dev.sps38.pro/www/src/Sps/']);

            file_put_contents("/var/www/dev.sps38.pro/www/src/src/Api/Swagger/View/openapi.yaml", $openapi->toYaml());
        } else {
            $openapi = new \OpenApi\Generator(new class extends DefaultLogger {
                public function warning($message, array $context = array()): void
                {
                    return;
                }
            })->generate([ '/var/www/html/www/src/Sps/', '/var/www/html/www/src/src/']);

            file_put_contents("/var/www/html/www/src/src/Api/Swagger/View/openapi.yaml", $openapi->toYaml());
        }

    }
}