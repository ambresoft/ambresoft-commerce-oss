<?php

namespace App\Tax\Domain\Model;

/** A priced line. The unit price must already be net of any discount; discount allocation belongs to a future phase. */
final readonly class TaxLine
{
    public function __construct(public string $productId, public string $unitPrice, public int $quantity = 1) {}
}
