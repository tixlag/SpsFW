<?php

namespace SpsFW\Api\Users;

use OpenApi\Attributes as OA;
use Sps\ApplicationError;
use Sps\Auth;
use Sps\HttpError401Exception;
use SpsFW\Api\Users\Dto\ChangePasswordDto;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;
use SpsFW\Core\Validation\Enums\ParamsIn;

#[Controller]
class LoginController extends RestController
{
    private UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
        parent::__construct();
    }

    /**
     * @throws HttpError401Exception
     * @throws ApplicationError
     */
    #[OA\Patch(
        path: '/api/login/password/change',
        description: 'Минимум 6 символов',
        summary: 'Change password',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                ref: ChangePasswordDto::class
            )
        ),
        tags: ['User'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Success',
            )
        ]
    )]
    #[Route('/api/login/password/change', httpMethods: ['PATCH'])]
    public function changePassword(): Response
    {
        $dto = $this->validator->validateLegacy(ParamsIn::Json, new ChangePasswordDto());
        
        if ($dto->new_password != $dto->confirm_password) {
            throw new ValidationException('New password and confirm password do not match');
        }
        $this->userService->changePassword(Auth::get(), $dto);

        return Response::ok();
    }


}