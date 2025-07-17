<?php

namespace SpsFW\Core\Http;

use Exception;
use SpsFW\Core\Auth\Instances\Auth;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BaseException;

class Response
{


    /**
     * @param int $status HTTP статус
     * @param array<string, string> $headers HTTP заголовки
     * @param string $body Тело ответа
     * @param string $contentType Тип содержимого
     */
    public function __construct(
        private int $status = 200,
        private array $headers = [],
        private string $body = '',
        string $contentType = 'text/html'
    ) {
        $this->headers['Content-Type'] = $contentType;
        $this->headers['Access-Control-Allow-Origin'] = Config::get('app')['host'];
        $this->headers['Access-Control-Allow-Credentials'] = 'true';
        $this->headers['Access-Control-Allow-Headers'] = 'Content-type, Authorization';
    }

    /**
     * @param int|null $statusCode
     * @param \Throwable|null $exception
     * @param string|null $message
     * @return array[]
     * @throws AuthorizationException
     */
    public static function createErrorBody(?\Throwable $exception, ?string $message, ?int $statusCode): array
    {
        $user = Auth::getOrNull();

        // Безопасно получаем previous exception
        $previous = null;
        if ($exception && $exception->getPrevious()) {
            $prevException = $exception->getPrevious();
            $previous = [
                'class' => basename(str_replace('\\', '/', get_class($prevException))),
                'message' => $prevException->getMessage(),
                'code' => $prevException->getCode(),
                'file' => $prevException->getFile(),
                'line' => $prevException->getLine(),
            ];
        }

        // Безопасно обрабатываем trace
        $trace = [];
        if ($exception) {
            $rawTrace = array_slice($exception->getTrace(), 0, -5);
            foreach ($rawTrace as $traceItem) {
                $cleanTraceItem = [];

                // Только безопасные для JSON поля
                if (isset($traceItem['file'])) {
                    $cleanTraceItem['file'] = basename($traceItem['file']);
                }
                if (isset($traceItem['line'])) {
                    $cleanTraceItem['line'] = $traceItem['line'];
                }
                if (isset($traceItem['function'])) {
                    $cleanTraceItem['function'] = $traceItem['function'];
                }
                if (isset($traceItem['class'])) {
                    $cleanTraceItem['class'] = basename(str_replace('\\', '/', $traceItem['class']));
                }
                if (isset($traceItem['type'])) {
                    $cleanTraceItem['type'] = $traceItem['type'];
                }

                // Безопасно обрабатываем args
                if (isset($traceItem['args']) && is_array($traceItem['args'])) {
                    $cleanTraceItem['args'] = self::sanitizeTraceArgs($traceItem['args']);
                }

                $trace[] = $cleanTraceItem;
            }
        }

        return [
            'error' => [
                'status' => $statusCode ?? ($exception ? $exception->getCode() : 500),
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'user' => $user ? ('id: ' . $user->uuid) : 'anonymous',
                'exception' => $exception ? basename(str_replace('\\', '/', get_class($exception))) : null,
                'message' => $message ?? ($exception ? $exception->getMessage() : 'Unknown error'),
                'file' => $exception ? basename($exception->getFile()) : '',
                'line' => $exception ? $exception->getLine() : 0,
                'previous' => $previous,
                'trace' => $trace,
            ]
        ];
    }

