<?php

namespace App\Tax\Domain\Model;

/** Installation-wide tax behavior (Settings → General). */
final readonly class CalculationSettings
{
    public const UNIT_PRICE = 'unit_price';

    public const SUBTOTAL = 'subtotal';

    public function __construct(
        public string $method = self::UNIT_PRICE,
        public bool $estimateWithoutAddress = false,
        public ?Address $defaultAddress = null,
        public bool $catalogPricesWithTax = false,
        public bool $cartPricesWithTax = false,
    ) {}
}
