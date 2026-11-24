<?php

namespace App\Shipping\Application\Contract;

/**
 * Lets a provider's settings choose the order status applied when a shipment is created, delivered or returned. The
 * CMS applies a status once per shipment and only while the order is still in the status the shipment expects.
 */
interface MapsOrderStatuses
{
    public const SHIPMENT_CREATED = 'shipment_created';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    /**
     * @param  array<string, string>  $settings
     * @return string|null Order status ID, or null to leave the order unchanged
     */
    public function orderStatusFor(string $event, array $settings): ?string;
}
