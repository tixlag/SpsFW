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
     * @return array<string, string>
     */
    public function getGet(): array
    {
        return $this->get;
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
    
        if ($this->getRequestUri() === '/api/exchange/1c/users') {
            file_put_contents('/var/www/next.sps38.pro/.tmp/logs/erp/raw-big.json', $this->content);
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
}