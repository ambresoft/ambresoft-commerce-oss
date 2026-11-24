<?php

namespace App\Identity\Application\Command;

use SensitiveParameter;

final readonly class ResetPasswordData
{
    public function __construct(
        public string $email,
        #[SensitiveParameter] public string $token,
        #[SensitiveParameter] public string $password,
    ) {}
}
