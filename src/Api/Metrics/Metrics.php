<?php

namespace SpsFW\Api\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\Exception\StorageException;
use Prometheus\Histogram;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\PDO;
use SpsFW\Core\Db\Db;
use SpsFW\Core\Http\Request;

class Metrics
{
    public static CollectorRegistry $registry;

    private static Histogram $requestDurationHistogram;

    private static Counter $requestCounter;

    private static Counter $errorCounter;


    /**
     * @throws MetricsRegistrationException
     */
    public static function init(): void
    {

        try {
//            Metrics::$registry = new CollectorRegistry(
//                RedisNg::fromExistingConnection(RedisHelper::getInstance()->getRedis())
//            );
            Metrics::$registry = new CollectorRegistry(new PDO(Db::get()));

        } catch (StorageException $e) {
            Metrics::$registry = new CollectorRegistry(new PDO(Db::get()));
        }
        // Гистограмма: время выполнения запросов
        Metrics::$requestDurationHistogram = Metrics::$registry->getOrRegisterHistogram(
            'http_requests',
            'duration',
            'Duration of HTTP requests in ms',
            ['method', 'path', 'status']
        );

        // Счетчик: количество запросов по эндпоинтам
        Metrics::$requestCounter = Metrics::$registry->getOrRegisterCounter(
            'http_requests',
            'total',
            'Total number of HTTP requests',
            ['method', 'path', 'status']
        );

        // Счетчик: количество запросов по эндпоинтам
        Metrics::$errorCounter = Metrics::$registry->getOrRegisterCounter(
            'http_requests',
            'error',
            'Total number of HTTP errors',
            ['exception', 'method', 'path', 'status']
        );
    }

    public static function observeRequest(?float $duration, ?string $method, ?string $path, ?int $status): void
    {
        Metrics::$requestDurationHistogram->observe($duration, [$method, $path, $status]);
    }

    public static function incrementRequests(?string $method, ?string $path, ?int $status): void
    {
        Metrics::$requestCounter->inc([$method, $path, $status]);
    }

    public static function incrementErrors(?\Throwable $e, ?string $method, ?string $path, ?int $status): void
    {

        Metrics::$errorCounter->inc([$e::class, $method, $path, $status]);
    }

    /**
     * @throws \Throwable
     */
    public static function getMetrics(): string
    {
        $renderer = new RenderTextFormat();
        return $renderer->render(Metrics::$registry->getMetricFamilySamples());
    }

    public static function createAll(?string $path = null): void
    {
        if ($path === '/api/metrics') return;

        global $globalStartTime;
        if (isset($globalStartTime) && $globalStartTime > 0) {
            $endTime = hrtime(true);
            $duration = ($endTime - $globalStartTime) / 1e6; // В миллисекундах
            if (!headers_sent()) {
                header(sprintf('X-Request-Time: %s', number_format($duration, 2)));
            }

            Metrics::observeRequest($duration, $_SERVER['REQUEST_METHOD'],  $path ?? Request::getUri(), http_response_code());
        }
        Metrics::incrementRequests($_SERVER['REQUEST_METHOD'], $path ?? Request::getUri(), http_response_code());
    }
}