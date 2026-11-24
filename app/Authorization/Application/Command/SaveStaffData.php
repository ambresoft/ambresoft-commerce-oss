<?php

namespace App\Authorization\Application\Command;

final readonly class SaveStaffData
{
    /** @param list<string>|null $roleIds */
    public function __construct(
        public string $name,
        public string $email,
        #[\SensitiveParameter] public ?string $password,
        public bool $active,
        public ?array $roleIds,
    ) {}
}
