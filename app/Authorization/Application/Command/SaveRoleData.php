<?php

namespace App\Authorization\Application\Command;

final readonly class SaveRoleData
{
    /** @param list<string> $permissions */
    public function __construct(public string $name, public string $slug, public ?string $description, public array $permissions) {}
}
