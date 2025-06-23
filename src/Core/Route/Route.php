<?php

namespace SpsFW\Core\Route;

use Attribute;
use SpsFW\Core\Http\HttpMethod;


#[Attribute(Attribute::TARGET_METHOD)]
class Route {
    /**
     * Устанавливается над методом контроллера,
     * показывая по какому пути и Http методу вызывать его
     * @param string $path
     * @param HttpMethod[] $httpMethods
     */
    public function __construct(
        private string $path = '',
        private array $httpMethods = [HttpMethod::GET],
    ) {}

    /**
     * @return array
     */
    public function getHttpMethods(): array
    {
        return $this->httpMethods;
    }

    public function getPath(): string {
        return $this->path;
    }
}