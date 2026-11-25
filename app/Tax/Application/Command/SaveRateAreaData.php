<?php

namespace App\Tax\Application\Command;

final readonly class SaveRateAreaData
{
    /**
     * @param  array<string, string>  $names  Locale code => name; the default language is required.
     * @param  list<string>  $countries  ISO 3166-1 alpha-2 codes; empty for the default area.
     * @param  array<string, list<string>>  $regions  Optional region restrictions keyed by country.
     */
    public function __construct(
        public array $names,
        public string $status,
        public bool $default,
        public array $countries = [],
        public array $regions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['names' => $this->names, 'status' => $this->status, 'is_default' => $this->default, 'countries' => $this->countries, 'regions' => $this->regions];
    }
}
