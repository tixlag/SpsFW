<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use SpsFW\Core\Compile\Metadata\OperationMetadata;

/**
 * Centralizes the standard error responses every operation may produce (plan §2.5/§7 — no invented statuses).
 *
 * The status set mirrors the runtime exception→code contract exactly:
 *  - 400 Bad Request        : ValidationException — possible whenever a validated DTO is bound (request body
 *                              or query params carry constraints the Validator enforces).
 *  - 401 Unauthorized       : AuthorizationException (default) — every authenticated action (not NoAuthAccess).
 *  - 403 Forbidden          : AuthorizationException (forbidden) / ForbiddenException — when #[AccessRulesAny/All]
 *                              gate the action.
 *  - 429 Too Many Requests  : TooManyRequestsException — when #[RateLimit] is applied.
 *  - 500 Internal Server Err: BaseException / any uncaught Throwable — globally, every operation.
 *
 * 422 is intentionally NOT emitted (the runtime has no 422 path — plan §2.5).
 *
 * The error body is the non-debug shape of {@see \SpsFW\Core\Http\Response::createErrorBody()}: a stable
 * `{error: {status, uri, user, exception, message, file, line, previous, trace}}` envelope. Debug-only fields
 * are described as nullable/empty so the schema matches what production responses actually carry.
 *
 * The policy is a pure function of {@see OperationMetadata}: it yields the {status ⇒ description} map; the
 * OpenApiEmitter materializes each into a response object that $refs the shared `Error` component, merging it
 * with the operation's declared #[Response] entries (a declared status wins — never duplicated).
 */
final class StandardErrorPolicy
{
    public const ERROR_SCHEMA_NAME = 'Error';

    private const DESC_400 = 'Validation failed';
    private const DESC_401 = 'Unauthorized';
    private const DESC_403 = 'Forbidden';
    private const DESC_429 = 'Too Many Requests';
    private const DESC_500 = 'Internal Server Error';

    /**
     * The canonical Error component schema (the non-debug createErrorBody envelope).
     *
     * @return array<string, mixed>
     */
    public function errorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer', 'description' => 'HTTP status code'],
                        'uri' => ['type' => 'string', 'description' => 'Request URI'],
                        'user' => ['type' => 'string', 'description' => 'Authenticated user ("id: <uuid>") or "anonymous"'],
                        'exception' => ['type' => 'string', 'nullable' => true, 'description' => 'Exception class (debug only; null in production)'],
                        'message' => ['type' => 'string', 'description' => 'Human-readable error message'],
                        'file' => ['type' => 'string', 'description' => 'Source file basename (debug only; empty in production)'],
                        'line' => ['type' => 'integer', 'description' => 'Source line (debug only; 0 in production)'],
                        'previous' => ['nullable' => true, 'description' => 'Previous exception chain (debug only; null in production)'],
                        'trace' => [
                            'type' => 'array',
                            'description' => 'Sanitized stack trace (debug only; empty in production)',
                            'items' => ['type' => 'object'],
                        ],
                    ],
                    'required' => ['status', 'uri', 'user', 'message'],
                ],
            ],
            'required' => ['error'],
        ];
    }

    /**
     * The standard error {status ⇒ description} map for an operation, in status order. The emitter excludes any
     * status the operation already declares via #[Response].
     *
     * @return array<int, string> status ⇒ description
     */
    public function responsesFor(OperationMetadata $operation): array
    {
        $responses = [];

        // 400: a validated input (body or query DTO) can fail validation.
        if ($operation->requestBody !== null || $operation->queryParams !== []) {
            $responses[400] = self::DESC_400;
        }

        $security = $operation->security;
        $anonymous = $security !== null && $security->isAnonymous();

        // 401: any authenticated action (not NoAuthAccess).
        if (!$anonymous) {
            $responses[401] = self::DESC_401;
        }

        // 403: capability-gated actions (#[AccessRulesAny/All]).
        if ($security !== null && $security->hasRules()) {
            $responses[403] = self::DESC_403;
        }

        // 429: rate-limited actions.
        if ($operation->rateLimited) {
            $responses[429] = self::DESC_429;
        }

        // 500: any uncaught Throwable — every operation, always.
        $responses[500] = self::DESC_500;

        ksort($responses);
        return $responses;
    }
}
