<?php

namespace App\Order\Infrastructure;

use App\Shared\Domain\Service\Decimal;

/**
 * The classic invoice view of order lines: the unit price without VAT, the VAT percentage, and the unit price with VAT.
 * Derived from the amounts stored on each line (net = subtotal minus allocated discounts and the tax already inside the price; VAT = the line's
 * tax; gross = the line total). The percentage is snapped to the order's own percentage taxes when it matches one.
 */
final class OrderLineVat
{
    /**
     * @param  object  $item  An order_items row.
     * @param  iterable<object>  $taxes  The order's order_taxes rows.
     * @return array{unit_net: string, unit_gross: string, vat: string, vat_rate: string, net: string, gross: string}
     */
    public function line(object $item, iterable $taxes): array
    {
        $net = Decimal::subtract(Decimal::subtract(Decimal::trim($item->subtotal), Decimal::trim((string) ($item->discount_total ?? '0'))), Decimal::trim($item->tax_included));
        $vat = Decimal::trim($item->tax_total);
        $quantity = (string) max(1, (int) $item->quantity);

        $basis = json_decode((string) ($item->pricing_basis ?? 'null'), true);
        $rates = $basis['tax_rates'] ?? [];
        // Keep a single VAT rate on zero-value lines; multiple/compound taxes retain the existing effective-rate calculation.
        $rate = count($rates) === 1 && $rates[0]['type'] === 'percent' ? Decimal::multiply($rates[0]['value'], '100') : $this->rate($net, $vat, $taxes);

        return ['unit_net' => Decimal::divide($net, $quantity, 6), 'unit_gross' => Decimal::divide(Decimal::trim($item->total), $quantity, 6), 'vat' => $vat,
            'vat_rate' => Decimal::trim($rate), 'net' => $net, 'gross' => Decimal::trim($item->total)];
    }

    /** @param iterable<object> $taxes */
    private function rate(string $net, string $vat, iterable $taxes): string
    {
        if (Decimal::compare($vat, '0') <= 0 || Decimal::compare($net, '0') <= 0) {
            return '0';
        }
        $actual = Decimal::round(Decimal::multiply(Decimal::divide($vat, $net, 6), '100'), 2);
        $percents = [];
        foreach ($taxes as $tax) {
            if ($tax->rate_type === 'percent' && $tax->rate_value !== null) {
                $percents[] = Decimal::multiply(Decimal::trim($tax->rate_value), '100');
            }
        }
        // A line taxed by several percentage taxes carries their sum.
        $candidates = $percents === [] ? [] : [...$percents, array_reduce($percents, fn (string $sum, string $p): string => Decimal::add($sum, $p), '0')];
        foreach ($candidates as $candidate) {
            $difference = Decimal::subtract($actual, $candidate);
            if (Decimal::compare($difference, '0') < 0) {
                $difference = Decimal::subtract('0', $difference);
            }
            if (Decimal::compare($difference, '0.1') <= 0) {
                return Decimal::trim(Decimal::round($candidate, 2));
            }
        }

        return Decimal::trim($actual);
    }
}
