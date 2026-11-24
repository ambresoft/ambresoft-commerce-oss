<?php

namespace App\Shipping\Application\Contract;

use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\Manifest;
use App\Shipping\Application\Command\ManifestResult;
use App\Shipping\Application\Command\ShippingDocument;

/**
 * Carrier pickup lists / manifests that finalize the day's shipments (e.g. a courier's daily pickup list). Issuing is not
 * idempotent at the carrier; the CMS serializes and de-duplicates requests.
 */
interface ManifestsShipments
{
    /** Translation key of the carrier's name for a manifest (e.g. "Pickup lists"). */
    public function manifestLabel(): string;

    /** @param array<string, string> $settings */
    public function issueManifest(string $date, CarrierAccount $account, array $settings): ManifestResult;

    /**
     * @param  array<string, string>  $settings
     * @return list<Manifest>
     */
    public function manifests(string $date, CarrierAccount $account, array $settings): array;

    /**
     * Tracking numbers listed on a manifest.
     *
     * @param  array<string, string>  $settings
     * @return list<string>
     */
    public function manifestContents(string $number, string $date, CarrierAccount $account, array $settings): array;

    /** @param array<string, string> $settings */
    public function printManifest(string $number, string $date, CarrierAccount $account, array $settings): ShippingDocument;
}
