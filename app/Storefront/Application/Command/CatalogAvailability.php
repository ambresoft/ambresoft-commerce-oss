<?php

namespace App\Storefront\Application\Command;

/** Availability as guests see it. Stock counts are not public, so there is no quantity. */
final readonly class CatalogAvailability
{
    public const STATUSES = ['in_stock', 'out_of_stock', 'backorder', 'upcoming'];

    /**
     * @param  string  $status  One of STATUSES
     * @param  string  $label  The merchant's availability text
     * @param  ?string  $availableFrom  Release date (Y-m-d) of an upcoming product
     * @param  bool  $purchasable  Whether the smallest quantity can be ordered now
     */
    public function __construct(
        public string $status,
        public string $label,
        public ?string $availableFrom,
        public bool $purchasable,
    ) {}
}
