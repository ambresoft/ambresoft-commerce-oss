<?php

namespace App\Tax\Domain\Model;

/** A tax together with the rate and area that apply to the address. */
final readonly class ResolvedTax
{
    public function __construct(public TaxDefinition $tax, public TaxRate $rate, public RateArea $area) {}
}
