<?php

namespace SpsFW\Core\Router;

use JetBrains\PhpStorm\Deprecated;
use ReflectionClass;
use ReflectionException;
use SpsFW\Core\Attributes\AccessRulesAttr;
use SpsFW\Core\Auth\Util\AccessRuleRegistry;

#[Deprecated]
class AccessRulesCacheBuilder
{
    private string $cachePath;


    public function __construct(string $cachePath)
    {
        $this->cachePath = $cachePath;
    }

    /**
     * @throws ReflectionException
     */
    public function compile(array $classList): void
    {
        foreach ($classList as $class) {
            $reflection = new ReflectionClass($class);
            $accessRulesAttrs = $reflection->getAttributes(AccessRulesAttr::class);

            if (empty($accessRulesAttrs)) continue;

            AccessRuleRegistry::register($class);

        }

        $this->writeToFile();
    }


    private function writeToFile(): void
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath , 0777, true);
        }

        $export = var_export(AccessRuleRegistry::getAllRules(), true);
        $php = "<?php\n\nreturn $export;\n";
        file_put_contents($this->cachePath  . '/compiled_access_rules.php', $php);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }


}