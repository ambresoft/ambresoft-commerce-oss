<?php

namespace App\Pricing\Domain\Service;

use App\Pricing\Domain\Exception\CurrencyUnavailable;
use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Model\Money;
use App\Shared\Domain\Service\Decimal;
use InvalidArgumentException;

/**
 * Converts authoritative primary-currency amounts for display: converted = amount × target rate.
 * Always convert from the stored primary amount; never chain conversions or persist the result over it.
 */
final readonly class CurrencyConverter
{
    private Currency $primary;

    /** @var array<string, Currency> */
    private array $byCode;

    /** @param list<Currency> $currencies */
    public function __construct(array $currencies)
    {
        $primaries = array_values(array_filter($currencies, fn (Currency $c): bool => $c->primary));
        if (count($primaries) !== 1) {
            throw new InvalidArgumentException('Exactly one primary currency is required.');
        }
        $this->primary = $primaries[0];
        $byCode = [];
        foreach ($currencies as $currency) {
            $byCode[$currency->code] = $currency;
        }
        $this->byCode = $byCode;
    }

    public function primary(): Currency
    {
        return $this->primary;
    }

    /**
     * Primary first, then active secondaries by code: the future storefront selection set.
     *
     * @return list<Currency>
     */
    public function active(): array
    {
        $active = array_filter($this->byCode, fn (Currency $c): bool => $c->active && ! $c->primary);
        ksort($active);

        return [$this->primary, ...array_values($active)];
    }

    public function target(string $code): Currency
    {
        $currency = $this->byCode[$code] ?? null;
        if ($currency === null || ! $currency->active) {
            throw new CurrencyUnavailable($code);
        }

        return $currency;
    }

    public function convert(string $primaryAmount, ?string $targetCode = null): Money
    {
        Decimal::assert($primaryAmount);
        $target = $targetCode === null ? $this->primary : $this->target($targetCode);
        if ($target->primary) {
            return new Money($primaryAmount, $target);
        }

        return new Money(Decimal::multiply($primaryAmount, $target->rate), $target);
    }
}
