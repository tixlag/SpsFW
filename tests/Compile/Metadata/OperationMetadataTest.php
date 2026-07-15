<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Metadata\OperationMetadata;
use SpsFW\Core\Compile\Metadata\ParameterMetadata;
use SpsFW\Core\Compile\Metadata\RequestBodyMetadata;
use SpsFW\Core\Compile\Metadata\ResponseMetadata;
use SpsFW\Core\Compile\Metadata\SchemaMetadata;
use SpsFW\Core\Compile\Metadata\SecurityMetadata;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 1 (M1): pins OperationMetadata — the OpenAPI documentation projection (distinct from the runtime
 * RouteRuntimeMetadata). Asserts nested VO assembly (request body, responses, security, params) and the
 * operationId-presence helper.
 */
$operation = new OperationMetadata(
    httpMethod: 'POST',
    path: '/api/auth/login',
    operationId: 'loginUser',
    requestBody: new RequestBodyMetadata(
        schema: new SchemaMetadata(className: 'App\\LoginUserDto', name: 'LoginUserDto'),
        contentType: 'application/json',
        required: true,
    ),
    responses: [
        new ResponseMetadata(200, new SchemaMetadata(className: 'App\\UserAbstract', name: 'UserAbstract')),
        new ResponseMetadata(401, description: 'Unauthorized'),
    ],
    security: new SecurityMetadata('bearerAuth', ['any' => [], 'all' => []]),
    tags: ['auth'],
);

assert_true($operation->hasOperationId(), 'hasOperationId true when id set');
assert_same('loginUser', $operation->operationId, 'operationId preserved');
assert_true($operation->requestBody instanceof RequestBodyMetadata, 'request body carried');
assert_same('application/json', $operation->requestBody->contentType, 'request body content type preserved');
assert_true(!$operation->requestBody->isMultipart(), 'json body is not multipart');
assert_same(2, count($operation->responses), 'responses count preserved');
assert_true($operation->responses[0]->isSuccess(), '200 response is a success response');
assert_true(!$operation->responses[1]->isSuccess(), '401 response is not a success response');
assert_true($operation->security instanceof SecurityMetadata, 'security carried');
assert_true(!$operation->security->isAnonymous(), 'bearerAuth security is not anonymous');
assert_true(!$operation->security->hasRules(), 'empty capability rules report no rules');

// omitted operationId
$noId = new OperationMetadata('GET', '/api/me');
assert_true(!$noId->hasOperationId(), 'hasOperationId false when id omitted');
assert_same(null, $noId->requestBody, 'no request body by default');

// parameter helpers (path vs query)
$pathParam = new ParameterMetadata('id', ParameterMetadata::IN_PATH, required: true, type: 'integer');
$queryParam = new ParameterMetadata('q', ParameterMetadata::IN_QUERY, required: false, type: 'string');
assert_true($pathParam->isPath() && !$pathParam->isQuery(), 'path param classified correctly');
assert_true($queryParam->isQuery() && !$queryParam->isPath(), 'query param classified correctly');

echo "OperationMetadata passed\n";
