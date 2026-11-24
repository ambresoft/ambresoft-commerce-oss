<?php

namespace App\Shipping\Domain\Service;

/**
 * Decides whether a shipment event may change the order status. A configured target applies once per shipment and
 * event, never when the order is already in it, and only while the order is still in the status the shipment
 * expects — a status changed by hand in the meantime is never overwritten.
 */
final class ShipmentTransitions
{
    /** @param list<string> $appliedEvents */
    public static function target(string $event, ?string $targetStatusId, string $currentStatusId, ?string $expectedStatusId, array $appliedEvents): ?string
    {
        if ($targetStatusId === null || $targetStatusId === '' || in_array($event, $appliedEvents, true) || $targetStatusId === $currentStatusId) {
            return null;
        }
        if ($expectedStatusId !== null && $expectedStatusId !== $currentStatusId) {
            return null;
        }

        return $targetStatusId;
    }
}
