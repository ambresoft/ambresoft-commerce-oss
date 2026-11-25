<?php

namespace App\Order\Domain\Service;

use App\Shared\Domain\Service\Decimal;
use InvalidArgumentException;

/** Exact decimal order arithmetic; never floating point. */
final class OrderTotals
{
    /** Grand total: tax already contained in prices is not added again. */
    public function total(string $subtotal, string $discount, string $taxTotal, string $taxIncluded, string $shipping): string
    {
        return Decimal::add(Decimal::subtract(Decimal::add($subtotal, Decimal::subtract($taxTotal, $taxIncluded)), $discount), $shipping);
    }

    /**
     * Splits an amount across weights at the given precision so the parts add up exactly (largest remainder; ties go
     * to the earlier weight). Used to attribute one tax amount, calculated for several lines together, to each line.
     *
     * @param  list<string>  $weights  Non-negative.
     * @return list<string>
     */
    public function allocate(string $amount, array $weights, int $scale): array
    {
        if ($weights === []) {
            return [];
        }
        $sum = array_reduce($weights, fn (string $carry, string $w): string => Decimal::add($carry, $w), '0');
        if (Decimal::compare($sum, '0') <= 0) {
            // No basis to split on: everything goes to the first line.
            return [Decimal::round($amount, $scale), ...array_fill(0, count($weights) - 1, Decimal::round('0', $scale))];
        }
        if (Decimal::compare($amount, '0') < 0) {
            throw new InvalidArgumentException('Only non-negative amounts can be allocated.');
        }
        $unit = '1'.str_repeat('0', $scale);
        $units = Decimal::round(Decimal::multiply($amount, $unit), 0);
        $parts = [];
        $remainders = [];
        $assigned = '0';
        foreach ($weights as $index => $weight) {
            $exact = Decimal::divide(Decimal::multiply($units, $weight), $sum, 12);
            $floor = explode('.', $exact)[0];
            $parts[$index] = $floor;
            $remainders[$index] = Decimal::subtract($exact, $floor);
            $assigned = Decimal::add($assigned, $floor);
        }
        $left = (int) Decimal::subtract($units, $assigned);
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => Decimal::compare($remainders[$b], $remainders[$a]) ?: $a <=> $b);
        foreach (array_slice($order, 0, $left) as $index) {
            $parts[$index] = Decimal::add($parts[$index], '1');
        }
        ksort($parts);

        return array_map(fn (string $part): string => Decimal::round(Decimal::divide($part, $unit, $scale), $scale), array_values($parts));
    }
}
