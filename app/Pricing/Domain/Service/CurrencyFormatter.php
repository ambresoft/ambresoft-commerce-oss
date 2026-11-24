<?php

namespace App\Pricing\Domain\Service;

use App\Pricing\Domain\Model\Money;

/**
 * Presentation only: the returned string is never an authoritative or persisted amount.
 * Rounds half away from zero to the currency precision, groups thousands, then places the symbol
 * directly before the amount or after it separated by a no-break space. Negative values lead with "-".
 */
final class CurrencyFormatter
{
    public function format(Money $money): string
    {
        $currency = $money->currency;
        $rounded = $money->rounded();
        $negative = str_starts_with($rounded, '-');
        [$integer, $fraction] = explode('.', ltrim($rounded, '-')) + [1 => ''];
        $first = strlen($integer) % 3 ?: 3;
        $groups = [substr($integer, 0, $first)];
        for ($i = $first; $i < strlen($integer); $i += 3) {
            $groups[] = substr($integer, $i, 3);
        }
        $number = implode($currency->thousandsSeparator, $groups).($currency->decimals > 0 ? $currency->decimalSeparator.$fraction : '');
        $body = $currency->symbolPosition === 'before' ? $currency->symbol.$number : $number."\u{00A0}".$currency->symbol;

        return ($negative ? '-' : '').$body;
    }
}
