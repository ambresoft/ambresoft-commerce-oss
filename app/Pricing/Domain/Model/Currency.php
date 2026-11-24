<?php

namespace App\Pricing\Domain\Model;

use App\Shared\Domain\Service\Decimal;
use InvalidArgumentException;

/**
 * Configured currency. $rate is units of this currency per one unit of the primary currency;
 * the primary currency's rate is always exactly 1.
 */
final readonly class Currency
{
    public const MAX_DECIMALS = 6;

    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $symbol,
        public string $symbolPosition,
        public string $thousandsSeparator,
        public string $decimalSeparator,
        public int $decimals,
        public string $rate,
        public bool $primary,
        public bool $active,
        public int $version = 1,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1 || ! in_array($symbolPosition, ['before', 'after'], true)
            || $decimals < 0 || $decimals > self::MAX_DECIMALS || $decimalSeparator === '' || $thousandsSeparator === $decimalSeparator
            || Decimal::compare($rate, '0') <= 0 || ($primary && (Decimal::compare($rate, '1') !== 0 || ! $active))) {
            throw new InvalidArgumentException('Invalid currency configuration for '.$code.'.');
        }
    }
}
