<?php

namespace App\Identity\Domain\Model;

final readonly class User
{
    public function __construct(
        public UserId $id,
        public string $name,
        public string $email,
        public bool $emailVerified,
    ) {}
}
