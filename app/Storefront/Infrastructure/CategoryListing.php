<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductCategories;
use App\Catalog\Infrastructure\ProductQueries;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\StickerProducts;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product listings: the products of a visible category and its subcategories, of a brand, or of a store-wide
 * collection (new, best sellers, offers), narrowed by the filters in the query string, with one aggregate query for
 * every filter count. The filters block and the main content block read the same validated state and the same counts,
 * prepared once per page.
 *
 * Filters of different groups combine with AND, the values of one group with OR. Each group's counts apply every
 * other group but not itself, so a shopper sees what choosing another value of that group would give. Price filters
 * and sorts use the stored catalog price; displayed prices (product sales, customer groups, tax display) stay the
 * cards' own, because they are computed per product by the pricing and tax services, not in SQL.
 */
final class CategoryListing
{
    /** Recommended: in stock first, then the best approved rating, then the newest (no invented popularity score). */
    public const SORTS = ['recommended', 'price_asc', 'price_desc', 'newest', 'rating'];

    /** Shopping options: created within the "new products" period, on an effective product sale, or among the best sellers. */
    public const SHOP = ['new', 'sale', 'bestseller'];

    /** The address parameter of sticker shopping options, valued by each sticker's readable filter_slug. */
    public const LABEL_PARAM = 'product-label';

    /** Store-wide collections with their own pages (/new, /bestsellers, /offers) and the shopping option each one is. */
    public const COLLECTIONS = ['new' => 'new', 'bestsellers' => 'bestseller', 'offers' => 'sale'];

    /** "Best seller" means one of this many best-selling products of the category or brand (paid units, all time). */
    private const BESTSELLERS = 20;

    private const AMOUNT = '/^\d{1,14}(\.\d{1,6})?$/D';

    private const NUMBER = '/^-?\d{1,14}(\.\d{1,6})?$/D';

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    /** @var array<string,array{type:string,slug:string}>|null The stickers offered as shopping options, by id. */
    private ?array $filterStickers = null;

    /**
     * The filters a query string asks for. Unknown, malformed, inactive or non-filterable values are dropped, so a
     * forged address can neither fail nor reach anything the listing would not offer.
     *
     * @param  array<string,mixed>  $query
     * @return array{price:array{0:?string,1:?string},brands:array<string,string>,choices:array<string,list<string>>,ranges:array<string,array{0:?string,1:?string}>,flags:list<string>,in_stock:bool,ratings:list<int>,shop:list<string>,members:array<string,list<int>>,statuses:list<string>,categories:list<string>}
     */
    public function state(array $query): array
    {
        $text = fn (string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';
        $list = fn (string $key): array => array_slice(array_values(array_unique(array_filter(explode(',', $text($key)), fn (string $v): bool => $v !== ''))), 0, 50);
        $range = function (string $key, string $pattern) use ($text): array {
            [$min, $max] = [$text($key.'_min'), $text($key.'_max')];
            [$min, $max] = [preg_match($pattern, $min) === 1 ? $min : null, preg_match($pattern, $max) === 1 ? $max : null];

            return $min !== null && $max !== null && Decimal::compare($min, $max) > 0 ? [$max, $min] : [$min, $max];
        };
        $settings = $this->settings();
        $state = ['price' => $settings['hide_product_prices'] ? [null, null] : $range('price', self::AMOUNT), 'brands' => [], 'choices' => [], 'ranges' => [], 'flags' => [],
            'in_stock' => $this->stockFilter() && $text('in_stock') === '1', 'ratings' => $settings['reviews_enabled'] ? array_map(intval(...), array_values(array_intersect(['5', '4', '3', '2', '1'], $list('rating')))) : [],
            // Stickers are shopping options too, but under their own readable address: ?product-label=black-friday.
            'shop' => [...array_values(array_intersect($this->baseShopOptions(), $list('shop'))), ...array_map(fn (string $id): string => 'sticker'.$id, array_keys(array_filter($this->filterStickers(), fn (array $s): bool => in_array($s['slug'], $list(self::LABEL_PARAM), true))))],
            'members' => [], 'statuses' => [],
            'categories' => array_values(array_filter($list('category'), fn (string $v): bool => preg_match('/^[1-9][0-9]{0,17}$/D', $v) === 1))];
        $statuses = array_values(array_filter($list('availability'), fn (string $v): bool => preg_match('/^[1-9][0-9]{0,17}$/D', $v) === 1));
        if ($statuses !== []) {
            $state['statuses'] = DB::table('availability_statuses')->whereIn('id', $statuses)->orderBy('id')->pluck('id')->map(strval(...))->all();
        }
        $codes = array_values(array_filter($list('brand'), fn (string $c): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $c) === 1));
        if ($codes !== []) {
            $state['brands'] = DB::table('brands')->where('status', 'active')->whereIn('code', $codes)->orderBy('id')->pluck('code', 'id')->mapWithKeys(fn ($c, $id) => [(string) $id => $c])->all();
        }
        $ids = [];
        foreach (array_keys($query) as $key) {
            if (preg_match('/^f([1-9][0-9]{0,17})(_min|_max)?$/D', (string) $key, $m) === 1) {
                $ids[$m[1]] = true;
            }
        }
        $features = array_slice(array_keys($ids), 0, 20);
        $types = $features === [] ? collect() : DB::table('features')->whereIn('id', $features)->where('filterable', true)->where('status', 'active')->pluck('type', 'id');
        foreach ($types as $id => $type) {
            $key = 'f'.$id;
            if ($type === 'number' && $range($key, self::NUMBER) !== [null, null]) {
                $state['ranges'][(string) $id] = $range($key, self::NUMBER);
            } elseif ($type === 'boolean' && $text($key) === '1') {
                $state['flags'][] = (string) $id;
            } elseif (in_array($type, ['single', 'multiple'], true)) {
                $options = array_values(array_filter($list($key), fn (string $v): bool => preg_match('/^[1-9][0-9]{0,17}$/D', $v) === 1));
                $valid = $options === [] ? [] : DB::table('feature_options')->where('feature_id', $id)->where('status', 'active')->whereIn('id', $options)->orderBy('id')->pluck('id')->map(strval(...))->all();
                if ($valid !== []) {
                    $state['choices'][(string) $id] = $valid;
                }
            }
        }

        return $state;
    }

