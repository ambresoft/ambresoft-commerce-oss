<?php

namespace App\Storefront\Application\Contract;

use App\Storefront\Application\Command\CatalogProduct;

/** Optional batching capability; existing StorefrontCatalog implementations remain compatible. */
interface CatalogProductSelection
{
    /** Public products in requested order, under the same eligibility rules as product().
     * @param  list<string>  $ids  Maximum 100 IDs per batch
     * @return list<CatalogProduct>
     *
     * @throws CatalogUnavailable */
    public function productsById(array $ids, ?string $locale = null): array;
}
