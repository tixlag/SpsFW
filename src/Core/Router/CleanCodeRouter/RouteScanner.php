<?php

namespace SpsFW\Core\Router\CleanCodeRouter;

use OpenApi\Attributes\Property;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\ValidateAttr;
use SpsFW\Core\Middleware\MiddlewareInterface;
use SpsFW\Core\Router\ClassScanner;
use SpsFW\Core\Validation\Enum\ParamsIn;
use SpsFW\Core\Validation\Validator;

class RouteScanner
{
    /**
     * @param array<string> $controllersDirs
     */
    public function __construct(
        private array $controllersDirs = []
    ) {
    }

    /**
     * @param string $dir
     * @return void
     */
    public function addControllerDirectory(string $dir): void
    {
        $this->controllersDirs[] = $dir;
    }

    /**
     * @return array<string, array>
     */
    public function scanRoutes(): array
    {
        $routes = [];

        foreach ($this->controllersDirs as $dir) {
            $controllerFiles = $this->findControllerFiles($dir);
            $controllerClassNames = $this->getControllerClassNames($controllerFiles);

            foreach ($controllerClassNames as $className) {
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);
                $controllerRoutes = $this->registerControllerRoutes($reflection);
                $routes = array_merge($routes, $controllerRoutes);
            }
        }

