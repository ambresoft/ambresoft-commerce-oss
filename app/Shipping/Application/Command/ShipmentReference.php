<?php

namespace App\Shipping\Application\Command;

/** A stored shipment as the provider sees it in follow-up operations. */
final readonly class ShipmentReference
{
    /**
     * @param  list<string>  $extraNumbers
     * @param  array<string, mixed>  $details
     */
    public function __construct(public string $trackingNumber, public array $extraNumbers = [], public ?string $pickupDate = null, public array $details = []) {}
}
