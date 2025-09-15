<?php

namespace SpsFW\Core\Router;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ClassScanner

{
    public static function getClassesFromDir(string $dir): array
    {
        $classes = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php'
//                || !(str_ends_with($file->getFileName(), 'Controller.php') || str_ends_with($file->getFileName(), 'Service.php') || str_ends_with($file->getFileName(), 'Storage.php'))
            ) {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if (preg_match_all('/namespace\s+(.+?);/', $content, $nsMatches)) {
                $namespace = trim($nsMatches[1][0]);
            } else {
                $namespace = '';
            }

            if (preg_match_all('/(class\s)+([A-Z][a-zA-Z0-9_]*)/', $content, $classMatches)) {
                foreach ($classMatches[2] as $className) {
                    $full = $namespace ? "$namespace\\$className" : $className;
                    $classes[] = $full;
                }
            }
        }

        return $classes;
    }

    public static function getPathToNamespace($filePath, $withClassName = true): ?string
    {
        $ns = null;
        $handle = fopen($filePath, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (str_starts_with($line, 'namespace')) {
                    $partsPath = explode(DIRECTORY_SEPARATOR, $filePath);
                    $className = substr(array_pop($partsPath), 0, -4);
                    $parts = explode(' ', $line);
                    $ns = rtrim(trim($parts[1]), ';') .  ($withClassName  ? '\\' .$className : "");
                    break;
                }
            }
            fclose($handle);
        }
        return $ns;
    }
}
