<?php

namespace SpsFW\Core\Auth\Users\Models;

use DateTime;

interface UserAuthI
{
    public function setRefreshToken(string $refreshToken): self;

    public function setTimeLogin(DateTime $timeLogin): self;
}