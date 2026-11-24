<?php

namespace App\Tax\Application\Command;

/** Validated tax definition input. Percentage rate values are percent figures ("24"); fixed values are primary-currency amounts. */
final readonly class SaveTaxData
{
    /**
     * @param  array<string, string>  $names  Locale code => name; the default language is required.
     * @param  list<SaveTaxRateData>  $rates
     */
    public function __construct(
        public array $names,
        public ?string $registrationNumber,
        public int $priority,
        public string $status,
        public bool $priceIncludesTax,
        public string $addressBasis,
        public array $rates = [],
    ) {}

    /** @return array<string, mixed> The browser input shape; used to revalidate DTOs from any caller. */
    public function toArray(): array
    {
        return [
            'names' => $this->names, 'registration_number' => $this->registrationNumber, 'priority' => $this->priority, 'status' => $this->status,
            'price_includes_tax' => $this->priceIncludesTax, 'address_basis' => $this->addressBasis,
            'rates' => array_map(fn (SaveTaxRateData $r): array => ['area_id' => $r->areaId, 'type' => $r->type, 'value' => $r->value], $this->rates),
        ];
    }
}
