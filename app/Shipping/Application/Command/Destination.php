<?php

namespace App\Shipping\Application\Command;

/** A delivery address as stored on the order (nothing derived or invented). */
final readonly class Destination
{
    public function __construct(
        public string $country,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $name = null,
        public ?string $company = null,
        public ?string $phone = null,
        public ?string $email = null,
    ) {}
}
