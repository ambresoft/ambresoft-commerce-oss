<?php

namespace App\Identity\Application\Command;

use App\Identity\Application\Contract\PasswordResetterInterface;
use SensitiveParameter;

final class ResetPasswordHandler
{
    public function __construct(private readonly PasswordResetterInterface $passwords) {}

    public function handle(#[SensitiveParameter] ResetPasswordData $data): bool
    {
        return $this->passwords->reset($data);
    }
}
