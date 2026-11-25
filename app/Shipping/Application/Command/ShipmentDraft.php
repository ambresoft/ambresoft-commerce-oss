<?php

namespace App\Shipping\Application\Command;

/**
 * The order snapshot a carrier shipment is created from. Amounts are decimal strings in the order currency;
 * `outstanding` is what is still to be collected (total minus captured payments). Weights are kilograms.
 */
final readonly class ShipmentDraft
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public Destination $recipient,
        public ?string $itemsWeightKg,
        public int $itemCount,
        public string $total,
        public string $outstanding,
        public string $currency,
        public ?string $paymentMethodCode,
        public ?string $customerNote,
        public string $storeName,
        public string $locale,
    ) {}
}