    /**
     * What both blocks of a listing page read: what the page shows above the products, the query, its validated
     * filters and every count, computed once per page. An age-gated category gets no counts (nothing of its products is
     * sent).
     *
     * @param  array<string,mixed>  $page  ['category' => CategoryLanding::data()] or ['brand' => CatalogLandingPages::resolve()]
     * @param  array<string,mixed>  $scope  ['category' => id] (with its subcategories), ['brand' => id], ['option' => id, 'feature' => id] or ['collection' => new|bestsellers|offers]
     * @param  array<string,mixed>  $query
     * @param  array{locale:string,customer:?string}  $context
     * @param  array<string,bool>  $groups  The optional filter groups the filters block offers: shopping, delivery, rating, stock.
     * @return array{page:array<string,mixed>,scope:array<string,mixed>,query:array<string,mixed>,state:array<string,mixed>,facets:?array<string,mixed>}
     */
    public function prepare(array $page, array $scope, array $query, array $context, array $groups = []): array
    {
        $state = $this->state($query);
        $gated = ($page['category']['age_gate'] ?? null) !== null;
        if (($scope['collection'] ?? null) === 'offers') {
            // The offers page lists exactly the products whose sale applies to this visitor.
            $scope['ids'] = $this->saleIds(app(PublicCatalog::class)->listed($context['customer'], null), $context['customer']);
        }
        // Categories to filter by must be ones the visitor may see.
        if ($state['categories'] !== []) {
            $state['categories'] = app(PublicCatalog::class)->categories($context['customer'])->whereIn('categories.id', $state['categories'])->orderBy('categories.id')->pluck('categories.id')->map(strval(...))->all();
        }
        // A group the block does not offer still applies when the address asks for it, and is then shown to be removed.
        $groups = ['shopping' => ($groups['shopping'] ?? false) || $state['shop'] !== [], 'delivery' => ($groups['delivery'] ?? false) || $state['statuses'] !== [],
            'rating' => ($groups['rating'] ?? false) || $state['ratings'] !== [], 'stock' => ($groups['stock'] ?? false) || $state['in_stock'], 'category' => isset($scope['collection'])];
        if ($groups['shopping'] && ! $gated) {
            $state['members'] = $this->members($scope, $context['customer']);
        }

        return ['page' => $page, 'scope' => $scope, 'query' => $query, 'state' => $state, 'facets' => $gated ? null : $this->facets($scope, $state, $context, $groups)];
    }

    /** The validated settings of the layout's enabled filters block (none without one): which optional groups it offers.
     * @return array<string,mixed> */
    public function filterSettings(string $layout): array
    {
        return $this->blockSettings($layout, 'product_filters');
    }

    /** The validated settings of the layout's first enabled block of a type (none without one).
     * @return array<string,mixed> */
    public function blockSettings(string $layout, string $type): array
    {
        foreach (app(LayoutStore::class)->read($layout, true)['placements'] as $p) {
            if ($p['block']['type'] === $type && $p['enabled']) {
                try {
                    return app(StorefrontRegistry::class)->type($type)?->validate((array) ($p['block']['settings'] ?? [])) ?? [];
                } catch (\Throwable) {
                    return [];
                }
            }
        }

        return [];
    }

    /** The availability status a product shows, as SQL: AvailabilityRules::bucket picks the in-stock label for stock above
     * zero (or untracked stock), otherwise the out-of-stock one; a product without its own uses the store default. */
    private function statusSql(): string
    {
        $defaults = DB::table('availability_statuses')->where(fn ($q) => $q->where('default_in_stock', true)->orWhere('default_out_of_stock', true))->get(['id', 'default_in_stock', 'default_out_of_stock']);
        $in = (int) ($defaults->firstWhere('default_in_stock', true)->id ?? 0);
        $out = (int) ($defaults->firstWhere('default_out_of_stock', true)->id ?? 0);
        $stocked = $this->settings()['track_inventory'] ? 'products.quantity > 0' : 'TRUE';

        return "(CASE WHEN $stocked THEN coalesce(products.in_stock_status_id, $in) ELSE coalesce(products.out_of_stock_status_id, $out) END)";
    }

    /** The shopping options on offer; "on sale" is withheld while the store hides prices.
     * @return list<string> */
    private function shopOptions(): array
    {
        return [...$this->baseShopOptions(), ...array_map(fn (string $id): string => 'sticker'.$id, array_keys($this->filterStickers()))];
    }

    /** @return list<string> */
    private function baseShopOptions(): array
    {
        return $this->settings()['hide_product_prices'] ? ['new', 'bestseller'] : self::SHOP;
    }

