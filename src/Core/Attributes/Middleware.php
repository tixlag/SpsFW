<?php

namespace SpsFW\Core\Attributes;


use Attribute;


#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
readonly class Middleware
{
    /**
     * @var array<array{class: class-string<\SpsFW\Core\Middleware\MiddlewareInterface>, params: array<string, mixed>}>
     */
    private array $middlewares;

    /**
     * @param array<class-string<\SpsFW\Core\Middleware\MiddlewareInterface>|array{class: class-string<\SpsFW\Core\Middleware\MiddlewareInterface>, params: array<string, mixed>}> $middlewares
     */
    public function __construct(array $middlewares)
    {
        $result = [];

        foreach ($middlewares as $key => $middleware) {
            // If the value is a string, it's just a class name without params
            if (is_string($middleware)) {
                $result[] = [
                    'class' => $middleware,
                    'params' => []
                ];
            }
            // If it's already in the correct format
            elseif (is_array($middleware) && isset($middleware['class'])) {
                $result[] = [
                    'class' => $middleware['class'],
                    'params' => $middleware['params'] ?? []
                ];
            }
            // If numeric key and array value, it could be just missing the 'class' key
            elseif (is_numeric($key) && is_array($middleware) && isset($middleware[0])) {
                $result[] = [
                    'class' => $middleware[0],
                    'params' => $middleware[1] ?? []
                ];
            }
        }
        $this->middlewares = $result;
    }

    /**
     * @return array<array{class: class-string<\SpsFW\Core\Middleware\MiddlewareInterface>, params: array<string, mixed>}>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }


}