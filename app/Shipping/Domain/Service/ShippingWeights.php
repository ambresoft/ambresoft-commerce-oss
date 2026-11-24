<?php

namespace App\Shipping\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/**
 * Shipment weights from order lines (per-unit weights in the store's weight unit, exact decimals).
 *
 * - physical: every line with a known weight (what the parcel weighs).
 * - chargeable: like physical, but lines of free-shipping products are left out when the method honours free
 *   shipping; they also add no per-product shipping cost (`freight`).
 * Unknown weights (missing, or a permanently deleted product) count as zero; `chargeableKnown` tells whether any
 * charged line had a weight, so carriers can fall back to their default weight instead of guessing.
 */
final class ShippingWeights
{
    /**
     * @param  list<array{weight: ?string, quantity: int, free_shipping: bool, freight: ?string}>  $lines
     * @return array{physical: string, chargeable: string, chargeable_known: bool, chargeable_items: int, freight: string}
     */
    public static function measure(array $lines, bool $freeShippingApplies): array
    {
        $physical = $chargeable = $freight = '0';
        $known = false;
        $items = 0;
        foreach ($lines as $line) {
            $quantity = (string) max(0, $line['quantity']);
            $weight = $line['weight'] !== null && Decimal::isCanonical($line['weight']) && Decimal::compare($line['weight'], '0') > 0 ? $line['weight'] : null;
            if ($weight !== null) {
                $physical = Decimal::add($physical, Decimal::multiply($weight, $quantity));
            }
            if ($freeShippingApplies && $line['free_shipping']) {
                continue;
            }
            $items += max(0, $line['quantity']);
            if ($weight !== null) {
                $chargeable = Decimal::add($chargeable, Decimal::multiply($weight, $quantity));
                $known = true;
            }
            if ($line['freight'] !== null && Decimal::isCanonical($line['freight']) && Decimal::compare($line['freight'], '0') > 0) {
                $freight = Decimal::add($freight, Decimal::multiply($line['freight'], $quantity));
            }
        }

        return ['physical' => Decimal::trim($physical), 'chargeable' => Decimal::trim($chargeable), 'chargeable_known' => $known, 'chargeable_items' => $items, 'freight' => Decimal::trim($freight)];
    }

    /** Converts a weight in the store unit (defined by grams per unit) to kilograms; only done at carrier boundaries. */
    public static function toKilograms(string $weight, string $gramsPerUnit): string
    {
        return Decimal::trim(Decimal::divide(Decimal::multiply($weight, $gramsPerUnit), '1000', 6));
    }

    /** Converts kilograms to the store unit. */
    public static function fromKilograms(string $kilograms, string $gramsPerUnit): string
    {
        return Decimal::trim(Decimal::divide(Decimal::multiply($kilograms, '1000'), $gramsPerUnit, 6));
    }
}
