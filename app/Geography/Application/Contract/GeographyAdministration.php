<?php

namespace App\Geography\Application\Contract;

interface GeographyAdministration
{
    /** Adds or removes a country from the shipping list. Regions are kept either way. */
    public function setShipping(string $actor, string $countryCode, bool $ships): void;

    /**
     * Creates a region of a shipping country. Names are keyed by active language code; the default language is required.
     *
     * @param  array<string, string>  $names
     */
    public function createRegion(string $actor, string $countryCode, string $code, array $names, string $status): string;

    /** @param array<string, string> $names */
    public function updateRegion(string $actor, string $id, int $version, string $code, array $names, string $status): void;

    public function deleteRegion(string $actor, string $id, int $version): void;

    /**
     * @param  list<string>  $ids
     * @return int Regions changed.
     */
    public function setRegionStatus(string $actor, array $ids, string $status): int;
}
