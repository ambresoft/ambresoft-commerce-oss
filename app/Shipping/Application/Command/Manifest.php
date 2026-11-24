<?php

namespace App\Shipping\Application\Command;

/** One manifest / pickup list issued at the carrier. */
final readonly class Manifest
{
    public function __construct(public string $number, public string $date, public ?string $issuedAt = null, public ?int $count = null) {}
}
