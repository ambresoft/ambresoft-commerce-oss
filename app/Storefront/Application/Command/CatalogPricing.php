<?php

namespace App\Storefront\Application\Command;

/**
 * A guest's price for one unit, exactly as the storefront shows it: decimal strings in the storefront currency,
 * tax included, after sale rules at the smallest orderable quantity. It is never a personalized price.
 */
final readonly class CatalogPricing
{
    /**
     * @param  string  $regular  The price without any sale
     * @param  ?string  $sale  The lower price while a sale applies to guests
     * @param  ?string  $list  The merchant's list price
     * @param  ?string  $reference  The price the storefront strikes through, when there is one
     * @param  int  $quantity  The smallest orderable quantity, which the sale was evaluated at
     */
    public function __construct(
        public string $currency,
        public string $regular,
        public ?string $sale,
        public ?string $list,
        public ?string $reference,
        public int $quantity,
    ) {}

    public function current(): string
    {
        return $this->sale ?? $this->regular;
    }
}
