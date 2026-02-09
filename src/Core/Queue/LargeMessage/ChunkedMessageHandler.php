<?php

namespace SpsFW\Core\Queue\LargeMessage;

use RuntimeException;

/**
 * Handles large messages by splitting them into chunks and reassembling on the consumer side.
 *
 * Features:
 * - Configurable chunk size (default 8MB to stay safely under RabbitMQ limits)
 * - Optional GZIP compression
 * - MD5 checksum validation
 * - Thread-safe chunk assembly
 */
class ChunkedMessageHandler implements LargeMessageHandlerInterface
{
    private const DEFAULT_CHUNK_SIZE = 8 * 1024 * 1024; // 8MB
    private const DEFAULT_CHECKSUM_ALGO = 'md5';

    private int $chunkSize;
    private bool $enableCompression;
    private string $checksumAlgo;
    private array $assemblyBuffers = [];

    public function __construct(
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        bool $enableCompression = true,
        string $checksumAlgo = self::DEFAULT_CHECKSUM_ALGO
    ) {
        $this->chunkSize = $chunkSize;
        $this->enableCompression = $enableCompression;
        $this->checksumAlgo = $checksumAlgo;
    }

    public function needsChunking(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        $jsonSize = strlen(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $jsonSize > $this->chunkSize;
    }

    public function splitIntoChunks(array $payload): array
    {
        $jobName = $payload['jobName'] ?? 'unknown';
        $originalPayload = $payload['payload'] ?? $payload;
        $meta = $payload['meta'] ?? [];

        // Serialize and optionally compress
        $serialized = is_string($originalPayload)
            ? $originalPayload
            : json_encode($originalPayload, JSON_UNESCAPED_UNICODE);

        if ($this->enableCompression) {
            $serialized = gzcompress($serialized, 6);
            $meta['compressed'] = true;
        }

        $originalSize = strlen($serialized);
        $meta['originalSize'] = $originalSize;
        $meta['checksum'] = hash($this->checksumAlgo, $serialized);

        // Generate unique message ID
        $messageId = $meta['messageId'] ?? $this->generateMessageId();

        // Split into chunks
        $chunks = str_split($serialized, $this->chunkSize);
        $totalChunks = count($chunks);
        $result = [];

        foreach ($chunks as $index => $chunk) {
            $chunkPayload = [
                'jobName' => $jobName,
                'payload' => base64_encode($chunk),
                'meta' => array_merge($meta, [
                    'isChunked' => true,
                    'messageId' => $messageId,
                    'chunkIndex' => $index,
                    'totalChunks' => $totalChunks,
                    'isLastChunk' => ($index === $totalChunks - 1),
                ]),
            ];

            $result[] = $chunkPayload;
        }

        return $result;
    }

    public function addChunk(array $chunk): bool
    {
        $meta = $chunk['meta'] ?? [];
        $messageId = $meta['messageId'] ?? null;

        if (!$messageId) {
            throw new RuntimeException('Chunk missing messageId in meta');
        }

        if (!isset($meta['isChunked'], $meta['chunkIndex'], $meta['totalChunks'])) {
            throw new RuntimeException('Chunk missing required chunking metadata');
        }

        // Initialize buffer for this message if needed
        if (!isset($this->assemblyBuffers[$messageId])) {
            $this->assemblyBuffers[$messageId] = [
                'chunks' => [],
                'totalChunks' => $meta['totalChunks'],
                'checksum' => $meta['checksum'] ?? null,
                'originalSize' => $meta['originalSize'] ?? null,
                'compressed' => $meta['compressed'] ?? false,
                'jobName' => $chunk['jobName'] ?? null,
                'payloadMeta' => $chunk['payload'] ?? null,
            ];
        }

        $buffer = &$this->assemblyBuffers[$messageId];

        // Validate consistency
        if ($buffer['totalChunks'] !== $meta['totalChunks']) {
            throw new RuntimeException("Chunk total mismatch for message $messageId");
        }

        // Store chunk data
        $buffer['chunks'][$meta['chunkIndex']] = base64_decode($chunk['payload']);

        // Check if all chunks received
        if (count($buffer['chunks']) === $buffer['totalChunks']) {
            ksort($buffer['chunks']);
            return true;
        }

        return false;
    }

    public function getAssembledPayload(string $messageId): ?array
    {
        if (!isset($this->assemblyBuffers[$messageId])) {
            return null;
        }

        $buffer = $this->assemblyBuffers[$messageId];

        if (count($buffer['chunks']) !== $buffer['totalChunks']) {
            return null;
        }

        // Concatenate all chunks
        $serialized = implode('', $buffer['chunks']);

        // Validate checksum
        $calculatedChecksum = hash($this->checksumAlgo, $serialized);
        if ($buffer['checksum'] !== $calculatedChecksum) {
            throw new RuntimeException("Checksum mismatch for message $messageId");
        }

        // Decompress if needed
        if ($buffer['compressed']) {
            $serialized = gzuncompress($serialized);
        }

        // Reconstruct original payload
        $payload = [
            'jobName' => $buffer['jobName'],
            'payload' => json_decode($serialized, true) ?? $serialized,
            'meta' => [
                'originalSize' => $buffer['originalSize'],
                'messageId' => $messageId,
            ],
        ];

        return $payload;
    }

    public function clearAssembly(string $messageId): void
    {
        unset($this->assemblyBuffers[$messageId]);
    }

    public function getConfig(): array
    {
        return [
            'chunkSize' => $this->chunkSize,
            'enableCompression' => $this->enableCompression,
            'checksumAlgorithm' => $this->checksumAlgo,
        ];
    }

    private function generateMessageId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
