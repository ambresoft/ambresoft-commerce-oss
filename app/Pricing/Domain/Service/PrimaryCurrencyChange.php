<?php

namespace App\Pricing\Domain\Service;

use App\Pricing\Domain\Exception\InvalidCurrencyChange;
use App\Pricing\Domain\Model\Currency;
use App\Shared\Domain\Service\Decimal;

/**
 * Re-denominates the installation from the current primary currency P to target T, preserving economic meaning.
 * Stored prices: new = round(old × rate(T), decimals(T)), half away from zero, computed from each stored amount once.
 * Rates: new rate(X) = rate(X) ÷ rate(T) at RATE_SCALE, so 1 T = rate(X)/rate(T) X; T becomes 1 and P becomes 1 ÷ rate(T).
 */
final class PrimaryCurrencyChange
{
    public const RATE_SCALE = 10;

    public const RATE_INTEGER_DIGITS = 10;

    public const PRICE_INTEGER_DIGITS = 14;

    /**
     * @param  list<Currency>  $currencies
     * @return array<string, string> code => rebased rate
     */
    public function rebasedRates(array $currencies, Currency $target): array
    {
        if ($target->primary) {
            throw new InvalidCurrencyChange('currencies.already_primary', ['code' => $target->code]);
        }
        if (! $target->active) {
            throw new InvalidCurrencyChange('currencies.primary_requires_active', ['code' => $target->code]);
        }
        $rates = [];
        foreach ($currencies as $currency) {
            $rate = $currency->code === $target->code ? '1' : Decimal::trim(Decimal::divide($currency->rate, $target->rate, self::RATE_SCALE));
            if (Decimal::compare($rate, '0') <= 0 || Decimal::integerDigits($rate) > self::RATE_INTEGER_DIGITS) {
                throw new InvalidCurrencyChange('currencies.rebased_rate_out_of_range', ['code' => $currency->code]);
            }
            $rates[$currency->code] = $rate;
        }

        return $rates;
    }

    public function convertPrice(string $price, Currency $target): string
    {
        $converted = Decimal::round(Decimal::multiply($price, $target->rate), $target->decimals);
        if (Decimal::integerDigits($converted) > self::PRICE_INTEGER_DIGITS) {
            throw new InvalidCurrencyChange('currencies.price_out_of_range', ['code' => $target->code]);
        }

        return $converted;
    }
}
