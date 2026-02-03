<?php

namespace SpsFW\Core\Http;

class Request
{

    private array $get;
    private array $post;
    private array $server;

    private static ?Request $request = null;

    /**
     * @param array<string, string> $params Параметры маршрута
     * @param array<string, string> $headers Заголовки запроса
     * @param ?string $content Тело запроса
     */
    private function __construct(
        private array $params = [],
        private array $headers = [],
        private ?string $content = null
    ) {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        // Загрузка заголовков из $_SERVER
        if (empty($this->headers)) {
            $this->parseHeaders();
        }

        // Получение содержимого запроса, если оно не передано
        if ($this->content === null) {
            $this->content = file_get_contents('php://input') ?: null;
        }
    }

    public static function getInstance(): self
    {
        if (!self::$request) {
            self::$request = new self();
        }
        return self::$request;
    }

    private function parseHeaders(): void
    {
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $this->headers[$name] = $value;
            }
        }
    }

    public function getMethod(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    public function getRequestUri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri)[0];
        return $path === '' ? '/' : $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGet(): array
    {
        return $this->convertTypes($this->get);
    }

    /**
     * @return array<string, string|array>
     */
    public function getPost(): array
    {
        return $this->post;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getParam(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * @param array<string, string> $params
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Возвращает данные JSON из тела запроса
     *
     * @return array<string, mixed>|null
     */
    public function getJsonData(): ?array
    {
        if ($this->content === null) {
            return null;
        }
    
        $contentType = $this->getHeader('Content-Type');
        if ($contentType && str_contains($contentType, 'application/json')) {
            return json_decode($this->content, true);
        }

        return null;
    }

    public static function getUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri)[0];
        return $path === '' ? '/' : $path;
    }

    /**
     * Convert string values in an array to their appropriate types
     *
     * @param array $data
     * @return array
     */
    private function convertTypes(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->convertTypes($value);
            } else {
                $result[$key] = $this->convertType($value);
            }
        }
        return $result;
    }

    /**
     * Convert a single value to its appropriate type
     *
     * @param string $value
     * @return mixed
     */
    private function convertType(string $value): mixed
    {
        // Check for boolean values
        if ($value === 'true') {
            return true;
        }
        
        if ($value === 'false') {
            return false;
        }
        
        // Check for null
        if ($value === 'null') {
            return null;
        }
        
        // Check for numeric values
        if (is_numeric($value)) {
            // Check if it's an integer
            if (ctype_digit($value) || (ltrim($value, '-+') !== '' && ctype_digit(ltrim($value, '+-')))) {
                return (int) $value;
            }
            
            // It's a float
            return (float) $value;
        }
        
        // Return as string if no conversion applies
        return $value;
    }
}