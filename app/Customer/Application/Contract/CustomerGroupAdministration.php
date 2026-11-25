<?php

namespace App\Customer\Application\Contract;

use App\Customer\Application\Command\SaveCustomerGroupData;

interface CustomerGroupAdministration
{
    public function create(string $actor, SaveCustomerGroupData $data): string;

    public function update(string $actor, string $id, int $version, SaveCustomerGroupData $data): void;

    /**
     * Refused for the default group. A group with members is deleted only when a target group is given: members
     * move there first, so no customer silently loses a membership.
     */
    public function delete(string $actor, string $id, int $version, ?string $reassignTo = null): void;
}
