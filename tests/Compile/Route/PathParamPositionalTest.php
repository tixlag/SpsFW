<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * M8a: path parameters bind POSITIONALLY at runtime — Router::executeControllerMethod builds the action
 * args as matchParams ++ dtoParams and invokes with `...$args`, so placeholder[i] lands on the i-th
 * path-receiver parameter regardless of its NAME; the placeholder name is never required to match a PHP
 * parameter name. The compiler now replicates that: OpenAPI parameter.name is always the RAW placeholder,
 * the type is derived from the positionally-corresponding scalar receiver, and a placeholder with no
 * receiver is documented as a required string. None of these surface a diagnostic.
 */

final class PathParamPositionalDto
{
    public string $x;
}

final class PathParamPositionalController
{
    #[Route('/api/pp/code/{code_1c}', [HttpMethod::GET])]
    #[ApiResponse(status: 200, description: 'ok')]
    public function byCode1c(string $code1c): void
    {
    }

    #[Route('/api/pp/ticket/{ticket_uuid}', [HttpMethod::GET])]
    #[ApiResponse(status: 200, description: 'ok')]
    public function byTicketUuid(int $ticketUuid): void
    {
    }

    // No formal path param — runtime reads Request; {id} is documented as a required string, NO warning.
    #[Route('/api/pp/noparam/{id}', [HttpMethod::GET])]
    #[ApiResponse(status: 200, description: 'ok')]
    public function noParam(): void
    {
    }

    // Multiple path params, mixed kebab/underscore, positional order preserved.
    #[Route('/api/pp/multi/{dept-id}/{role_id}/{user_uuid}', [HttpMethod::GET])]
    #[ApiResponse(status: 200, description: 'ok')]
    public function multi(string $deptId, int $roleId, string $userUuid): void
    {
    }

    // Path param + a DTO marker: the #[JsonBody] param is bound AFTER the path values, so {code_1c} still
    // lands on the first (scalar) receiver positionally — the DTO does NOT consume a path slot.
    #[Route('/api/pp/withdto/{code_1c}', [HttpMethod::POST])]
    #[ApiResponse(status: 200, description: 'ok')]
    public function withDto(string $code1c, #[JsonBody] PathParamPositionalDto $dto): void
    {
    }
}

$diag = new CompileDiagnostics();
$ops = (new RouteMetadataCompiler($diag))->compileOperationClasses([PathParamPositionalController::class]);
assert_same(0, $diag->warningCount(), 'positional path-param binding surfaces NO warnings (snake_case↔camelCase, missing formal param, multi-param, DTO-adjacent)');
assert_same(0, $diag->errorCount(), 'no errors');

$byMethod = [];
foreach ($ops as $op) {
    $byMethod[$op->method] = $op;
}

// {code_1c} + $code1c — snake_case placeholder, camelCase param: name is the RAW placeholder, type positional.
$p = $byMethod['byCode1c']->pathParams[0];
assert_same('code_1c', $p->name, 'placeholder name is the RAW {code_1c} (OpenAPI parameter.name)');
assert_true($p->isPath() && $p->required, 'path param, required');
assert_same('string', $p->type, 'type derived from the positionally-matching $code1c (string) despite the name mismatch');

// {ticket_uuid} + $ticketUuid — same positional idea, int receiver.
$p = $byMethod['byTicketUuid']->pathParams[0];
assert_same('ticket_uuid', $p->name, 'placeholder name is the RAW {ticket_uuid}');
assert_same('integer', $p->type, 'type derived from $ticketUuid (int ⇒ integer) despite the name mismatch');

// No formal param — {id} documented as required string (type null ⇒ emitter renders 'string'), NO warning.
$p = $byMethod['noParam']->pathParams[0];
assert_same('id', $p->name, 'placeholder name preserved');
assert_true($p->required, 'required even with no positional receiver');
assert_same(null, $p->type, 'no positional receiver ⇒ type null (emitter renders a required string param)');

// Multiple path params — positional order + raw names preserved (kebab NOT folded, underscore preserved).
$mp = $byMethod['multi']->pathParams;
assert_same(3, count($mp), 'three path params compiled');
assert_same('dept-id', $mp[0]->name, 'first placeholder raw name (kebab kept as-is in the spec)');
assert_same('string', $mp[0]->type, '{dept-id} ⇒ $deptId (string) positionally');
assert_same('role_id', $mp[1]->name, 'second placeholder raw name (underscore preserved)');
assert_same('integer', $mp[1]->type, '{role_id} ⇒ $roleId (int ⇒ integer) positionally');
assert_same('user_uuid', $mp[2]->name, 'third placeholder raw name');
assert_same('string', $mp[2]->type, '{user_uuid} ⇒ $userUuid (string) positionally');

// DTO marker is skipped positionally — {code_1c} maps to the first scalar receiver, not the DTO.
$p = $byMethod['withDto']->pathParams[0];
assert_same('code_1c', $p->name, 'placeholder raw name');
assert_same('string', $p->type, '{code_1c} ⇒ $code1c positionally; the #[JsonBody] DTO is appended after path values');

echo "Path-param positional passed\n";
