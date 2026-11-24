<?php

namespace App\Authorization\Application\Command;

use App\Authorization\Application\Contract\StaffAdministrationInterface;

final readonly class ManageStaffHandler
{
    public function __construct(private StaffAdministrationInterface $administration) {}

    public function saveUser(string $actorId, ?string $targetId, SaveStaffData $data): string
    {
        return $this->administration->saveUser($actorId, $targetId, $data);
    }

    public function deactivateUser(string $actorId, string $targetId): void
    {
        $this->administration->deactivateUser($actorId, $targetId);
    }

    public function saveRole(string $actorId, ?string $roleId, SaveRoleData $data): string
    {
        return $this->administration->saveRole($actorId, $roleId, $data);
    }

    public function deleteRole(string $actorId, string $roleId): void
    {
        $this->administration->deleteRole($actorId, $roleId);
    }
}
