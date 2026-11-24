<?php

namespace App\Marketing\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/** Flat, bounded rules. Referenced records and category descendants are resolved by the adapter. */
final class PromotionRules
{
    /** @param list<bool> $matches */
    public function matches(string $mode, array $matches): bool
    {
        return $matches === [] || ($mode === 'all' ? ! in_array(false, $matches, true) : in_array(true, $matches, true));
    }

    public function subtotal(string $subtotal, ?string $minimum, ?string $maximum): bool
    {
        return $this->range($subtotal, $minimum, $maximum);
    }

    public function range(string $value, ?string $minimum, ?string $maximum): bool
    {
        return ($minimum === null || Decimal::compare($value, $minimum) >= 0) && ($maximum === null || Decimal::compare($value, $maximum) <= 0);
    }

    public function active(string $status, ?string $start, ?string $end, string $instant): bool
    {
        return $status === 'active' && ($start === null || $instant >= $start) && ($end === null || $instant < $end);
    }
}
