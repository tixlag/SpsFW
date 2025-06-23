<?php

namespace SpsFW\Core\Auth\Users\Models;

use JsonSerializable;
use SpsFW\Core\Models\BaseModel;

class User extends BaseModel implements JsonSerializable
{

    public const string TABLE = 'users';

    /**
     * @param string $login
     * @param string $hashedPassword
     * @param string $passport
     * @param string $fio
     * @param string $birthday
     * @param string|null $email
     * @param string|null $phone
     * @param array $accessRules
     * @param array $accessRulesValue
     */
    public function __construct(
        private(set) string $id,
        readonly string $login,
        readonly string $hashedPassword,
        readonly string $passport,
        readonly string $fio,
        readonly string $birthday,
        readonly ?string $email = null,
        readonly ?string $phone = null,
        private ?string $refresh_token = null,
        private(set) array $accessRules = [],
    ) {
    }

    /**
     * Возвращает значение правила, если установлено
     * @return array
     */
    public function getAccessRuleValue(int $accessRuleId): mixed
    {
        return $this->accessRules[$accessRuleId] ?? null;
    }

    /**
     * Устанавливает значение правила
     * @param int $accessRuleId
     * @param mixed $value
     */
    public function setAccessRuleValue(int $accessRuleId, mixed $value): void
    {
        $this->accessRules[$accessRuleId] = $value;
    }

    /**
     * Устанавливает значение правила
     * @param int $accessRuleId
     * @param mixed $value
     */
    public function setAccessRules(array $accessRules): void
    {
        $this->accessRules = $accessRules;
    }

    public function setRefreshToken(string $refreshToken): self
    {
        $this->refresh_token = $refreshToken;
        return $this;
    }


    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'login' => $this->login,
            'passport' => $this->passport,
            'fio' => $this->fio,
            'birthday' => $this->birthday,
            'email' => $this->email,
            'phone' => $this->phone,
            'accessRules' => $this->accessRules,
        ];
    }

}