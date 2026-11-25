<?php

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Model\UserId;

final class UserMapper
{
    public function toDomain(UserRecord $record): User
    {
        return new User(
            id: new UserId((string) $record->getKey()),
            name: $record->name,
            email: $record->email,
            emailVerified: $record->hasVerifiedEmail(),
        );
    }
}
