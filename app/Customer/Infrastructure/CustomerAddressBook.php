<?php

namespace App\Customer\Infrastructure;

use App\Customer\Application\Command\SaveAddressData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Address-book rules shared by staff administration and customer self-service. Callers own the transaction, the
 * customer lock and the version check.
 */
final class CustomerAddressBook
{
    public function insert(int $customerId, SaveAddressData $data): int
    {
        return DB::table('customer_addresses')->insertGetId([...$this->fields($data), 'customer_id' => $customerId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function update(string $customerId, string $addressId, SaveAddressData $data): void
    {
        DB::table('customer_addresses')->where('id', $addressId)->where('customer_id', $customerId)->update([...$this->fields($data), 'updated_at' => now()]);
    }

    /** A removed default falls back to the customer's oldest remaining address, or none. Orders keep their own snapshots. */
    public function delete(object $customer, string $addressId): void
    {
        $fallback = DB::table('customer_addresses')->where('customer_id', $customer->id)->where('id', '<>', $addressId)->min('id');
        $defaults = [];
        foreach (['default_billing_address_id', 'default_shipping_address_id'] as $field) {
            if ((string) $customer->{$field} === $addressId) {
                $defaults[$field] = $fallback;
            }
        }
        if ($defaults !== []) {
            DB::table('customers')->where('id', $customer->id)->update($defaults);
        }
        DB::table('customer_addresses')->where('id', $addressId)->where('customer_id', $customer->id)->delete();
    }

    /** Both defaults point at the oldest address when unset and at least one address exists. */
    public function ensureDefaults(int $customerId): void
    {
        $first = DB::table('customer_addresses')->where('customer_id', $customerId)->min('id');
        if ($first === null) {
            return;
        }
        DB::table('customers')->where('id', $customerId)->whereNull('default_billing_address_id')->update(['default_billing_address_id' => $first]);
        DB::table('customers')->where('id', $customerId)->whereNull('default_shipping_address_id')->update(['default_shipping_address_id' => $first]);
    }

    /** An address ID from another customer is treated as not found, never as a validation hint. */
    public function owned(string $customerId, string $addressId): object
    {
        return DB::table('customer_addresses')->where('id', $addressId)->where('customer_id', $customerId)->first(['id', 'country_code', 'region_code'])
            ?? throw new ModelNotFoundException('Customer address not found.');
    }

    /** @return array<string, string|null> */
    private function fields(SaveAddressData $data): array
    {
        return [
            'label' => $data->label, 'first_name' => $data->firstName, 'last_name' => $data->lastName, 'company' => $data->company, 'line1' => $data->line1, 'line2' => $data->line2,
            'city' => $data->city, 'postal_code' => $data->postalCode, 'country_code' => $data->countryCode, 'region_code' => $data->regionCode, 'phone' => $data->phone,
            'street_number' => $data->streetNumber, 'floor' => $data->floor, 'bell_name' => $data->bellName, 'notes' => $data->notes,
        ];
    }
}
