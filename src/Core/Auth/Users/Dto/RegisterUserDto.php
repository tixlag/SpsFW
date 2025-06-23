<?php

namespace SpsFW\Core\Auth\Users\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema('Регистрация пользователя DTO')]
class RegisterUserDto
{

    #[OA\Property(
        property: "login",
        description: "Логин пользователя",
        required: [true],
        type: "string",
        maxLength: 20,
        minLength: 4,
    )]
    readonly string $login;

    #[OA\Property(
        property: "password",
        description: "Пароль пользователя",
        required: [true],
        type: "string",
        maxLength: 20,
        minLength: 6,
    )]
    readonly string $password;

    #[OA\Property(
        property: "fio",
        description: "ФИО пользователя",
        required: [true],
        type: "string",
        maxLength: 35,
        minLength: 8,
    )]
    readonly string $fio;

    #[OA\Property(
        property: "passport_number",
        description: "Номер паспорта",
        required: [true],
        type: "string",
        maxLength: 20,
        minLength: 5,
    )]
    readonly string $passportNumber;



    #[OA\Property(
        property: "birthday",
        required: [true],
        type: "string",
        format: "date",
        example: "2025-06-05"
    )]
    private(set) string $birthday;


    #[OA\Property(
        property: "email",
        description: "Email пользователя",
        required: [false],
        type: "string",
        format: "email",
        maxLength: 20,
        minLength: 4,
    )]
    readonly string $email;

    #[OA\Property(
        property: "phone",
        description: "Номер телефона",
        required: [false],
        type: "string",
        maxLength: 20,
        minLength: 5,
    )]
    readonly string $phone;

}