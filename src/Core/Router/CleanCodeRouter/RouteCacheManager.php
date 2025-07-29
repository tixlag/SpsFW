<?php

namespace SpsFW\Core\Router\CleanCodeRouter;

class RouteCacheManager
{
    public function __construct(
        private string $cacheDir,
        private bool $useCache = true
    ) {
    }

    /**
     * @return array<string, array>|null
     */
    public function loadRoutesFromCache(): ?array
    {
        if (!$this->useCache) {
            return null;
        }

        try {
            $compiledRoutesFile = $this->getCacheFilePath();

            if (file_exists($compiledRoutesFile)) {
                return require $compiledRoutesFile;
            }
        } catch (\Error $e) {
            // Кэш поврежден, игнорируем
        }

        return null;
    }

    /**
     * @param array<string, array> $routes
     * @return void
     */
    public function saveRoutesToCache(array $routes): void
    {
        if (!$this->useCache) {
            return;
        }

        $routesString = var_export($routes, true);
        $php = "<?php\n\nreturn $routesString;\n";

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        file_put_contents($this->getCacheFilePath(), $php);
    }

    /**
     * @return void
     */
    public function clearCache(): void
    {
        $cacheFile = $this->getCacheFilePath();

        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * @return bool
     */
    public function isCacheEnabled(): bool
    {
        return $this->useCache;
    }

    /**
     * @return string
     */
    private function getCacheFilePath(): string
    {
        return $this->cacheDir . '/compiled_routes.php';
    }
}