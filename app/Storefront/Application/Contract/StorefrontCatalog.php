<?php

namespace App\Storefront\Application\Contract;

use App\Storefront\Application\Command\CatalogCategory;
use App\Storefront\Application\Command\CatalogPage;
use App\Storefront\Application\Command\CatalogProduct;
use App\Storefront\Application\Command\CatalogProductPage;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Command\CatalogStatus;

/**
 * The public catalog for add-ons: what an anonymous visitor may discover on the storefront, calculated by the
 * storefront's own rules. It takes no customer, so it can never return a customer's prices or restricted products.
 *
 * A product is public when it is listed, its category is not excluded from discovery and no age check stands in front
 * of it (an anonymous reader cannot confirm an age). Search and product() share that one rule, so knowing an ID gains
 * nothing. Reads throw CatalogUnavailable rather than answering empty while the store is closed.
 */
interface StorefrontCatalog
{
    /** Never throws CatalogUnavailable, so a caller can learn why reads are refused. */
    public function status(): CatalogStatus;

    /**
     * @throws CatalogUnavailable
     */
    public function searchProducts(CatalogQuery $query): CatalogProductPage;

    /**
     * @throws CatalogUnavailable
     */
    public function product(string $id, ?string $locale = null): ?CatalogProduct;

    /**
     * Every public category, parents included; parentId carries the tree.
     *
     * @return list<CatalogCategory>
     *
     * @throws CatalogUnavailable
     */
    public function categories(?string $locale = null): array;

    /**
     * Published content pages with their FAQs. Stores keep policies here; the CMS does not classify pages by topic.
     *
     * @return list<CatalogPage>
     *
     * @throws CatalogUnavailable
     */
    public function pages(?string $locale = null): array;
}
