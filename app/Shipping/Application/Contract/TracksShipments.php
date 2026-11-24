<?php

namespace App\Shipping\Application\Contract;

use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\ShipmentReference;
use App\Shipping\Application\Command\TrackingResult;

/** Reads the latest state (and checkpoints) of a shipment from the carrier. */
interface TracksShipments
{
    /** @param array<string, string> $settings */
    public function track(ShipmentReference $shipment, CarrierAccount $account, array $settings): TrackingResult;
}
