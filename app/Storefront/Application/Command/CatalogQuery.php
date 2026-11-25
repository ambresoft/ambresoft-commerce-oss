<?php

namespace App\Storefront\Application\Command;

use InvalidArgumentException;

/** A product search. Price sorts follow the storefront: they use the stored catalog price, not the displayed one. */
final readonly class CatalogQuery
{
    public const SORTS = ['default', 'price_asc', 'price_desc', 'newest', 'rating'];

    public const MAX_PER_PAGE = 100;

    public const MAX_PAGE = 1000000;

    /**
     * @param  string  $text  Empty browses in storefront order; otherwise the active search add-on ranks the results
     * @param  bool  $includeDescendants  Also match every category below categoryId, at any depth
     * @param  bool  $inStockOnly  Only products in stock; out-of-stock products otherwise stay in the results
     * @param  list<string>  $brands  Brand codes or names, any language, case ignored, at most 100; a product of any of them matches
     * @param  bool  $onSaleOnly  Only products whose sale gives a visitor a lower price now, exactly as their cards show
     * @param  list<string>  $skus  Exact SKUs
     */
    public function __construct(
        public string $text = '',
        public ?string $categoryId = null,
        public bool $includeDescendants = false,
        public bool $inStockOnly = false,
        public string $sort = 'default',
        public int $page = 1,
        public int $perPage = 24,
        public ?string $locale = null,
        public array $brands = [],
        public bool $onSaleOnly = false,
        public array $skus = [],
    ) {
        foreach ([[$brands, 100], [$skus, 100]] as [$list, $limit]) {
            if (count($list) > $limit || array_filter($list, fn (string $v): bool => $v === '' || mb_strlen($v) > 120) !== []) {
                throw new InvalidArgumentException('Invalid catalog query.');
            }
        }
        if ($page < 1 || $page > self::MAX_PAGE || $perPage < 1 || $perPage > self::MAX_PER_PAGE || ! in_array($sort, self::SORTS, true)
            || ($categoryId !== null && preg_match('/^[1-9][0-9]{0,17}$/D', $categoryId) !== 1) || ($includeDescendants && $categoryId === null)) {
            throw new InvalidArgumentException('Invalid catalog query.');
        }
    }
}
