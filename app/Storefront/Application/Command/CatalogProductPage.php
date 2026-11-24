<?php

namespace App\Storefront\Application\Command;

final readonly class CatalogProductPage
{
    /**
     * @param  list<CatalogProduct>  $products
     * @param  int  $total  Matches across pages. For text search this is the engine's count, an upper bound: hits the
     *                      index has not caught up with are dropped on reading
     * @param  bool  $totalExact  Whether $total is exact. True when browsing, false for text search
     * @param  bool  $hasMore  Whether to read the next page. Decide by this, never by the page's size: after dropped hits
     *                         a text search page can be short or empty while more follow
     */
    public function __construct(
        public array $products,
        public int $page,
        public int $perPage,
        public int $total,
        public bool $totalExact,
        public bool $hasMore,
    ) {}
}
