<?php

// Function to cache configuration values
function cacheConfig($key, $callback) {
    $cacheFile = __DIR__ . '/../.cache/dynamic/' . $key . '.php';
    $data = $callback();
    file_put_contents($cacheFile, '<?php return ' . var_export($data, true) . ';');
    echo "Cached configuration: $key\n";
    return $data;
}

// Function to load cached configuration with fallback
function loadCachedConfig($key, $fallback = []) {
    $cacheFile = __DIR__ . '/../.cache/dynamic/' . $key . '.php';
    if (file_exists($cacheFile)) {
        return require $cacheFile;
    }
    return $fallback;
}

// Build and cache Exchange1CConfig
function cacheDynamicConfigs() {

    // Create cache directory if not exists
    mkdir(__DIR__ . '/../.cache/dynamic', 0777, true);
    require_once __DIR__ . '/di_dynamic_config.php';
    echo "All dynamic configurations cached successfully.\n";
}