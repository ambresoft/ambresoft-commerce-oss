<?php

namespace App\Authorization\Application\Contract;

use App\Authorization\Application\Command\SaveRoleData;
use App\Authorization\Application\Command\SaveStaffData;

interface StaffAdministrationInterface
{
    public function saveUser(string $actorId, ?string $targetId, SaveStaffData $data): string;

    public function deactivateUser(string $actorId, string $targetId): void;

    public function saveRole(string $actorId, ?string $roleId, SaveRoleData $data): string;

    public function deleteRole(string $actorId, string $roleId): void;
}
