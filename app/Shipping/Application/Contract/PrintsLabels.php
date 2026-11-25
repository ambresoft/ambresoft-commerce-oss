<?php

namespace App\Shipping\Application\Contract;

use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\ShipmentReference;
use App\Shipping\Application\Command\ShippingDocument;

/** Returns the printable label of a shipment as a document produced by the carrier. */
interface PrintsLabels
{
    /** @return array<string, string> Format => label translation key (e.g. laser, thermal) */
    public function labelFormats(): array;

    /** @param array<string, string> $settings */
    public function label(ShipmentReference $shipment, CarrierAccount $account, array $settings, string $format): ShippingDocument;
}
