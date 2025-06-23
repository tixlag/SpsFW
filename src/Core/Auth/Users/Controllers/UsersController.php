<?php

namespace SpsFW\Core\Auth\Users\Controllers;

use SpsFW\Core\Auth\Users\Dto\LoginUserDto;
use SpsFW\Core\Auth\Users\Dto\RegisterUserDto;
use SpsFW\Core\Auth\Users\UsersService;
use SpsFW\Core\Auth\Users\UsersServiceI;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;

class UsersController extends RestController
{

    // Такой констурктор нужен, чтобы мы могли мокать наши сервисы
    public function __construct(?UsersServiceI $usersService = null)
    {
        if ($usersService) {
            $this->usersService = $usersService;
        } else {
            $this->usersService = new UsersService();
        }
        parent::__construct();
    }


    #[Route('/api/users/register', ["POST"])]
    #[ValidateDto(RegisterUserDto::class)]
    public function register(RegisterUserDto $dto)
    {
        $this->usersService->register($dto->login, $dto->password, $dto->passportNumber, $dto->fio, $dto->birthday, $dto->email, $dto->phone);
    }

    #[Route('/api/users/login', ["POST"])]
    #[ValidateDto(LoginUserDto::class)]
    public function login(LoginUserDto $dto)
    {
        $user = $this->usersService->login($dto->login, $dto->password, $dto->remember);
        return $user;

    }

}