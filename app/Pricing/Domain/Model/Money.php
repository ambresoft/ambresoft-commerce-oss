<?php

namespace App\Pricing\Domain\Model;

use App\Shared\Domain\Service\Decimal;

/** An exact, unrounded amount in a configured currency. Round only at a display/persistence boundary. */
final readonly class Money
{
    public function __construct(public string $amount, public Currency $currency)
    {
        Decimal::assert($amount);
    }

    /** The amount rounded half away from zero to the currency's configured precision. */
    public function rounded(): string
    {
        return Decimal::round($this->amount, $this->currency->decimals);
    }
}