        return $routes;
    }

    /**
     * @param string $dir
     * @return array<string>
     */
    private function findControllerFiles(string $dir): array
    {
        $dir = new RecursiveDirectoryIterator($dir);
        $iterator = new RecursiveIteratorIterator($dir);
        $controllerFiles = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/Controller\.php$/', $file->getFilename())) {
                $controllerFiles[] = $file->getRealPath();
            }
        }

        return $controllerFiles;
    }

    /**
     * @param array<string> $controllerFiles
     * @return array<string>
     */
    private function getControllerClassNames(array $controllerFiles): array
    {
        $controllerClassNames = [];
        foreach ($controllerFiles as $file) {
            require_once $file;
            $className = ClassScanner::getPathToNamespace($file);
            $controllerClassNames[] = $className;
        }

        return $controllerClassNames;
    }

    /**
     * @param ReflectionClass $reflection
     * @return array<string, array>
     */
    private function registerControllerRoutes(ReflectionClass $reflection): array
    {
        $routes = [];
        $classMiddlewares = $this->collectMiddlewares($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodRouteAttributes = $method->getAttributes(Route::class);
            if (empty($methodRouteAttributes)) {
                continue;
            }

            $methodRoute = $methodRouteAttributes[0]->newInstance();
            $fullPath = $methodRoute->getPath();
            $compiled = $this->compileRoutePattern($fullPath);
            $middlewares = array_merge($classMiddlewares, $this->collectMiddlewares($method));
            $accessRules = $this->collectAccessRules($method);
            $httpMethods = $methodRoute->getHttpMethods();

            if (empty($httpMethods)) {
                $httpMethods[] = "GET";
            }

            $validationParams = $this->extractValidationParams($method);

            foreach ($httpMethods as $httpMethod) {
                $httpMethodString = is_string($httpMethod) ? $httpMethod : $httpMethod->value;
                $key = ($httpMethodString) . ':' . $fullPath;
                $routes[$key] = [
                    'controller' => $reflection->getName(),
                    'httpMethod' => $httpMethodString,
                    'method' => $method->getName(),
                    'rawPath' => $methodRoute->getPath(),
                    'pattern' => $compiled['pattern'],
                    'params' => $compiled['params'],
                    'middlewares' => $middlewares,
                    'access_rules' => $accessRules,
                    'dtos' => $validationParams,
                ];
            }
        }

        return $routes;
    }

    /**
     * @param ReflectionMethod $method
     * @return array<array>
     */
    private function extractValidationParams(ReflectionMethod $method): array
    {
        $methodParameters = $method->getParameters();
        $validationParams = [];

        foreach ($methodParameters as $methodParameter) {
            $dtoClass = $methodParameter->getType()->getName();
            $validationAttributes = $methodParameter->getAttributes(ValidateAttr::class, ReflectionAttribute::IS_INSTANCEOF);

            if (empty($validationAttributes)) {
                continue;
            }

            $validationAttribute = $validationAttributes[0];
            if ($validationAttribute->newInstance() instanceof JsonBody) {
                $validationParams[] = [
                    'in' => ParamsIn::Json,
                    'dto' => $dtoClass,
                    'rules' => $this->extractValidationRules($dtoClass),
                ];
            }
        }

        return $validationParams;
    }

    /**
     * @param string $dtoClass
     * @return array<string, array>
     */
    private function extractValidationRules(string $dtoClass): array
    {
        $rules = [];
        $reflection = new ReflectionClass($dtoClass);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyAttributes = $property->getAttributes(Property::class);
            $realPropertyName = $property->getName();

            foreach ($propertyAttributes as $propertyAttribute) {
                $attributesOpenApi = $propertyAttribute->getArguments();
                $propertyName = $attributesOpenApi['property'] ?? $property->getName();
                $propertyRules = [];

                foreach ($attributesOpenApi as $attributeKey => $attributeValue) {
                    $propertyRules['real_name'] = $realPropertyName;

                    if ($attributeKey === 'ref') {
                        if (isset($attributesOpenApi['type']) && $attributesOpenApi['type'] == 'array') {
                            $propertyRules['ref'] = $attributeValue;
                            $propertyRules['type'] = 'array';
                            $propertyRules['nested_rules'] = $this->extractValidationRules($attributeValue);
                        } else {
                            $propertyRules['ref'] = $attributeValue;
                            $propertyRules['nested_rules'] = $this->extractValidationRules($attributeValue);
                        }
                        break;
                    }

                    if (isset(Validator::$attributesOpenApi[$attributeKey])) {
                        $propertyRules[$attributeKey] = $attributeValue;
                    }
                }

                if (!empty($propertyRules)) {
                    $rules[$propertyName] = $propertyRules;
                }
            }
        }

        return $rules;
    }

    /**
     * @param string $path
     * @return array{pattern: string, params: array<string>}
     */
    private function compileRoutePattern(string $path): array
    {
        $params = [];
        $pattern = preg_replace_callback('/{([a-zA-Z0-9_-]+)}/', function ($matches) use (&$params) {
            $params[$matches[1]] = null;
            return '([^/]+)';
        }, $path);

        if (empty($params)) {
            return [
                'pattern' => '',
                'params' => [],
            ];
        }

        return [
            'pattern' => '#^' . $pattern . '$#',
            'params' => $params,
        ];
    }

    /**
     * @param ReflectionClass|ReflectionMethod $reflection
     * @return array<array{class: class-string<MiddlewareInterface>, params: array<string, mixed>}>
     */
    private function collectMiddlewares($reflection): array
    {
        $middlewares = [];
        $attributes = $reflection->getAttributes(Middleware::class);

        foreach ($attributes as $attribute) {
            /** @var Middleware $middlewareAttr */
            $middlewareAttr = $attribute->newInstance();
            $middlewares = array_merge($middlewares, $middlewareAttr->getMiddlewares());
        }

        return $middlewares;
    }

    /**
     * @param ReflectionMethod $method
     * @return array<array<string>>
     */
    private function collectAccessRules(ReflectionMethod $method): array
    {
        $attributesAny = $method->getAttributes(AccessRulesAny::class);
        $attributesAll = $method->getAttributes(AccessRulesAll::class);
        $noAuthAccess = $method->getAttributes(NoAuthAccess::class);

        if (!empty($noAuthAccess)) {
            return ['NO_AUTH_ACCESS'];
        }

        if (empty($attributesAny)) {
            return [];
        }

        $accessRules = [];

        foreach ($attributesAny as $attribute) {
            $accessRuleAttribute = $attribute->newInstance();
            $accessRules['any'] = [
                'rules' => $accessRuleAttribute->getRequiredRules(),
            ];
        }

        foreach ($attributesAll as $attribute) {
            $accessRuleAttribute = $attribute->newInstance();
            $accessRules['all'] = [
                'rules' => $accessRuleAttribute->getRequiredRules(),
            ];
        }

        return $accessRules;
    }
}