<?php

namespace App\Customer\Application\Command;

/** A validated postal address. Codes are normalized upper case; optional values are null, never blank. */
final readonly class SaveAddressData
{
    public function __construct(
        public ?string $label,
        public string $firstName,
        public string $lastName,
        public ?string $company,
        public string $line1,
        public ?string $line2,
        public string $city,
        public ?string $postalCode,
        public string $countryCode,
        public ?string $regionCode,
        public ?string $phone,
        // Delivery details: where in the building, the name on the bell and a note for the courier.
        public ?string $floor = null,
        public ?string $bellName = null,
        public ?string $notes = null,
        public ?string $streetNumber = null,
    ) {}

    /** @return array<string, string|null> The browser input shape; used to revalidate DTOs from any caller. */
    public function toArray(): array
    {
        return [
            'label' => $this->label, 'first_name' => $this->firstName, 'last_name' => $this->lastName, 'company' => $this->company,
            'line1' => $this->line1, 'line2' => $this->line2, 'city' => $this->city, 'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode, 'region_code' => $this->regionCode, 'phone' => $this->phone,
            'street_number' => $this->streetNumber, 'floor' => $this->floor, 'bell_name' => $this->bellName, 'notes' => $this->notes,
        ];
    }
}
