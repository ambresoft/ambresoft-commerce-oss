<?php

namespace App\Reporting\Infrastructure;

use App\Order\Infrastructure\OrderMoney;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;

/** Formats report amounts in the currency of the orders they were summed from; amounts are never converted. */
final class ReportMoney
{
    /** @var array<string, int> */
    private array $decimals = [];

    public function __construct(private OrderMoney $money) {}

    /** The precision orders of this currency were stored with (the largest, should it ever have changed). */
    public function decimals(string $currency): int
    {
        return $this->decimals[$currency] ??= (int) (DB::table('orders')->where('currency_code', $currency)->max('currency_decimals') ?? $this->fallback($currency));
    }

    public function format(?string $amount, string $currency): ?string
    {
        return $amount === null ? null : $this->money->format(Decimal::round($amount, $this->decimals($currency)), $currency, $this->decimals($currency));
    }

    /** A canonical amount rounded to the currency's display precision, for chart axes and CSV (strings, never floats). */
    public function plain(?string $amount, string $currency): ?string
    {
        return $amount === null ? null : Decimal::atLeastScale(Decimal::round($amount, $this->decimals($currency)), $this->decimals($currency));
    }

    private function fallback(string $currency): int
    {
        return (int) (DB::table('currencies')->where('code', $currency)->value('decimals') ?? 2);
    }
}
