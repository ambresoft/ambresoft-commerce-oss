<?php

namespace App\Pricing\Application\Service;

use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Domain\Service\CurrencyConverter;
use App\Pricing\Domain\Service\CurrencyFormatter;

/**
 * Shared entry point for displaying authoritative primary-currency amounts (admin now; storefront, cart,
 * order and shipping display later). It never mutates or persists the amounts it receives.
 */
final readonly class PriceDisplay
{
    public function __construct(private CurrencyRepository $currencies, private CurrencyFormatter $formatter) {}

    /** One configuration snapshot; reuse it for many amounts in a single request. */
    public function converter(): CurrencyConverter
    {
        return new CurrencyConverter($this->currencies->all());
    }

    public function primary(): Currency
    {
        return $this->converter()->primary();
    }

    /** Converts a primary amount to an active target (default: primary) and formats it. */
    public function format(string $primaryAmount, ?string $targetCode = null, ?CurrencyConverter $converter = null): string
    {
        return $this->formatter->format(($converter ?? $this->converter())->convert($primaryAmount, $targetCode));
    }
}
