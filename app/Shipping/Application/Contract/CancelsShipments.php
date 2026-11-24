<?php

namespace App\Shipping\Application\Contract;

use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\ShipmentReference;

/** Cancels (deletes) a carrier shipment that the carrier still allows to be removed. */
interface CancelsShipments
{
    /** @param array<string, string> $settings */
    public function cancelShipment(ShipmentReference $shipment, CarrierAccount $account, array $settings): void;
}
