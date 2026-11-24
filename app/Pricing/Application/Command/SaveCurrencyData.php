<?php

namespace App\Pricing\Application\Command;

/** Validated currency settings. Separators are the actual characters; rate is a canonical decimal string. */
final readonly class SaveCurrencyData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public string $symbolPosition,
        public string $thousandsSeparator,
        public string $decimalSeparator,
        public int $decimals,
        public string $rate,
        public string $status,
    ) {}
}