    /**
     * Безопасная очистка аргументов trace для JSON сериализации
     */
    private static function sanitizeTraceArgs(array $args): array
    {
        $sanitized = [];

        foreach ($args as $arg) {
            if (is_object($arg)) {
                // Пытаемся сериализовать объект
                try {
                    // Проверяем разными способами сериализации
                    $serialized = null;

                    // 1. Если объект реализует JsonSerializable
                    if ($arg instanceof \JsonSerializable) {
                        $serialized = $arg->jsonSerialize();
                    } // 2. Если у объекта есть метод toArray()
                    elseif (method_exists($arg, 'toArray')) {
                        $serialized = $arg->toArray();
                    } // 3. Пытаемся получить публичные свойства
                    else {
                        $reflection = new \ReflectionClass($arg);
                        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

                        if (!empty($properties)) {
                            $serialized = [];
                            foreach ($properties as $property) {
                                try {
                                    $serialized[$property->getName()] = $property->getValue($arg);
                                } catch ( \Throwable $e) {
                                    $serialized[$property->getName()] = "";
                                }
                            }
                        } // 4. Если публичных свойств нет, пытаемся привести к массиву
                        else {
                            $serialized = (array)$arg;
                            // Убираем приватные/защищенные свойства (они начинаются с \0)
                            $serialized = array_filter($serialized, function ($key) {
                                return strpos((string)$key, "\0") === false;
                            }, ARRAY_FILTER_USE_KEY);
                        }
                    }

                    // Проверяем, что результат можно закодировать в JSON
                    if ($serialized !== null) {
                        json_encode($serialized, JSON_THROW_ON_ERROR);
                        $sanitized[] = $serialized;
                    } else {
                        throw new \Exception('Cannot serialize object');
                    }
                } catch (\Exception $e) {
                    // Если не удалось сериализовать, показываем информацию о классе
                    $sanitized[] = [
                        'type' => 'object',
                        'class' => basename(str_replace('\\', '/', get_class($arg))),
                        'serialization_error' => $e->getMessage()
                    ];
                }
            } elseif (is_resource($arg)) {
                $sanitized[] = [
                    'type' => 'resource',
                    'resource_type' => get_resource_type($arg)
                ];
            } elseif (is_callable($arg)) {
                $sanitized[] = [
                    'type' => 'callable'
                ];
            } elseif (is_array($arg)) {
                // Рекурсивно обрабатываем массивы, но ограничиваем глубину
                if (count($arg) > 20) {
                    $sanitized[] = ['type' => 'array', 'count' => count($arg), 'note' => 'truncated'];
                } else {
                    $sanitized[] = self::sanitizeTraceArgs($arg);
                }
            } else {
                // Примитивные типы - строки, числа, bool, null
                $sanitized[] = $arg;
            }
        }

        return $sanitized;
    }

    /**
     * @param array $headers
     * @return Response
     */
    public function setHeaders(array $headers): Response
    {
        $this->headers = $headers;
        return $this;
    }

    public static function html(string $content): self
    {
        return new self(200, body: $content);
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function addHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    private function getBody(): string
    {
        return $this->body;
    }

    public function setContentType(string $contentType): self
    {
        $this->headers['Content-Type'] = $contentType;
        return $this;
    }

    /**
     * Устанавливает JSON-ответ
     *
     * @param mixed $data
     * @param int $flags Флаги для json_encode
     * @return self
     * @throws BaseException
     */
    private function createJson(mixed $data, int $flags = 0): self
    {
        $this->setContentType('application/json');
        try {
            $this->body = json_encode($data, $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            throw new BaseException($e->getMessage());
        }
        return $this;
    }

    public function send(): void
    {
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        http_response_code($this->status);

        echo $this->body;
    }

    /**
     * Создает стандартный JSON-ответ
     *
     * @param mixed $data
     * @param int $status HTTP статус
     * @return self
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self($status);
        return $response->createJson($data);
    }

    /**
     * Возвращает 200 ответ
     * @return self
     */
    public static function ok(): self
    {
        return new self(200);
    }

    /**
     * Возвращает 201 ответ
     * @return self
     */
    public static function created(array|object|string|null $data = null): self
    {
        if (is_null($data)) {
            return new self(201);
        }
        return new self(201)->createJson($data);
    }

    public function setAllowedMethods(string|HttpMethod $method): void
    {
        $allowedMethods = ["GET", "OPTIONS"];
        $httpMethodString = is_string($method) ? $method : $method->value;

        // Добавляем текущий метод запроса, если он не GET или OPTIONS
        if (!in_array($httpMethodString, $allowedMethods)) {
            $allowedMethods[] = $httpMethodString;
        }

        $this->headers['Access-Control-Allow-Methods'] =
            implode(
                ', ',
                $allowedMethods
            );
    }

    public static function error(?\Throwable $exception = null, ?string $message = null, ?int $statusCode = null): self
    {
        $errorBody = self::createErrorBody($exception, $message, $statusCode);
        $error = new self(
            $statusCode ??
            (is_int($exception->getCode()) && $exception->getCode() > 0 ? $exception->getCode() : 500)
        )
            ->createJson($errorBody);
        error_log(
            sprintf(
                "REST error:\n %s\n",
                json_encode(
                    $errorBody,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
                )
            )
        );
//        if (!SPS_DEVELOPMENT_VERSION)
//            new ErrorNotification("New error response", "```json:\n" . json_encode($errorBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS) . "\n```")->send(true);
        return $error;
    }

    public static function raw(mixed $date, ?string $contentType): self
    {
        return new self(200)
            ->setContentType($contentType ?? 'text/html')
            ->setBody($date);
    }
}