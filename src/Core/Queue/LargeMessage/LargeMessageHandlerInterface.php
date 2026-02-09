<?php

namespace SpsFW\Core\Queue\LargeMessage;

/**
 * Interface for handling large messages that exceed RabbitMQ size limits.
 *
 * Implementations should:
 * - Split large payloads into smaller chunks for publishing
 * - Reassemble chunks on consumption
 * - Optionally compress/decompress data
 * - Provide checksum validation
 */
interface LargeMessageHandlerInterface
{
    /**
     * Check if payload exceeds the size threshold and needs chunking.
     *
     * @param mixed $payload The payload to check
     * @return bool True if payload needs chunking
     */
    public function needsChunking(mixed $payload): bool;

    /**
     * Split a large payload into chunks for publishing.
     *
     * @param array $payload The full payload containing jobName, payload, meta
     * @return array Array of chunk payloads with metadata
     */
    public function splitIntoChunks(array $payload): array;

    /**
     * Add a chunk to the assembly buffer.
     *
     * @param array $chunk The chunk data
     * @return bool True if assembly is complete, false if more chunks expected
     */
    public function addChunk(array $chunk): bool;

    /**
     * Get the reassembled payload when all chunks are received.
     *
     * @param string $messageId The message ID to assemble
     * @return array|null The reassembled payload or null if not complete
     */
    public function getAssembledPayload(string $messageId): ?array;

    /**
     * Clear assembly buffer for a specific message ID.
     *
     * @param string $messageId The message ID to clear
     */
    public function clearAssembly(string $messageId): void;

    /**
     * Get configuration for chunking.
     *
     * @return array Configuration with keys: chunkSize, enableCompression, checksumAlgorithm
     */
    public function getConfig(): array;
}
