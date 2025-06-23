<?php

namespace SpsFW\Api\Users\Dto;

use JsonSchema\Exception\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(title: 'DTO для смены пароля')]
class ChangePasswordDto
{
    #[OA\Property(
        property: "old_password",
        required: [true],
        type: "string",
        example: "old_password"
    )]
    private(set) string $old_password {
        set {
            if (strlen($value) < 6) {
                throw new ValidationException('Old password must be at least 6 characters long');
            }
            $this->old_password = $value;
        }
    }
    #[OA\Property(
        property: "new_password",
        required: [true],
        type: "string",
        example: "new_password"
    )]
    private(set) string $new_password {
        set {
            if (strlen($value) < 6) {
                throw new ValidationException('New password must be at least 6 characters long');
            }
            $this->new_password = $value;
        }
    }
    #[OA\Property(
        property: "confirm_password",
        required: [true],
        type: "string",
        example: "confirm_password"
    )]
    private(set) string $confirm_password;

}