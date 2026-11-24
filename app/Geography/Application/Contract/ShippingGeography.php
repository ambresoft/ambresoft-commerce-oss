<?php

namespace App\Geography\Application\Contract;

/** Read side for the storefront checkout and shipping rules (not consumed until those exist). */
interface ShippingGeography
{
    /** @return list<string> ISO country codes the store ships to. */
    public function countries(): array;

    public function ships(string $countryCode): bool;

    /**
     * Active regions of a shipping country in the requested (or current) language, sorted by name.
     *
     * @return list<array{code: string, name: string}>
     */
    public function regions(string $countryCode, ?string $locale = null): array;

    /**
     * Active regions of every shipping country, keyed by country code, named and sorted like regions(). One query; used
     * by address forms so a region is chosen from the list rather than typed.
     *
     * @return array<string, list<array{code: string, name: string}>>
     */
    public function regionOptions(?string $locale = null): array;

    /**
     * Display names for stored country/region pairs, whatever the region's status; unknown codes are omitted.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, string> keyed "COUNTRY:CODE"
     */
    public function regionNames(array $pairs, ?string $locale = null): array;
}
