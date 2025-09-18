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
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            // Извлекаем namespace (оставляем как есть — он работает)
            if (preg_match_all('/namespace\s+(.+?);/', $content, $nsMatches)) {
                $namespace = trim($nsMatches[1][0]);
            } else {
                $namespace = '';
            }

            // ✅ Заменяем регулярку на токен-парсер
            $tokens = token_get_all($content);
            $inAbstract = false;

            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];

                // Пропускаем не-токены (например, '{', '}', ';')
                if (!is_array($token)) {
                    continue;
                }

                [$id, $text] = $token;

                // Игнорируем всё, что внутри строк, комментариев и т.п.
                if (in_array($id, [
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_WHITESPACE,
                    T_CONSTANT_ENCAPSED_STRING, // строки в одинарных/двойных кавычках
                    T_ENCAPSED_AND_WHITESPACE,  // строки с переменными внутри
                    T_STRING,                   // просто строки (например, 'class' как значение)
                ])) {
                    continue;
                }

                // Поддержка abstract class
                if ($id === T_ABSTRACT) {
                    $inAbstract = true;
                    continue;
                }

                // Находим ключевое слово class
                if ($id === T_CLASS) {
                    // Пропускаем пробелы после class
                    $nextIndex = $i + 1;
                    while ($nextIndex < count($tokens)
                        && is_array($tokens[$nextIndex])
                        && $tokens[$nextIndex][0] === T_WHITESPACE
                    ) {
                        $nextIndex++;
                    }

                    // Проверяем, что следующий токен — имя класса (T_STRING)
                    if ($nextIndex < count($tokens)
                        && is_array($tokens[$nextIndex])
                        && $tokens[$nextIndex][0] === T_STRING
                    ) {
                        $className = $tokens[$nextIndex][1];

                        // Проверяем соглашение об именовании: с заглавной буквы
                        if (preg_match('/^[A-Z][a-zA-Z0-9_]*$/', $className)) {
                            $full = $namespace ? "$namespace\\$className" : $className;
                            $classes[] = $full;
                        }
                    }

                    // Сбрасываем флаг abstract
                    $inAbstract = false;
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
