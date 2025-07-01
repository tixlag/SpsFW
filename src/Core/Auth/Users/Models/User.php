<?php

namespace SpsFW\Core\Auth\Users\Models;

use DateTime;
use JsonSerializable;
use SpsFW\Core\Models\BaseModel;

class User extends BaseModel implements UserAuthI, JsonSerializable
{

    public const string TABLE = 'users';

    private(set) string $id;

    /**
     * @param string $id
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

        readonly string $login,
        private(set) ?string $code_1c,
        readonly string $hashedPassword,
        readonly string $passport,
        readonly string $fio,
        readonly string $birthday,
        readonly ?string $email = null,
        readonly ?string $phone = null,
        private(set) ?string $refreshToken = null,
        private(set) array $accessRules = [],
        private(set) ?DateTime $time_signup = null,
        private(set) ?DateTime $time_login = null
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

    public function addAccessRules(array $accessRules): self
    {
        $this->accessRules += $accessRules;

        return $this;
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
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function setCode1c(string $code_1c): void
    {
        $this->code_1c = $code_1c;
    }


    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'login' => $this->login,
            'code_1c' => $this->code_1c,
            'passport' => $this->passport,
            'fio' => $this->fio,
            'birthday' => $this->birthday,
            'email' => $this->email,
            'phone' => $this->phone,
            'accessRules' => $this->accessRules,
        ];
    }

    public function setTimeLogin(DateTime $timeLogin): UserAuthI
    {
        // TODO: Implement setTimeLogin() method.
    }

    public function setId(string $user_id): self
    {
        $this->id = $user_id;
        return $this;
    }

}