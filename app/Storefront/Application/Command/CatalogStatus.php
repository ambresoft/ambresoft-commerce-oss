<?php

namespace App\Storefront\Application\Command;

/** What a catalog reader needs to know about the store right now. */
final readonly class CatalogStatus
{
    /**
     * @param  string  $currency  ISO 4217 code of the storefront's prices, which is also what orders are charged in
     * @param  bool  $pricesVisible  False while the store hides prices: products then carry no pricing
     * @param  bool  $searchAvailable  Whether a search add-on can answer text queries
     */
    public function __construct(
        public bool $open,
        public string $currency,
        public int $decimals,
        public bool $pricesVisible,
        public bool $searchAvailable,
    ) {}
}
