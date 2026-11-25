<?php

namespace App\Customer\Application\Contract;

use App\Customer\Application\Command\SaveAddressData;
use App\Customer\Application\Command\SaveCustomerData;

/**
 * Customer profiles and their addresses form one consistency unit: every change, including address changes,
 * runs in one transaction under the customer's optimistic version and advances it.
 */
interface CustomerAdministration
{
    public function create(string $actor, SaveCustomerData $data): string;

    public function update(string $actor, string $id, int $version, SaveCustomerData $data): void;

    /** Active or disabled. Disabling never deletes the record or touches staff accounts. */
    public function changeStatus(string $actor, string $id, int $version, string $status): void;

    public function addAddress(string $actor, string $customerId, int $version, SaveAddressData $data): string;

    public function updateAddress(string $actor, string $customerId, string $addressId, int $version, SaveAddressData $data): void;

    /** A removed default falls back to the customer's oldest remaining address, or none. */
    public function deleteAddress(string $actor, string $customerId, string $addressId, int $version): void;
}
