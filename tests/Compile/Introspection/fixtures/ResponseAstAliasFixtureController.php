<?php

declare(strict_types=1);

/**
 * M8b fixture (D5): NameResolver must recognize `SpsFW\Core\Http\Response` through an ALIAS. This file imports
 * the Response class as `ApiResponse`, so `ApiResponse::json(new Dto())` resolves to the real FQCN — proving the
 * analyzer does not rely on the literal short name `Response`.
 */

namespace SpsFW\Tests\Compile\Introspection\Fixtures;

use SpsFW\Core\Http\Response as ApiResponse;

class AliasUserDto
{
    public string $id = '';
}

class ResponseAstAliasFixtureController
{
    public function aliased(): ApiResponse
    {
        return ApiResponse::json(new AliasUserDto());
    }
}
