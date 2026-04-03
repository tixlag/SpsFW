<?php

namespace SpsNext\Workers\Errors;

use SpsFW\Core\Auth\Instances\UserAbstract;
use SpsFW\Core\Queue\Attributes\QueueJob;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsNext\Users\Dto\UserShortDto;

#[QueueJob('errors-worker')]
class ErrorJob implements JobInterface
{
    private const MAX_TRACE_LENGTH = 3000;
    private const MAX_CONTEXT_LENGTH = 2000;

    public function __construct(
        public readonly ?UserAbstract  $user,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly string $trace,
        public readonly string $type = 'error',
        public readonly ?string $additionalContext = null,
        public readonly ?int $code = null,
        public ?string $timestamp = null,
        public readonly ?string $requestUri = null
    ) {
        $this->timestamp = $timestamp ?? date('Y-m-d H:i:s');
    }

    public function getName(): string
    {
        return 'errors-worker';
    }

    public function serialize(): string
    {
        return json_encode([
            'user' => isset($this->user) ? [
                'uuid' => $this->user->uuid,
            ] : null,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->truncate($this->trace, self::MAX_TRACE_LENGTH),
            'type' => $this->type,
            'additionalContext' => $this->truncate($this->additionalContext, self::MAX_CONTEXT_LENGTH),
            'code' => $this->code,
            'timestamp' => $this->timestamp,
            'requestUri' => $this->requestUri,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function truncate(?string $text, int $maxLength): ?string
    {
        if ($text === null) {
            return null;
        }
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength) . '... [truncated]';
    }

    public static function deserialize(string $payload): static
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $userData = is_array($data['user'] ?? null) ? $data['user'] : null;
        $user = null;
        if ($userData !== null) {
            $user = new UserShortDto(
                $userData['uuid'] ?? null
            );
        }

        $additionalContext = null;
        if (array_key_exists('additionalContext', $data) && $data['additionalContext'] !== null) {
            $rawAdditionalContext = $data['additionalContext'];
            $additionalContext = is_string($rawAdditionalContext)
                ? $rawAdditionalContext
                : json_encode($rawAdditionalContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return new self(
            user: $user,
            message: (string)($data['message'] ?? ''),
            file: (string)($data['file'] ?? ''),
            line: (int)($data['line'] ?? 0),
            trace: (string)($data['trace'] ?? ''),
            type: (string)($data['type'] ?? 'error'),
            additionalContext: $additionalContext,
            code: isset($data['code']) ? (int)$data['code'] : null,
            timestamp: isset($data['timestamp']) ? (string)$data['timestamp'] : null,
            requestUri: isset($data['requestUri']) ? (string)$data['requestUri'] : null,
        );
    }
}
