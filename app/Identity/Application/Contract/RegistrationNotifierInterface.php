<?php

namespace App\Identity\Application\Contract;

use App\Identity\Domain\Model\UserId;

interface RegistrationNotifierInterface
{
    public function registered(UserId $id): void;
}
