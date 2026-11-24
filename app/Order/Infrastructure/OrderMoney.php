<?php

namespace App\Order\Infrastructure;

use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Model\Money;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Domain\Service\CurrencyFormatter;
use App\Shared\Domain\Service\Decimal;

/**
 * Presentation of order amounts in the order's own currency and precision. The currency's current format is used
 * when it still exists (even disabled); otherwise the code is shown. Amounts are never converted.
 */
final class OrderMoney
{
    /** @var array<string, Currency>|null */
    private ?array $currencies = null;

    public function __construct(private CurrencyRepository $repository, private CurrencyFormatter $formatter) {}

    public function format(string $amount, string $code, int $decimals): string
    {
        $this->currencies ??= collect($this->repository->all())->keyBy(fn (Currency $c): string => $c->code)->all();
        $known = $this->currencies[$code] ?? null;
        $currency = new Currency($known->id ?? '0', $code, $known->name ?? $code, $known->symbol ?? $code, $known->symbolPosition ?? 'after', $known->thousandsSeparator ?? ',', $known->decimalSeparator ?? '.', $decimals, '1', false, true);

        return $this->formatter->format(new Money(Decimal::trim($amount), $currency));
    }
}
