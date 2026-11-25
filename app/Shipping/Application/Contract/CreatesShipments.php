<?php

namespace App\Shipping\Application\Contract;

use App\Shared\Application\Command\SettingField;
use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\CreatedShipment;
use App\Shipping\Application\Command\ShipmentDraft;

/**
 * Creates a shipment (voucher / label record) at the carrier. Staff review the provider's form, prefilled from the
 * order snapshot, before anything is sent. Creating a shipment is not idempotent at most carriers: the CMS claims the
 * request first and never repeats a call whose outcome is unknown.
 */
interface CreatesShipments
{
    /** @return list<SettingField> Fields of the shipment form (recipient corrections, weight, parcels, date…) */
    public function shipmentFields(): array;

    /**
     * Prefilled values from the order snapshot; nothing is invented when the order lacks a value.
     *
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    public function shipmentDefaults(ShipmentDraft $draft, array $settings): array;

    /**
     * @param  array<string, string>  $input  Validated form values
     * @param  array<string, string>  $settings
     *
     * @throws ShippingProviderRejected for refused data (nothing was created)
     * @throws ShippingProviderUnavailable when the outcome is unknown or the carrier is unreachable
     */
    public function createShipment(ShipmentDraft $draft, array $input, CarrierAccount $account, array $settings): CreatedShipment;
}
