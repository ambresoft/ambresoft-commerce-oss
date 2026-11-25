<?php

namespace Ambresoft\StripeCheckout\Domain;

use App\Shared\Domain\Service\Decimal;

/**
 * Currencies this integration offers and how Stripe counts their minor units. Money never passes through floats: the
 * amount string is shifted by its currency's exponent and only sent when that is exact. Currencies with special Stripe
 * rules (ISK, UGX, HUF, TWD) and currencies this list does not name are not offered instead of being rounded or guessed.
 */
final class StripeCurrencies
{
    /** Two minor-unit digits (cents). */
    private const TWO = ['AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'ILS', 'INR', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR'];

    /** No minor units. */
    private const ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Three minor-unit digits; Stripe requires the last one to be zero, so at most two decimals are ever sent. */
    private const THREE = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Stripe's largest charge is eight digits of minor units. */
    public const MAX_MINOR = '99999999';

    public static function exponent(string $currency): ?int
    {
        return match (true) {
            in_array($currency, self::TWO, true) => 2,
            in_array($currency, self::ZERO, true) => 0,
            in_array($currency, self::THREE, true) => 3,
            default => null,
        };
    }

    /** Whether shop amounts of this currency and precision can be sent to Stripe unchanged. */
    public static function supports(string $currency, int $shopDecimals): bool
    {
        $exponent = self::exponent($currency);

        return $exponent !== null && $shopDecimals <= ($exponent === 3 ? 2 : $exponent);
    }

    /** Integer minor units as a digit string, or null when the amount is not positive, not exact or too large. */
    public static function toMinor(string $amount, string $currency): ?string
    {
        $exponent = self::exponent($currency);
        if ($exponent === null || ! Decimal::isCanonical($amount) || Decimal::compare($amount, '0') <= 0) {
            return null;
        }
        $trimmed = Decimal::trim($amount);
        $limit = $exponent === 3 ? 2 : $exponent;
        if (Decimal::scale($trimmed) > $limit) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $trimmed, 2), 2, '');
        $minor = ltrim($whole.str_pad($fraction, $exponent, '0'), '0');
        if ($minor === '' || strlen($minor) > strlen(self::MAX_MINOR) || (strlen($minor) === strlen(self::MAX_MINOR) && strcmp($minor, self::MAX_MINOR) > 0)) {
            return null;
        }

        return $minor;
    }

    /** The decimal amount of integer minor units Stripe reports (canonical, trailing zeros trimmed). */
    public static function fromMinor(int $minor, string $currency): ?string
    {
        $exponent = self::exponent($currency);
        if ($exponent === null || $minor < 0) {
            return null;
        }
        if ($exponent === 0) {
            return (string) $minor;
        }
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);

        return Decimal::trim(substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent));
    }
}
