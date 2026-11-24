<?php

namespace App\Shipping\Domain\Service;

use App\Shared\Domain\Service\Decimal;
use InvalidArgumentException;

/**
 * The value of a shipment, measured against couriers' maximum shipment value: the goods after their discounts, with
 * their taxes, without shipping or payment fees. It is the sum of the priced order lines' totals (OrderPricing), so it
 * is in the order's currency, which is always the primary currency.
 */
final class ShipmentValue
{
    /** @param list<array<string, mixed>> $lines Priced order lines, each with its `total`. */
    public static function of(array $lines): string
    {
        $value = '0';
        foreach ($lines as $line) {
            $total = $line['total'] ?? null;
            if (! is_string($total) || preg_match('/^-?\d+(\.\d+)?$/D', $total) !== 1) {
                throw new InvalidArgumentException('Every priced line needs a decimal total.');
            }
            $value = Decimal::add($value, $total);
        }

        return $value;
    }

    /** Whether a method with this limit may carry the shipment: no limit, or a value up to and including it. */
    public static function allowed(string $value, bool $enabled, ?string $limit): bool
    {
        return ! $enabled || $limit === null || Decimal::compare($value, $limit) <= 0;
    }
}
