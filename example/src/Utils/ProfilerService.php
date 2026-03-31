<?php

namespace SpsNext\Utils;
class ProfilerService
{
    private string $namespace;
    private string $outputDir;
    private string $xhprofLibPath;

    /** интервал чекпоинтов (сек) */
    private int $checkpointInterval = 10;

    /** время последнего чекпоинта */
    private int $lastCheckpointAt = 0;

    /** активировано ли профилирование */
    private bool $enabled = false;

    /** базовый run id */
    private string $baseRunId;

    public function __construct(
        string $namespace = 'app',
        string $outputDir = '/tmp/xhprof',
        string $xhprofLibPath = '/var/www/xhprof/xhprof_lib'
    ) {
        $this->namespace = $namespace;
        $this->outputDir = $outputDir;
        $this->xhprofLibPath = $xhprofLibPath;
    }

    /**
     * Запускает профилирование, если передан параметр GET и расширение доступно
     */
    public function init(string $triggerParam = 'start_profile'): void
    {
        if (!isset($_GET[$triggerParam]) || !extension_loaded('xhprof')) {
            return;
        }

        ignore_user_abort(true);
        set_time_limit(0);

        $this->baseRunId = $this->generateBaseRunId();
        $this->lastCheckpointAt = time();

        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
        $this->enabled = true;

        // чекпоинты
        register_tick_function([$this, 'checkpoint']);
        register_shutdown_function([$this, 'saveFinal']);
    }

    /**
     * Чекпоинт (вызывается автоматически)
     */
    public function checkpoint(): void
    {
        if (!$this->enabled) {
            return;
        }

        if ((time() - $this->lastCheckpointAt) < $this->checkpointInterval) {
            return;
        }

        $this->lastCheckpointAt = time();

        $data = xhprof_disable();
        $this->saveData($data, 'checkpoint');

        // перезапускаем профилирование
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }

    /**
     * Финальное сохранение (shutdown)
     */
    public function saveFinal(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->enabled = false;

        $data = xhprof_disable();
        $this->saveData($data, 'final');
    }

    /**
     * Унифицированное сохранение
     */
    private function saveData(array $data, string $type): void
    {
        if (!$this->loadDependencies()) {
            error_log("ProfilerService: XHProf libraries not found at {$this->xhprofLibPath}");
            return;
        }

        try {
            // Класс из библиотеки UI
            $runs = new \XHProfRuns_Default($this->outputDir);

            $runId = sprintf(
                '%s-%s-%d-pid%d',
                $this->baseRunId,
                $type,
                time(),
                getmypid()
            );

            // Сохраняем
            $runs->save_run($data, $this->namespace, $runId);

        } catch (\Throwable $e) {
            error_log("ProfilerService error: " . $e->getMessage());
        }
    }

    /**
     * Базовый run id (один на запрос)
     */
    private function generateBaseRunId(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? 'cmd';

        // Убираем GET-параметры
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Обработка корня
        if ($uri === '/' || $uri === '') {
            $uri = 'index';
        }

        $clean = preg_replace('/[^a-zA-Z0-9\-]/', '_', $uri);
        $clean = trim(preg_replace('/_+/', '_', $clean), '_');

        return substr($clean, 0, 64);
    }

    private function loadDependencies(): bool
    {
        static $loaded = false;
        if ($loaded) {
            return true;
        }

        $utils = $this->xhprofLibPath . '/utils/xhprof_lib.php';
        $runs = $this->xhprofLibPath . '/utils/xhprof_runs.php';

        if (file_exists($utils) && file_exists($runs)) {
            require_once $utils;
            require_once $runs;
            $loaded = true;
            return true;
        }

        return false;
    }
}
