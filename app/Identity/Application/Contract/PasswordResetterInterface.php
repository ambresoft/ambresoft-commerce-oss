<?php

namespace App\Identity\Application\Contract;

use App\Identity\Application\Command\ResetPasswordData;
use SensitiveParameter;

interface PasswordResetterInterface
{
    public function reset(#[SensitiveParameter] ResetPasswordData $data): bool;
}
