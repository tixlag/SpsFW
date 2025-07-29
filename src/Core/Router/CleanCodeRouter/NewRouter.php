<?php

namespace SpsFW\Core\Router\CleanCodeRouter;

use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Middleware\MiddlewareInterface;

class NewRouter
{
    private RouteScanner $routeScanner;
    private RouteCacheManager $cacheManager;
    private RouteMatcher $routeMatcher;
    private RouteProcessor $routeProcessor;
    private DependencyResolver $dependencyResolver;
    private Request $request;

    /**
     * @var array<string, array>
     */
    private array $routes = [];

    /**
     * @var array<string, mixed>
     */
    private array $globalMiddlewares = [];

    private array $currentRoute = [];

    /**
     * @param string|null $controllersDir
     * @param bool $useCache
     * @param array<string, mixed> $dependencies
     * @throws BaseException
     */
    public function __construct(
        ?string $controllersDir = null,
        bool $useCache = true,
        array $dependencies = []
    ) {
        $this->request = Request::getInstance();

        // Инициализация сканера маршрутов
        $controllersDirs = [
            __DIR__ . '/Core/',
            __DIR__ . '/../../../../../../src',
        ];

        if ($controllersDir !== null) {
            $controllersDirs[] = $controllersDir;
        }

        $this->routeScanner = new RouteScanner($controllersDirs);

        // Инициализация менеджера кэша
        $cacheDir = __DIR__ . '/../../../../../../var/cache';
        $this->cacheManager = new RouteCacheManager($cacheDir, $useCache);

        // Инициализация разрешителя зависимостей
        $this->dependencyResolver = new DependencyResolver($dependencies);

        // Загрузка маршрутов
        $this->loadRoutes();

        // Инициализация matcher и processor
        $this->routeMatcher = new RouteMatcher($this->routes);
        $this->routeProcessor = new RouteProcessor($this->dependencyResolver, $this->globalMiddlewares);
    }

    /**
     * @param class-string<MiddlewareInterface> $middlewareClass
     * @param array<string, mixed> $params
     * @return self
     */
    public function addGlobalMiddleware(string $middlewareClass, array $params = []): self
    {
        $this->globalMiddlewares[$middlewareClass] = $params;
        return $this;
    }

    /**
     * @param bool $createCache
     * @return void
     */
    public function loadRoutes(bool $createCache = false): void
    {
        if (!$createCache) {
            $cachedRoutes = $this->cacheManager->loadRoutesFromCache();
            if ($cachedRoutes !== null) {
                $this->routes = $cachedRoutes;
                return;
            }
        }

        $this->routes = $this->routeScanner->scanRoutes();

        if ($this->cacheManager->isCacheEnabled() || $createCache) {
            $this->cacheManager->saveRoutesToCache($this->routes);
        }
    }

    /**
     * @return Response
     */
    public function dispatch(): Response
    {
        try {
            $this->currentRoute = $this->routeMatcher->findRoute($this->request);
            return $this->routeProcessor->processRoute($this->currentRoute, $this->request);
        } catch (\Throwable $e) {
            if (!($e instanceof BaseException)) {
                error_log(
                    sprintf(
                        "ApplicationError exception: %s on %u in %s\nTrace: %s\n--- End of trace",
                        $e->getMessage(),
                        $e->getLine(),
                        $e->getFile(),
                        $e->getTraceAsString()
                    )
                );
            }

            return Response::error($e);
        }
    }

    /**
     * @return void
     */
    public function createRoutesCache(): void
    {
        $this->loadRoutes(true);
    }

    /**
     * @return void
     */
    public function clearRoutesCache(): void
    {
        $this->cacheManager->clearCache();
    }

    /**
     * @return array<string, array>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * @return array
     */
    public function getCurrentRoute(): array
    {
        return $this->currentRoute;
    }

    /**
     * @param string $dependency
     * @param mixed $value
     * @return void
     */
    public function addDependency(string $dependency, mixed $value): void
    {
        $this->dependencyResolver->addDependency($dependency, $value);
    }

    /**
     * @param string $dir
     * @return void
     */
    public function addControllerDirectory(string $dir): void
    {
        $this->routeScanner->addControllerDirectory($dir);
    }

}