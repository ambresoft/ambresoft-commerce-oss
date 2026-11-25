<?php

namespace App\Customer\Application\Command;

final readonly class SaveCustomerData
{
    /**
     * @param  list<string>|null  $groupIds  Null on creation means "the default group"; on update it keeps the memberships.
     * @param  ?SaveAddressData  $address  Optional first address on creation; it becomes both defaults.
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public bool $taxExempt,
        public ?string $notes,
        public ?array $groupIds = null,
        public ?string $defaultBillingAddressId = null,
        public ?string $defaultShippingAddressId = null,
        public ?SaveAddressData $address = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'first_name' => $this->firstName, 'last_name' => $this->lastName, 'email' => $this->email, 'phone' => $this->phone,
            'tax_exempt' => $this->taxExempt, 'notes' => $this->notes, 'group_ids' => $this->groupIds,
            'default_billing_address_id' => $this->defaultBillingAddressId, 'default_shipping_address_id' => $this->defaultShippingAddressId,
            'address' => $this->address?->toArray(),
        ], fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['phone', 'notes'], true), ARRAY_FILTER_USE_BOTH);
    }
}
