<?php

namespace App\Order\Application\Event;

/**
 * A shopper placed an order at checkout (announced once, after it committed). Amounts are decimal strings in the order's
 * currency as the shopper was charged; it carries no personal data, so add-ons such as analytics may listen safely.
 */
final readonly class OrderPlaced
{
    /**
     * @param  list<array{product_id: ?string, sku: ?string, name: string, variation: ?string, quantity: int, total: string}>  $items  Line totals as charged
     */
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $currency,
        public int $decimals,
        public string $total,
        public string $tax,
        public string $shipping,
        public ?string $coupon,
        public array $items,
    ) {}
}