    /** Active stickers within their dates that the merchant offers as shopping options (internally "sticker{id}").
     * @return array<string,array{type:string,slug:string}> */
    private function filterStickers(): array
    {
        $instant = now();

        return $this->filterStickers ??= DB::table('stickers')->where('is_filter', true)->where('status', 'active')->whereNotNull('filter_slug')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $instant))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $instant))
            ->orderBy('priority')->orderBy('id')->get(['id', 'type', 'filter_slug'])
            ->mapWithKeys(fn (object $s): array => [(string) $s->id => ['type' => (string) $s->type, 'slug' => (string) $s->filter_slug]])->all();
    }

    /**
     * The scope's products on sale and its best sellers. A product is on sale when ProductSales (customer groups,
     * dates, quotas, minimum quantity) gives it a lower price than its own, exactly as its card shows; best sellers use
     * the storefront's paid-units ranking.
     *
     * @param  array<string,mixed>  $scope
     * @return array{sale:list<int>,bestseller:list<int>}
     */
    private function members(array $scope, ?string $customer): array
    {
        $sale = in_array('sale', $this->shopOptions(), true) ? $this->saleIds($this->scope($scope, $customer), $customer) : [];
        $best = $this->scope($scope, $customer)->joinSub(app(Bestsellers::class)->productUnitTotals(), 'sold', 'sold.product_id', '=', 'products.id')
            ->orderByDesc('sold.units')->orderByDesc('products.id')->limit(self::BESTSELLERS)->pluck('products.id')->map(intval(...))->all();

        $members = ['sale' => $sale, 'bestseller' => $best];
        // Conditional stickers are decided in PHP; assigned ones are a plain SQL condition (shopCondition()).
        $dynamic = array_keys(array_filter($this->filterStickers(), fn (array $s): bool => $s['type'] === 'dynamic'));
        if ($dynamic !== []) {
            // shortcut: every product of the scope is evaluated in PHP on each request; a catalog with many thousands of products needs a persisted sticker projection.
            $holders = app(StickerProducts::class)->holders($this->scope($scope, $customer)->pluck('products.id')->all(), $dynamic, $customer);
            foreach ($dynamic as $id) {
                $members['sticker'.$id] = $holders[(string) $id] ?? [];
            }
        }

        return $members;
    }

    /**
     * Products of a query on sale right now for an anonymous visitor, by the same rule as the "sale" listing filter.
     *
     * @return list<int>
     */
    public function onSale(Builder $products): array
    {
        return $this->saleIds($products, null);
    }

    /**
     * The products of a query whose sale gives the visitor a lower price than their own: only products with an enabled
     * sale rule are priced, by ProductSales, exactly as their cards show.
     *
     * @return list<int>
     */
    private function saleIds(Builder $products, ?string $customer): array
    {
        if (! in_array('sale', $this->shopOptions(), true)) {
            return [];
        }
        // shortcut: every product with an enabled sale rule in the query is priced in PHP on each request; a catalog with many thousands of sale rules needs an indexed effective-sale projection.
        $candidates = $products->whereExists(fn ($s) => $s->selectRaw('1')->from('product_sales')->whereColumn('product_sales.product_id', 'products.id')->where('product_sales.is_enabled', true))
            ->get(['products.id', 'products.price', 'products.min_qty', 'products.qty_step']);
        $requests = [];
        foreach ($candidates as $p) {
            $requests[$p->id] = ['base' => (string) $p->price, 'quantity' => (int) ($p->min_qty ?? $p->qty_step ?? 1)];
        }
        $prices = app(ProductSales::class)->prices($requests, $customer, app(CurrencyRepository::class)->currentPrimary()->decimals);
        $sale = [];
        foreach ($candidates as $p) {
            if (isset($prices[$p->id]) && Decimal::compare($prices[$p->id]['price'], (string) $p->price) < 0) {
                $sale[] = (int) $p->id;
            }
        }

        return $sale;
    }

    /** One shopping option as a condition on products.
     * @param array<string,mixed> $state
     * @return array{0:string,1:list<mixed>} */
    private function shopCondition(string $option, array $state): array
    {
        if ($option === 'new') {
            return ['products.created_at >= ?', [now()->subDays((int) $this->settings()['new_products_days'])]];
        }
        $sticker = str_starts_with($option, 'sticker') ? substr($option, 7) : null;
        if ($sticker !== null && ($this->filterStickers()[$sticker]['type'] ?? null) === 'manual') {
            return ['EXISTS (SELECT 1 FROM product_stickers ps WHERE ps.product_id = products.id AND ps.sticker_id = ?)', [(int) $sticker]];
        }
        $ids = $state['members'][$option] ?? [];

        return [$ids === [] ? 'FALSE' : 'products.id IN ('.implode(',', array_map(intval(...), $ids)).')', []];
    }

    /** Sorts the shopper can choose; price sorts disappear while the store hides prices.
     * @return list<string> */
    public function sorts(): array
    {
        $sorts = $this->settings()['hide_product_prices'] ? ['recommended', 'newest', 'rating'] : self::SORTS;

        return $this->settings()['reviews_enabled'] ? $sorts : array_values(array_diff($sorts, ['rating']));
    }

    /**
     * What the main content block shows: the page of products, the result count, the active filters and sorting.
     *
     * @param  array{page:array<string,mixed>,scope:array<string,mixed>,query:array<string,mixed>,state:array<string,mixed>,facets:?array<string,mixed>,search?:array<string,mixed>}  $listing  From prepare(), or ProductSearch::listing() with the ranked page of IDs.
     * @param  array<string,mixed>  $settings  The block's validated settings.
     * @param  array{locale:string,customer:?string}  $context
     * @return array<string,mixed>
     */
    public function results(array $listing, array $settings, array $context): array
    {
        $scope = $listing['scope'];
        $facets = $listing['facets'];
        if ($facets === null) {
            return $listing['page'];
        }
        $state = $listing['state'];
        // Search results arrive ranked and paginated by the search engine (ProductSearch); listings are read here.
        $search = $listing['search'] ?? null;
        if ($search !== null) {
            [$ids, $page, $last, $sort, $default, $sorts, $products] = [$search['ids'], $search['page'], $search['last_page'], $search['sort'], $search['default_sort'], $search['sorts'], $search['products']];
        } else {
            $perPage = (int) $settings['products_per_page'];
            $sorts = $this->sorts();
            $requested = is_string($listing['query']['sort'] ?? null) ? $listing['query']['sort'] : '';
            $default = in_array($settings['default_sort'], $sorts, true) ? $settings['default_sort'] : 'recommended';
            $sort = in_array($requested, $sorts, true) ? $requested : $default;
            $last = max(1, (int) ceil($facets['total'] / $perPage));
            $requestedPage = is_string($listing['query']['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $listing['query']['page']) === 1 ? (int) $listing['query']['page'] : 1;
            $page = min($requestedPage, $last);

            $q = $this->filtered($scope, $state, $context['customer']);
            // The best sellers page recommends what sells most.
            if ($sort === 'recommended' && ($scope['collection'] ?? null) === 'bestsellers') {
                $q->joinSub(app(Bestsellers::class)->productUnitTotals(), 'sold', 'sold.product_id', '=', 'products.id')->orderByDesc('sold.units')->orderByDesc('products.id');
            } else {
                $this->order($q, $sort, $this->within(DB::table('products'), $scope)->select('products.id'));
            }
            $ids = $facets['total'] === 0 ? [] : $q->offset(($page - 1) * $perPage)->limit($perPage)->pluck('products.id')->map(strval(...))->all();
            $products = app(PublicCatalog::class)->listed($context['customer']);
        }
        $content = app(BlockContent::class);
        ['cards' => $cards, 'format' => $format] = $content->productCards($ids, $context, $products, $search === null ? 'lists' : 'search');
        if ($settings['show_variations']) {
            $cards = $content->withVariations($cards, $context);
        }
        if ($settings['show_brand']) {
            $cards = $content->withBrands($cards, $context['locale']);
        }
        // The product blocks' default card presentation with ratings, plus this block's own card options.
        $display = [...array_intersect_key(app(StorefrontRegistry::class)->type('products')?->definition()['defaults'] ?? [], array_flip(BlockContent::PRODUCT_DISPLAY)), 'show_rating' => true,
            'show_brand' => (bool) $settings['show_brand'], 'show_category' => (bool) $settings['show_category'], 'show_variations' => (bool) $settings['show_variations']];

        return [...$listing['page'], 'items' => array_values(array_filter(array_map(fn (string $id) => $cards[$id] ?? null, $ids))), 'currency_format' => $format, 'display' => $display,
            'total' => $facets['total'], 'page' => $page, 'last_page' => $last, 'sort' => $sort, 'default_sort' => $default, 'sorts' => $sorts, 'active' => $facets['active'],
            'per_row' => (int) $settings['products_per_row'], 'mobile_per_row' => (int) ($settings['mobile_products_per_row'] ?? 2), 'tablet_per_row' => (int) ($settings['tablet_products_per_row'] ?? 2), 'show_count' => (bool) $settings['show_count'], 'show_sorting' => (bool) $settings['show_sorting'],
            // The compare button under each card's heart (only while comparison is switched on).
            'comparison' => app(ProductComparison::class)->enabled() ? ['max' => app(ProductComparison::class)->max(), 'ids' => app(ProductComparison::class)->ids()] : null];
    }

    /** Orders products as listings do; review averages are read for $products, the set being listed. */
    public function order(Builder $q, string $sort, Builder $products): void
    {
        match ($sort) {
            'price_asc' => $q->orderBy('products.price')->orderBy('products.id'),
            'price_desc' => $q->orderByDesc('products.price')->orderByDesc('products.id'),
            'recommended' => $q->leftJoinSub($this->reviewAverages($products), 'rv', 'rv.product_id', '=', 'products.id')
                ->orderByRaw('(products.quantity > 0) DESC, rv.tenths DESC NULLS LAST, products.created_at DESC, products.id DESC'),
            'rating' => $q->leftJoinSub($this->reviewAverages($products), 'rv', 'rv.product_id', '=', 'products.id')
                ->orderByRaw('rv.tenths DESC NULLS LAST, products.created_at DESC, products.id DESC'),
            default => $q->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    /**
     * What the filters block shows: every filter group with its values and counts.
     *
     * @param  array{facets:?array<string,mixed>}  $listing  From prepare().
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    public function filters(array $listing, array $settings): array
    {
        $facets = $listing['facets'];
        if ($facets === null) {
            return [];
        }

        return ['filters' => ['total' => $facets['total'], 'groups' => $facets['groups'], 'active' => $facets['active'], 'currency_format' => app(BlockContent::class)->currencyFormat(), 'visible_options' => (int) $settings['visible_options']]];
    }

    /** Eligible products of the scope: the storefront's own publication and listing rules.
     * @param array<string,mixed> $scope */
    private function scope(array $scope, ?string $customer): Builder
    {
        return $this->within(app(PublicCatalog::class)->listed($customer, null), $scope);
    }

    /** Narrows a products query to a category with its subcategories, a brand, a feature option or a collection.
     * @param array<string,mixed> $scope */
    private function within(Builder $q, array $scope): Builder
    {
        return match (true) {
            isset($scope['ids']) => $scope['ids'] === [] ? $q->whereRaw('FALSE') : $q->whereIn('products.id', $scope['ids']),
            ($scope['collection'] ?? null) === 'new' => $q->where('products.created_at', '>=', now()->subDays((int) $this->settings()['new_products_days'])),
            ($scope['collection'] ?? null) === 'bestsellers' => $q->whereIn('products.id', app(Bestsellers::class)->productUnitTotals()->select('i.product_id')),
            // A category lists its own and its subcategories' products, by main or extra category.
            isset($scope['category']) => $q->whereRaw(...ProductCategories::in(app(CategoryHierarchy::class)->descendantIds($scope['category']))),
            isset($scope['brand']) => $q->where('products.brand_id', $scope['brand']),
            default => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('product_feature_assignments as sa')->join('product_feature_choices as sc', 'sc.assignment_id', '=', 'sa.id')
                ->whereColumn('sa.product_id', 'products.id')->where('sa.is_applicable', true)->where('sc.option_id', $scope['option'])),
        };
    }

    /** @param array<string,mixed> $scope
     * @param array<string,mixed> $state */
    private function filtered(array $scope, array $state, ?string $customer): Builder
    {
        $q = $this->scope($scope, $customer);
        foreach ($this->predicates($state) as [$sql, $bindings]) {
            $q->whereRaw($sql, $bindings);
        }

        $identity = app(VariationListings::class)->identitySql(app(VariationListings::class)->representatives($customer));

        return app(PublicCatalog::class)->listed($customer)->whereIn('products.id', $q->selectRaw($identity));
    }

    /**
     * One SQL condition per active filter group, keyed by group (price, brand, stock, rating, f{feature}).
     *
     * @param  array<string,mixed>  $state
     * @return array<string,array{0:string,1:list<mixed>}>
     */
    private function predicates(array $state): array
    {
        $p = [];
        $between = function (string $column, array $range): array {
            $sql = [];
            $bindings = [];
            foreach ([0 => '>=', 1 => '<='] as $i => $op) {
                if ($range[$i] !== null) {
                    $sql[] = "$column $op ?";
                    $bindings[] = $range[$i];
                }
            }

            return [implode(' AND ', $sql), $bindings];
        };
        if ($state['price'] !== [null, null]) {
            $p['price'] = $between('products.price', $state['price']);
        }
        if ($state['brands'] !== []) {
            $p['brand'] = ['products.brand_id IN ('.implode(',', array_fill(0, count($state['brands']), '?')).')', array_keys($state['brands'])];
        }
        if ($state['in_stock']) {
            $p['stock'] = ['products.quantity > 0', []];
        }
        if ($state['statuses'] !== []) {
            $p['status'] = [$this->statusSql().' IN ('.implode(',', array_map(intval(...), $state['statuses'])).')', []];
        }
        if ($state['shop'] !== []) {
            $conditions = array_map(fn (string $o): array => $this->shopCondition($o, $state), $state['shop']);
            $p['shop'] = ['('.implode(' OR ', array_column($conditions, 0)).')', array_merge(...array_column($conditions, 1))];
        }
        if ($state['ratings'] !== []) {
            // The whole stars of the approved average as cards show it (4.0 to 4.9 is four stars).
            $p['rating'] = ['('.$this->tenthsSql().') / 10 IN ('.implode(',', $state['ratings']).')', []];
        }
        if ($state['categories'] !== []) {
            $p['category'] = ['products.category_id IN ('.implode(',', array_map(intval(...), $state['categories'])).')', []];
        }
        $assigned = 'EXISTS (SELECT 1 FROM product_feature_assignments a %s WHERE a.product_id = products.id AND a.feature_id = ? AND a.is_applicable AND %s)';
        foreach ($state['choices'] as $feature => $options) {
            $p['f'.$feature] = [sprintf($assigned, 'JOIN product_feature_choices c ON c.assignment_id = a.id', 'c.option_id IN ('.implode(',', array_fill(0, count($options), '?')).')'), [$feature, ...$options]];
        }
        foreach ($state['ranges'] as $feature => $range) {
            [$sql, $bindings] = $between('a.number_value', $range);
            $p['f'.$feature] = [sprintf($assigned, '', $sql), [$feature, ...$bindings]];
        }
        foreach ($state['flags'] as $feature) {
            $p['f'.$feature] = [sprintf($assigned, '', 'a.boolean_value'), [$feature]];
        }

        return $p;
    }

    /** The approved-review averages (tenths, see tenthsSql()) of the scope's products in one grouped read, instead of a
     * lookup per product. */
    private function reviewAverages(Builder $products): Builder
    {
        // Products whose reviews are switched off have no average (no rating filter match, no rating order).
        return DB::table('product_reviews as r')->join('products as rp', 'rp.id', '=', 'r.product_id')->where('rp.reviews_enabled', true)->where('r.status', 'approved')
            ->whereIn('r.product_id', $products)
            ->groupBy('r.product_id')->selectRaw('r.product_id, (20 * sum(r.rating) + count(*)) / (2 * count(*)) AS tenths');
    }

    /** The approved-review average in tenths, rounded half up exactly as ReviewRules::summary shows it; null without reviews. */
    private function tenthsSql(): string
    {
        return "(SELECT (20 * sum(r.rating) + count(*)) / nullif(2 * count(*), 0) FROM product_reviews r WHERE r.product_id = products.id AND products.reviews_enabled AND r.status = 'approved')";
    }

    /** @return array<string,mixed> General settings, read once per listing. */
    private function settings(): array
    {
        return $this->settings ??= app(GeneralSettings::class)->all();
    }

    /** The "in stock" filter means something only while stock is tracked and out-of-stock products are listed. */
    private function stockFilter(): bool
    {
        $settings = $this->settings();

        return $settings['track_inventory'] && ! $settings['hide_out_of_stock_listings'];
    }

    /**
     * Every count of the sidebar plus the total in one statement: the category's eligible products are read once
     * (with one boolean per active filter group), then each group is aggregated with every other group's condition.
     *
     * @param  array<string,mixed>  $scope
     * @param  array<string,mixed>  $state
     * @param  array{locale:string,customer:?string}  $context
     * @param  array<string,bool>  $groups
     * @return array{total:int,groups:list<array<string,mixed>>,active:list<array<string,mixed>>}
     */
    private function facets(array $scope, array $state, array $context, array $groups): array
    {
        $shopping = $groups['shopping'];
        $delivery = $groups['delivery'];
        $predicates = $this->predicates($state);
        $base = $this->scope($scope, $context['customer'])->leftJoinSub($this->reviewAverages($this->within(DB::table('products'), $scope)->select('products.id')), 'rv', 'rv.product_id', '=', 'products.id')
            ->select('products.id', 'products.brand_id', 'products.price', 'products.quantity', 'products.category_id', 'rv.tenths');
        $identity = app(VariationListings::class)->identitySql(app(VariationListings::class)->representatives($context['customer']));
        $base->selectRaw($identity.' AS listing_id')->whereRaw($identity.' IS NOT NULL');
        if ($delivery) {
            $base->selectRaw($this->statusSql().' AS status_id');
        }
        // One flag per shopping option, counted below with every other group's condition.
        $shop = $shopping ? $this->shopOptions() : [];
        foreach ($shop as $option) {
            [$sql, $bindings] = $this->shopCondition($option, $state);
            $base->selectRaw("($sql) AS \"shop_$option\"", $bindings);
        }
        $conditions = [];
        foreach ($predicates as $group => [$sql, $bindings]) {
            if ($group === 'rating') {
                $conditions[$group] = 's.tenths / 10 IN ('.implode(',', $state['ratings']).')';
            } else {
                $base->selectRaw("($sql) AS \"g_$group\"", $bindings);
                $conditions[$group] = "s.\"g_$group\"";
            }
        }
        $except = fn (?string $skip): string => implode(' AND ', array_diff_key($conditions, [$skip => true])) ?: 'TRUE';
        // Each chosen feature counts its own values without its own condition, in its own branch, so every branch
        // narrows the products before joining their values; the other features share one branch with all conditions.
        $chosen = array_map(fn (string $g): int => (int) substr($g, 1), array_values(array_filter(array_keys($conditions), fn (string $g): bool => str_starts_with($g, 'f'))));
        $byFeature = function (string $select, string $join, string $where, string $group) use ($chosen, $except): string {
            $from = "FROM s JOIN product_feature_assignments a ON a.product_id = s.id AND a.is_applicable JOIN features f ON f.id = a.feature_id AND f.filterable AND f.status = 'active' $join";
            $branches = ["UNION ALL SELECT $select $from WHERE $where AND ".($chosen === [] ? '' : 'a.feature_id NOT IN ('.implode(',', $chosen).') AND ').$except(null)." GROUP BY $group"];
            foreach ($chosen as $feature) {
                $branches[] = "UNION ALL SELECT $select $from WHERE $where AND a.feature_id = $feature AND ".$except('f'.$feature)." GROUP BY $group";
            }

            return implode("\n", $branches);
        };
        $sql = 'WITH s AS MATERIALIZED ('.$base->toSql().')
            SELECT \'total\' AS kind, NULL::bigint AS k1, NULL::bigint AS k2, count(DISTINCT s.listing_id) AS n, NULL::numeric AS lo, NULL::numeric AS hi FROM s WHERE '.$except(null).'
            UNION ALL SELECT \'price\', NULL, NULL, count(DISTINCT s.listing_id), min(s.price), max(s.price) FROM s WHERE '.$except('price').'
            UNION ALL SELECT \'brand\', s.brand_id, NULL, count(DISTINCT s.listing_id), NULL, NULL FROM s WHERE s.brand_id IS NOT NULL AND '.$except('brand').' GROUP BY s.brand_id
            UNION ALL SELECT \'stock\', NULL, NULL, count(DISTINCT s.listing_id), NULL, NULL FROM s WHERE s.quantity > 0 AND '.$except('stock').'
            '.($delivery ? 'UNION ALL SELECT \'status\', s.status_id, NULL, count(DISTINCT s.listing_id), NULL, NULL FROM s WHERE '.$except('status').' GROUP BY s.status_id' : '').'
            '.($groups['category'] ? 'UNION ALL SELECT \'category\', s.category_id, NULL, count(DISTINCT s.listing_id), NULL, NULL FROM s WHERE '.$except('category').' GROUP BY s.category_id' : '').'
            '.implode("\n", array_map(fn (string $o): string => "UNION ALL SELECT 'shop', ".(array_search($o, $shop, true) + 1)." , NULL, count(DISTINCT s.listing_id), NULL, NULL FROM s WHERE s.\"shop_$o\" AND ".$except('shop'), $shop)).'
            UNION ALL SELECT \'rating\', s.tenths / 10, NULL, count(DISTINCT s.listing_id), NULL, NULL FROM s WHERE s.tenths IS NOT NULL AND '.$except('rating').' GROUP BY s.tenths / 10
            '.$byFeature("'choice', a.feature_id, c.option_id, count(DISTINCT s.listing_id), NULL, NULL", "JOIN product_feature_choices c ON c.assignment_id = a.id JOIN feature_options o ON o.id = c.option_id AND o.status = 'active'", 'TRUE', 'a.feature_id, c.option_id').'
            '.$byFeature("'number', a.feature_id, NULL, count(DISTINCT s.listing_id), min(a.number_value), max(a.number_value)", '', "f.type = 'number' AND a.number_value IS NOT NULL", 'a.feature_id').'
            '.$byFeature("'boolean', a.feature_id, NULL, count(DISTINCT s.listing_id), NULL, NULL", '', "f.type = 'boolean' AND a.boolean_value", 'a.feature_id');
        // The planner's cost estimate switches on JIT compilation, which costs more than it saves on this short read.
        $rows = DB::transaction(function () use ($sql, $base): array {
            DB::statement('SET LOCAL jit = off');

            return DB::select($sql, $base->getBindings());
        });

        $facets = $this->present($rows, $state, $context, $groups);
        // The page's own brand, feature option or shopping option is no choice there.
        $own = isset($scope['brand']) ? 'brand' : (isset($scope['feature']) ? 'f'.$scope['feature'] : null);
        $ownShop = self::COLLECTIONS[$scope['collection'] ?? ''] ?? null;
        $facets['groups'] = array_values(array_filter(array_map(function (array $g) use ($ownShop): array {
            if ($g['key'] === 'shop' && $ownShop !== null) {
                $g['options'] = array_values(array_filter($g['options'], fn (array $o): bool => $o['value'] !== $ownShop));
            }

            return $g;
        }, $facets['groups']), fn (array $g): bool => $g['key'] !== $own && ($g['kind'] === 'range' || $g['options'] !== [])));

        return $facets;
    }

    /**
     * Turns the aggregate rows into named filter groups (price, brand, availability, rating, then the features in
     * their group and feature order) and the active filter chips. Selected values stay listed, with zero if needed.
     * Search (ProductSearch) feeds it the search engine's counts in the same row shape, so both pages filter alike.
     *
     * @param  list<object>  $rows
     * @param  array<string,mixed>  $state
     * @param  array{locale:string,customer:?string}  $context
     * @param  array<string,bool>  $groups
     * @return array{total:int,groups:list<array<string,mixed>>,active:list<array<string,mixed>>}
     */
    public function present(array $rows, array $state, array $context, array $groups): array
    {
        $locale = $context['locale'];
        $shopping = $groups['shopping'];
        $delivery = $groups['delivery'];
        $shop = [];
        $categories = [];
        $statusCounts = [];
        $total = 0;
        $price = null;
        $brands = [];
        $stock = 0;
        $stars = [];
        $choices = [];
        $numbers = [];
        $flags = [];
        foreach ($rows as $r) {
            match ($r->kind) {
                'total' => $total = (int) $r->n,
                'price' => $price = (int) $r->n > 0 ? [Decimal::trim((string) $r->lo), Decimal::trim((string) $r->hi)] : null,
                'brand' => $brands[(string) $r->k1] = (int) $r->n,
                'stock' => $stock = (int) $r->n,
                'shop' => $shop[$this->shopOptions()[(int) $r->k1 - 1] ?? ''] = (int) $r->n,
                'status' => $statusCounts[(string) $r->k1] = (int) $r->n,
                'category' => $categories[(string) $r->k1] = (int) $r->n,
                'rating' => $stars[(int) $r->k1] = (int) $r->n,
                'choice' => $choices[(string) $r->k1][(string) $r->k2] = (int) $r->n,
                'number' => $numbers[(string) $r->k1] = [Decimal::trim((string) $r->lo), Decimal::trim((string) $r->hi)],
                'boolean' => $flags[(string) $r->k1] = (int) $r->n,
                default => null,
            };
        }
        foreach ($state['brands'] as $id => $code) {
            $brands[$id] ??= 0;
        }
        foreach ($state['choices'] as $feature => $options) {
            foreach ($options as $option) {
                $choices[$feature][$option] ??= 0;
            }
        }
        foreach ($state['flags'] as $feature) {
            $flags[$feature] ??= 0;
        }
        $offered = $groups;
        $groups = [];
        $active = [];
        if (! $this->settings()['hide_product_prices'] && ($price !== null || $state['price'] !== [null, null])) {
            $groups[] = ['key' => 'price', 'kind' => 'range', 'money' => true, 'name' => __('storefront.filter_price'), 'param' => 'price', 'unit' => '', 'min' => $price[0] ?? '0', 'max' => $price[1] ?? '0', 'from' => $state['price'][0], 'to' => $state['price'][1]];
            if ($state['price'] !== [null, null]) {
                $active[] = ['param' => 'price', 'kind' => 'range', 'money' => true, 'name' => __('storefront.filter_price'), 'unit' => '', 'from' => $state['price'][0], 'to' => $state['price'][1]];
            }
        }
        $choice = function (string $key, string $name, string $param, array $options) use (&$groups, &$active): void {
            if ($options === []) {
                return;
            }
            $groups[] = ['key' => $key, 'kind' => 'choice', 'name' => $name, 'param' => $param, 'options' => $options];
            foreach ($options as $o) {
                if ($o['selected']) {
                    $active[] = ['param' => $o['param'] ?? $param, 'kind' => 'choice', 'name' => $name, 'value' => $o['value'], 'label' => $o['label']];
                }
            }
        };
        if ($offered['category']) {
            foreach ($state['categories'] as $id) {
                $categories[$id] ??= 0;
            }
            $texts = $categories === [] ? [] : app(BlockContent::class)->translations('category_translations', 'category_id', array_keys($categories));
            $options = [];
            foreach ($categories as $id => $count) {
                $name = app(BlockContent::class)->text($texts[$id] ?? [], $locale, 'name');
                if ($name !== '') {
                    $options[] = ['value' => (string) $id, 'label' => $name, 'count' => $count, 'selected' => in_array((string) $id, $state['categories'], true)];
                }
            }
            // The categories with the most products first, as on comparison sites.
            usort($options, fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
            $choice('category', __('storefront.filter_category'), 'category', $options);
        }
        if ($shopping || $state['shop'] !== []) {
            $options = [];
            $stickers = array_keys($this->filterStickers());
            $texts = $stickers === [] ? [] : app(BlockContent::class)->translations('sticker_translations', 'sticker_id', $stickers);
            foreach ($this->shopOptions() as $option) {
                $selected = in_array($option, $state['shop'], true);
                $sticker = str_starts_with($option, 'sticker') ? substr($option, 7) : null;
                $label = $sticker !== null ? app(BlockContent::class)->text($texts[$sticker] ?? [], $locale, 'text') : __('storefront.shop_'.$option);
                if ($label !== '' && (($shop[$option] ?? 0) > 0 || $selected)) {
                    $options[] = ['value' => $sticker !== null ? $this->filterStickers()[$sticker]['slug'] : $option, 'label' => $label, 'count' => $shop[$option] ?? 0, 'selected' => $selected,
                        ...($sticker !== null ? ['param' => self::LABEL_PARAM] : [])];
                }
            }
            $choice('shop', __('storefront.filter_shopping'), 'shop', $options);
        }
        if ($delivery) {
            foreach ($state['statuses'] as $id) {
                $statusCounts[$id] ??= 0;
            }
            $default = app(ProductQueries::class)->defaultId();
            $current = app(LocaleCatalog::class)->active()->firstWhere('code', $locale)->id ?? $default;
            $options = [];
            if ($statusCounts !== []) {
                foreach (DB::table('availability_statuses as a')
                    ->leftJoin('availability_status_translations as c', fn ($j) => $j->on('c.status_id', '=', 'a.id')->where('c.locale_id', $current))
                    ->leftJoin('availability_status_translations as d', fn ($j) => $j->on('d.status_id', '=', 'a.id')->where('d.locale_id', $default))
                    ->whereIn('a.id', array_keys($statusCounts))->orderBy('a.position')->orderBy('a.id')->selectRaw("a.id, coalesce(nullif(btrim(c.name), ''), d.name, '') AS name")->get() as $a) {
                    if (trim((string) $a->name) !== '') {
                        $options[] = ['value' => (string) $a->id, 'label' => (string) $a->name, 'count' => $statusCounts[(string) $a->id], 'selected' => in_array((string) $a->id, $state['statuses'], true)];
                    }
                }
            }
            $choice('availability', __('storefront.filter_delivery'), 'availability', $options);
        }
        if ($brands !== []) {
            $named = app(BrandQueries::class)->named($locale)->whereIn('b.id', array_keys($brands))->where('b.status', 'active')->get();
            $options = $named->filter(fn (object $b): bool => trim($b->name) !== '')->sortBy(fn (object $b): string => mb_strtolower($b->name))
                ->map(fn (object $b): array => ['value' => $b->code, 'label' => $b->name, 'count' => $brands[(string) $b->id], 'selected' => isset($state['brands'][(string) $b->id])])->values()->all();
            $choice('brand', __('storefront.filter_brand'), 'brand', $options);
        }
        if ($offered['stock'] && $this->stockFilter() && ($stock > 0 || $state['in_stock'])) {
            $choice('in_stock', __('storefront.filter_availability'), 'in_stock', [['value' => '1', 'label' => __('storefront.filter_in_stock'), 'count' => $stock, 'selected' => $state['in_stock']]]);
        }
        if ($offered['rating'] && ($stars !== [] || $state['ratings'] !== [])) {
            $options = [];
            foreach ([5, 4, 3, 2, 1] as $n) {
                $selected = in_array($n, $state['ratings'], true);
                if (($stars[$n] ?? 0) > 0 || $selected) {
                    $options[] = ['value' => (string) $n, 'label' => __('storefront.filter_rating_stars', ['count' => $n]), 'count' => $stars[$n] ?? 0, 'selected' => $selected, 'stars' => $n];
                }
            }
            if ($options !== []) {
                $groups[] = ['key' => 'rating', 'kind' => 'rating', 'name' => __('storefront.filter_rating'), 'param' => 'rating', 'options' => $options];
                foreach ($options as $o) {
                    if ($o['selected']) {
                        $active[] = ['param' => 'rating', 'kind' => 'choice', 'name' => __('storefront.filter_rating'), 'value' => $o['value'], 'label' => $o['label']];
                    }
                }
            }
        }
        $featureIds = array_values(array_unique([...array_keys($choices), ...array_keys($numbers), ...array_keys($flags), ...array_keys($state['ranges'])]));
        foreach ($this->features($featureIds, array_merge([], ...array_map(array_keys(...), array_values($choices))), $locale) as $f) {
            $id = $f['id'];
            if ($f['type'] === 'number') {
                $range = $state['ranges'][$id] ?? [null, null];
                if (! isset($numbers[$id]) && $range === [null, null]) {
                    continue;
                }
                $groups[] = ['key' => 'f'.$id, 'kind' => 'range', 'money' => false, 'name' => $f['name'], 'param' => 'f'.$id, 'unit' => $f['unit'], 'min' => $numbers[$id][0] ?? '0', 'max' => $numbers[$id][1] ?? '0', 'from' => $range[0], 'to' => $range[1]];
                if ($range !== [null, null]) {
                    $active[] = ['param' => 'f'.$id, 'kind' => 'range', 'money' => false, 'name' => $f['name'], 'unit' => $f['unit'], 'from' => $range[0], 'to' => $range[1]];
                }
            } elseif ($f['type'] === 'boolean') {
                if (isset($flags[$id])) {
                    $choice('f'.$id, $f['name'], 'f'.$id, [['value' => '1', 'label' => __('storefront.yes'), 'count' => $flags[$id], 'selected' => in_array($id, $state['flags'], true)]]);
                }
            } else {
                $options = [];
                foreach ($f['options'] as $o) {
                    if (isset($choices[$id][$o['id']])) {
                        $options[] = ['value' => $o['id'], 'label' => $o['label'], 'count' => $choices[$id][$o['id']], 'selected' => in_array($o['id'], $state['choices'][$id] ?? [], true)];
                    }
                }
                $choice('f'.$id, $f['name'], 'f'.$id, $options);
            }
        }

        return ['total' => $total, 'groups' => $groups, 'active' => $active];
    }

    /**
     * Names, units and ordered option labels of the given filterable features, in the shopper's language with the
     * default language as fallback; features without a name are left out.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $optionIds
     * @return list<array{id:string,type:string,name:string,unit:string,options:list<array{id:string,label:string}>}>
     */
    private function features(array $ids, array $optionIds, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $default = app(ProductQueries::class)->defaultId();
        $current = app(LocaleCatalog::class)->active()->firstWhere('code', $locale)->id ?? $default;
        $rows = DB::table('features as f')->join('feature_groups as g', 'g.id', '=', 'f.group_id')
            ->leftJoin('feature_translations as c', fn ($j) => $j->on('c.feature_id', '=', 'f.id')->where('c.locale_id', $current))
            ->leftJoin('feature_translations as d', fn ($j) => $j->on('d.feature_id', '=', 'f.id')->where('d.locale_id', $default))
            ->whereIn('f.id', $ids)->where('f.filterable', true)->where('f.status', 'active')
            ->orderBy('g.position')->orderBy('f.position')->orderBy('f.id')
            ->selectRaw("f.id, f.type, f.unit, coalesce(nullif(btrim(c.name), ''), d.name, '') AS name")->get();
        $labels = [];
        if ($optionIds !== []) {
            foreach (DB::table('feature_options as o')->leftJoin('feature_option_translations as c', fn ($j) => $j->on('c.option_id', '=', 'o.id')->where('c.locale_id', $current))
                ->leftJoin('feature_option_translations as d', fn ($j) => $j->on('d.option_id', '=', 'o.id')->where('d.locale_id', $default))
                ->whereIn('o.id', array_values(array_unique($optionIds)))->where('o.status', 'active')->orderBy('o.position')->orderBy('o.id')
                ->selectRaw("o.id, o.feature_id, coalesce(nullif(btrim(c.label), ''), d.label, '') AS label")->get() as $o) {
                if (trim($o->label) !== '') {
                    $labels[(string) $o->feature_id][] = ['id' => (string) $o->id, 'label' => $o->label];
                }
            }
        }
        $out = [];
        foreach ($rows as $f) {
            $name = trim((string) $f->name);
            if ($name !== '') {
                $out[] = ['id' => (string) $f->id, 'type' => $f->type, 'name' => $name, 'unit' => (string) $f->unit, 'options' => $labels[(string) $f->id] ?? []];
            }
        }

        return $out;
    }
}
