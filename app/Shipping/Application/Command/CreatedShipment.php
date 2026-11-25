<?php

namespace App\Shipping\Application\Command;

/** A shipment the carrier created: its main tracking number, associated numbers and a safe summary of what was sent. */
final readonly class CreatedShipment
{
    /**
     * @param  list<string>  $extraNumbers
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $trackingNumber,
        public array $extraNumbers = [],
        public ?string $pickupDate = null,
        public ?string $weightKg = null,
        public int $packages = 1,
        public ?string $codAmount = null,
        public array $details = [],
    ) {}
}
