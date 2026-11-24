<?php

namespace App\Tax\Domain\Model;

/** A customer address reduced to what tax matching uses: ISO country and an optional region code. */
final readonly class Address
{
    public ?string $country;

    public ?string $region;

    public function __construct(?string $country, ?string $region = null)
    {
        $country = strtoupper(trim((string) $country));
        $region = strtoupper(trim((string) $region));
        $this->country = $country === '' ? null : $country;
        // A region only has meaning inside a country.
        $this->region = $this->country === null || $region === '' ? null : $region;
    }

    public function known(): bool
    {
        return $this->country !== null;
    }
}
