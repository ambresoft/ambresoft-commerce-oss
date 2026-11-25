<?php

namespace App\Identity\Application\Command;

use App\Identity\Application\Contract\RegistrationNotifierInterface;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use SensitiveParameter;

final class RegisterUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RegistrationNotifierInterface $notifications,
    ) {}

    public function handle(#[SensitiveParameter] RegisterUserData $data): User
    {
        $user = $this->users->register($data->name, $data->email, $data->password);
        $this->notifications->registered($user->id);

        return $user;
    }
}
