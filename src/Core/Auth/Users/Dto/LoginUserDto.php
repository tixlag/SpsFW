<?php

namespace SpsFW\Core\Auth\Users\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema('Регистрация пользователя DTO')]
class LoginUserDto
{
    #[OA\Property(
        description: "Логин пользователя",
        required: [true],
        type: "string",
        maxLength: 20,
        minLength: 4,
    )]
    readonly string $login;

    #[OA\Property(
        description: "Пароль пользователя",
        required: [true],
        type: "string",
        maxLength: 20,
        minLength: 6,
    )]
    readonly string $password;

    #[OA\Property(
        description: "Галочка запомнить меня",
        required: [false],
        type: "boolean",
        default: false
    )]
    private(set) bool $remember = false;

//    #[OA\Property(
//        description: "Email пользователя",
//        required: [false],
//        type: "string",
//        format: "email",
//        maxLength: 20,
//        minLength: 4,
//    )]
//    readonly string $email;

}