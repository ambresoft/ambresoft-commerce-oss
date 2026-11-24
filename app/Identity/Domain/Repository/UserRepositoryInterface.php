<?php

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Exception\EmailAlreadyRegistered;
use App\Identity\Domain\Model\User;
use SensitiveParameter;

interface UserRepositoryInterface
{
    /** @throws EmailAlreadyRegistered */
    public function register(string $name, string $email, #[SensitiveParameter] string $password): User;
}
