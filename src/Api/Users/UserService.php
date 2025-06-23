<?php

namespace SpsFW\Api\Users;

use Sps\ApplicationError;
use Sps\Auth;
use Sps\HttpError401Exception;
use Sps\Users\User;
use Sps\Users\UserStore;
use SpsFW\Api\Users\Dto\ChangePasswordDto;
use SpsFW\Core\Exceptions\ValidationException;

class UserService
{
    private UserStorage $userStorage;

    public function __construct()
    {
        $this->userStorage = new UserStorage();
    }


    public function changePassword(User $user, ChangePasswordDto $dto): void
    {
        if (password_verify($dto->old_password, $user->getPassword())) {
            throw new ValidationException('Old password is incorrect');
        }
        $this->userStorage->changePassword($user->getId(), $dto->new_password);
    }

}