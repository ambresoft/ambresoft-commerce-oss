<?php

namespace App\Marketing\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/** Exact discount arithmetic. The result never exceeds the eligible merchandise amount. */
final class CouponAmounts
{
    public function discount(string $type, string $value, string $eligible, int $scale): string
    {
        $amount = $type === 'percentage' ? Decimal::divide(Decimal::multiply($eligible, $value), '100', $scale) : Decimal::round($value, $scale);

        return Decimal::compare($amount, $eligible) > 0 ? Decimal::round($eligible, $scale) : $amount;
    }

    /** Split an exact discounted line total into at most two currency-unit prices for tax calculation.
     * @return list<array{price: string, quantity: int}>
     */
    public function units(string $net, int $quantity, int $scale): array
    {
        $factor = bcpow('10', (string) $scale, 0);
        $minor = bcmul($net, $factor, 0);
        $unit = bcdiv($minor, (string) $quantity, 0);
        $extra = (int) bcmod($minor, (string) $quantity, 0);
        $parts = [];
        if ($quantity > $extra) {
            $parts[] = ['price' => bcdiv($unit, $factor, $scale), 'quantity' => $quantity - $extra];
        }
        if ($extra > 0) {
            $parts[] = ['price' => bcdiv(bcadd($unit, '1', 0), $factor, $scale), 'quantity' => $extra];
        }

        return $parts;
    }
}
