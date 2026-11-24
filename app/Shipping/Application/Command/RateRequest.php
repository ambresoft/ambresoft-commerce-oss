<?php

namespace App\Shipping\Application\Command;

/**
 * What a carrier needs to quote one shipment, calculated by the CMS. Weights are kilograms (decimal strings),
 * converted from the store's weight unit at this boundary. `itemsWeightKg` is the sum of the item weights that are
 * known (null when no item has a weight); `itemCount` counts the charged items.
 */
final readonly class RateRequest
{
    public function __construct(
        public Destination $destination,
        public ?string $itemsWeightKg,
        public int $itemCount,
        public string $orderValue,
        public string $currency,
        public int $decimals,
        public ?string $paymentMethodCode = null,
    ) {}
}
