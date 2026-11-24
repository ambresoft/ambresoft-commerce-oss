<?php

namespace App\Tax\Domain\Model;

/** A reusable geographic area: whole countries, or only listed regions of a country. The default area matches everywhere. */
final readonly class RateArea
{
    public const REGION = 3;

    public const COUNTRY = 2;

    public const FALLBACK = 1;

    /**
     * @param  list<string>  $countries
     * @param  array<string, list<string>>  $regions  Region restrictions keyed by country; a country without an entry is a member as a whole.
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
        public bool $default,
        public array $countries = [],
        public array $regions = [],
    ) {}

    /** How specifically this area matches the address (REGION > COUNTRY > FALLBACK), or null when it does not apply. */
    public function specificity(?Address $address): ?int
    {
        if (! $this->active) {
            return null;
        }
        if ($this->default) {
            return self::FALLBACK;
        }
        if ($address === null || $address->country === null || ! in_array($address->country, $this->countries, true)) {
            return null;
        }
        $restricted = $this->regions[$address->country] ?? [];
        if ($restricted === []) {
            return self::COUNTRY;
        }

        return $address->region !== null && in_array($address->region, $restricted, true) ? self::REGION : null;
    }
}
