<?php

namespace App\Payment\Application\Command;

/** An exact amount (canonical decimal string) in a currency with its precision; never a float. */
final readonly class PaymentAmount
{
    public function __construct(public string $value, public string $currency, public int $decimals) {}
}
