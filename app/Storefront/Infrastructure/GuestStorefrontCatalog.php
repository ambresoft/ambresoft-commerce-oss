<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductAvailability;
use App\Catalog\Infrastructure\ProductCategories;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Search\Application\Contract\SearchUnavailable;
use App\Search\Infrastructure\SearchIndex;
use App\Search\Infrastructure\SearchSettings;
use App\Search\Infrastructure\SearchVisibility;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Storefront\Application\Command\CatalogAvailability;
use App\Storefront\Application\Command\CatalogCategory;
use App\Storefront\Application\Command\CatalogPage;
use App\Storefront\Application\Command\CatalogPricing;
use App\Storefront\Application\Command\CatalogProduct;
use App\Storefront\Application\Command\CatalogProductPage;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Command\CatalogStatus;
use App\Storefront\Application\Contract\CatalogProductSelection;
use App\Storefront\Application\Contract\CatalogUnavailable;
use App\Storefront\Application\Contract\StorefrontCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The published catalog, read as an anonymous visitor through the storefront's own services: PublicCatalog for
 * visibility, BlockContent for prices, ProductSearch and CategoryListing for ranking. It adds no pricing or publication
 * rule of its own, only the narrowing the contract states: nothing a guest cannot discover or confirm.
 */
final class GuestStorefrontCatalog implements CatalogProductSelection, StorefrontCatalog
{
    private const ID = '/^[1-9][0-9]{0,17}$/D';

    public function status(): CatalogStatus
    {
        $settings = app(GeneralSettings::class)->all();
        $currency = app(CurrencyRepository::class)->currentPrimary();
        $provider = app(SearchSettings::class)->provider();

        return new CatalogStatus((bool) $settings['storefront_open'], $currency->code, $currency->decimals, ! $settings['hide_product_prices'],
            $provider !== null && app(SearchIndex::class)->built($provider));
    }

    public function searchProducts(CatalogQuery $query): CatalogProductPage
    {
        $this->assertOpen();
        $settings = app(GeneralSettings::class)->all();
        if ($settings['hide_product_prices'] && in_array($query->sort, ['price_asc', 'price_desc'], true)) {
            throw new InvalidArgumentException('Prices are hidden, so products cannot be sorted by price.');
        }
        if ($settings['hide_product_prices'] && $query->onSaleOnly) {
            throw new InvalidArgumentException('Prices are hidden, so products cannot be filtered by sale.');
        }
        $locale = $this->locale($query->locale);
        $visible = $this->visibleCategories($locale);
        $allowed = array_keys($visible);
        if ($query->categoryId !== null) {
            $wanted = $query->includeDescendants ? app(CategoryHierarchy::class)->descendantIds($query->categoryId) : [$query->categoryId];
            $allowed = array_values(array_intersect($allowed, array_map(strval(...), $wanted)));
        }
        // With stock tracking off every product counts as in stock, so the quantity means nothing.
        $inStock = $query->inStockOnly && $settings['track_inventory'];
        $narrowed = $query->brands !== [] || $query->skus !== [] || $query->onSaleOnly;
        if ($query->text !== '') {
            try {
                $result = app(ProductSearch::class)->ranked($query->text, $locale, $query->sort === 'default' ? 'relevance' : $query->sort, $query->page, $query->perPage,
                    array_values(array_intersect(app(SearchVisibility::class)->categories(null), $allowed)), $inStock);
            } catch (SearchUnavailable) {
                throw new CatalogUnavailable('search');
            }
            [$ids, $total, $exact] = [$result->ids, $result->total, false];
            if ($narrowed && $ids !== []) {
                // The engine's pages stay as they are: a page may come back short, as for a stale hit.
                $kept = array_flip($this->narrow($this->products($allowed)->whereIn('products.id', array_map(intval(...), $ids)), $query)->pluck('products.id')->map(strval(...))->all());
                $ids = array_values(array_filter($ids, fn (string $id): bool => isset($kept[$id])));
            }
        } else {
            // A chosen category also lists products placed in it as an extra category; visibility follows the main one.
            $products = $query->categoryId === null ? $this->products($allowed) : $this->products(array_keys($visible))->whereRaw(...ProductCategories::in($allowed));
            if ($narrowed) {
                $this->narrow($products, $query);
            }
            if ($inStock) {
                $products->where('products.quantity', '>', 0);
            }
            [$total, $exact] = [(clone $products)->count(), true];
            app(CategoryListing::class)->order($products, $query->sort === 'default' ? 'recommended' : $query->sort, (clone $products)->select('products.id'));
            $ids = $products->offset(($query->page - 1) * $query->perPage)->limit($query->perPage)->pluck('products.id')->map(strval(...))->all();
        }

        return new CatalogProductPage($this->read($ids, $locale, $visible), $query->page, $query->perPage, $total, $exact, $total > $query->page * $query->perPage);
    }

    public function product(string $id, ?string $locale = null): ?CatalogProduct
    {
        return $this->productsById([$id], $locale)[0] ?? null;
    }

    public function productsById(array $ids, ?string $locale = null): array
    {
        $this->assertOpen();
        if (count($ids) > 100) {
            throw new InvalidArgumentException('At most 100 products can be selected in one catalog batch.');
        }
        $ids = array_values(array_unique(array_filter($ids, fn (string $id): bool => preg_match(self::ID, $id) === 1)));
        $locale = $this->locale($locale);

        return $this->read($ids, $locale, $this->visibleCategories($locale));
    }

    public function categories(?string $locale = null): array
    {
        $this->assertOpen();

        return array_values($this->visibleCategories($this->locale($locale)));
    }

    public function pages(?string $locale = null): array
    {
        $this->assertOpen();
        $locale = $this->locale($locale);
        $pages = app(StorefrontPages::class);
        $out = [];
        foreach ($pages->visible(DB::table('pages')->where('status', 'active')->orderBy('position')->orderBy('id')->pluck('id')->map(strval(...))->all(), null) as $id) {
            $page = $pages->data($id, $locale);
            if ($page['name'] !== '') {
                $out[] = new CatalogPage($id, $page['name'], $this->plain($page['content']), url($page['url']),
                    array_map(fn (array $faq): array => ['question' => $faq['question'], 'answer' => $this->plain($faq['answer'])], $page['faqs']));
            }
        }

        return $out;
    }

    private function assertOpen(): void
    {
        if (! app(GeneralSettings::class)->all()['storefront_open']) {
            throw new CatalogUnavailable('closed');
        }
    }

    private function locale(?string $locale): string
    {
        $catalog = app(LocaleCatalog::class);

        return $catalog->active()->contains('code', $locale) ? (string) $locale : $catalog->defaultCode();
    }

    /**
     * Categories a guest may discover: publicly visible, with no excluded or age-checked category on the way up.
     *
     * @return array<string, CatalogCategory>
     */
    private function visibleCategories(string $locale): array
    {
        // PublicCatalog only returns categories whose whole ancestry is public, so every parent is in this list.
        $rows = app(PublicCatalog::class)->categories(null)->orderBy('categories.position')->orderBy('categories.id')
            ->get(['categories.id', 'categories.parent_id', 'categories.exclude_from_discovery', 'categories.age_verification', 'categories.age_limit'])->keyBy('id');
        $visible = $rows->filter(function (object $category) use ($rows): bool {
            for ($c = $category; $c !== null; $c = $rows[$c->parent_id] ?? null) {
                if ($c->exclude_from_discovery || ($c->age_verification && $c->age_limit !== null)) {
                    return false;
                }
            }

            return true;
        });
        $ids = $visible->keys()->map(strval(...))->all();
        if ($ids === []) {
            return [];
        }
        $content = app(BlockContent::class);
        $texts = $content->translations('category_translations', 'category_id', $ids);
        $paths = app(CategoryUrls::class)->paths($ids, $locale);
        $categories = [];
        foreach ($visible as $id => $row) {
            $name = $content->text($texts[$id] ?? [], $locale, 'name');
            if ($name !== '') {
                $path = $paths[(string) $id] ?? null;
                $categories[(string) $id] = new CatalogCategory((string) $id, $name, $path === null ? null : url($path), $row->parent_id === null ? null : (string) $row->parent_id);
            }
        }

        return $categories;
    }

    /** Brand, SKU and sale narrowing. A brand nobody has, or no sale, leaves nothing rather than everything. */
    private function narrow(Builder $products, CatalogQuery $query): Builder
    {
        if ($query->brands !== []) {
            $wanted = array_map(mb_strtolower(...), $query->brands);
            $brands = DB::table('brands')->where('status', 'active')->where(fn ($q) => $q->whereIn(DB::raw('lower(code)'), $wanted)
                ->orWhereIn('id', DB::table('brand_translations')->whereIn(DB::raw('lower(name)'), $wanted)->select('brand_id')))->pluck('id')->all();
            $products->whereIn('products.brand_id', $brands ?: [0]);
        }
        if ($query->skus !== []) {
            $products->whereIn('products.sku', $query->skus);
        }
        if ($query->onSaleOnly) {
            $products->whereIn('products.id', app(CategoryListing::class)->onSale(clone $products) ?: [0]);
        }

        return $products;
    }

    /** @param list<int|string> $categories */
    private function products(array $categories): Builder
    {
        return app(PublicCatalog::class)->listed(null)->whereIn('products.category_id', array_map(intval(...), $categories ?: [0]));
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, CatalogCategory>  $categories
     * @return list<CatalogProduct>
     */
    private function read(array $ids, string $locale, array $categories): array
    {
        if ($ids === []) {
            return [];
        }
        $content = app(BlockContent::class);
        ['cards' => $cards, 'format' => $format, 'pricing' => $pricing] = $content->productCards($ids, ['locale' => $locale, 'customer' => null], $this->products(array_keys($categories)));
        $cards = $content->withBrands($cards, $locale);
        $found = array_map(strval(...), array_keys($cards));
        $records = ProductRecord::query()->whereIn('id', $found)->get()->keyBy('id');
        $texts = $content->translations('product_translations', 'product_id', $found);
        $sanitizer = app(RichTextSanitizer::class);
        $settings = app(GeneralSettings::class)->all();
        $pages = app(ProductPage::class);
        $images = $pages->imagesFor($found);
        $features = $pages->featuresFor($found, $locale, $format['decimal']);
        $availability = app(ProductAvailability::class)->forProducts($records, $locale);
        $typed = DB::table('product_feature_assignments as a')->join('features as f', 'f.id', '=', 'a.feature_id')->whereIn('a.product_id', $found)
            ->get(['a.product_id', 'f.id', 'f.code', 'f.type', 'f.unit', 'a.number_value'])->groupBy('product_id');
        $groups = DB::table('variation_group_members')->whereIn('product_id', $found)->pluck('group_id', 'product_id');
        $groupFeatures = DB::table('variation_group_features')->whereIn('group_id', $groups->unique()->values()->all())->orderBy('position')->orderBy('feature_id')
            ->get(['group_id', 'feature_id'])->groupBy('group_id');
        $companions = DB::table('product_companions')->whereIn('product_id', $found)->orderBy('position')->get(['product_id', 'companion_id'])->groupBy('product_id');
        // A companion is listed only while a visitor could find it too.
        $public = array_flip($this->products(array_keys($categories))->whereIn('products.id', $companions->flatten()->pluck('companion_id')->unique()->values()->all() ?: [0])
            ->pluck('products.id')->map(strval(...))->all());
        $products = [];
        foreach ($ids as $id) {
            $card = $cards[$id] ?? null;
            $record = $records[$id] ?? null;
            if ($card === null || $record === null) {
                continue;
            }
            $description = $sanitizer->sanitize($content->text($texts[$id] ?? [], $locale, 'description'));
            $meta = collect($typed[$id] ?? [])->keyBy(fn (object $f): string => (string) $f->id);
            $attributes = array_map(function (array $a) use ($meta): array {
                $f = $meta[$a['id']] ?? null;

                return $f === null ? $a : [...$a, 'code' => (string) $f->code, 'type' => (string) $f->type, 'unit' => $f->unit === null || $f->unit === '' ? null : (string) $f->unit,
                    'number' => $f->type === 'number' && $f->number_value !== null ? Decimal::trim((string) $f->number_value) : null];
            }, array_merge(...array_map(fn (array $group): array => $group['features'], [...$features[$id] ?? [], ['features' => []]])));
            $group = isset($groups[$id]) ? (string) $groups[$id] : null;
            $products[] = new CatalogProduct($id, $card['name'], $this->plain($description !== '' ? $description : (string) $card['short_description']),
                $card['url'] === null ? null : url($card['url']), array_map(fn (array $image): string => url($image['url']), $images[$id] ?? []), (string) $card['sku'],
                $categories[(string) $record->category_id], $attributes,
                $card['price'] === null ? null : $this->pricing($card, $pricing[$id], $format['code'], $record),
                $this->availability($record, $card, $settings, $availability[$id]), $card['brand_link'] ?? null, $card['rating'] ?? null,
                isset($images[$id][0]) ? array_intersect_key($images[$id][0], ['width' => true, 'height' => true]) : null, $card['discount_percent'] ?? null,
                $group, $group === null ? [] : array_map(fn (object $f): string => (string) $f->feature_id, ($groupFeatures[$group] ?? collect())->all()),
                array_values(array_filter(array_map(fn (object $c): string => (string) $c->companion_id, ($companions[$id] ?? collect())->all()), fn (string $c): bool => isset($public[$c]))),
                $record->ean === null || $record->ean === '' ? null : (string) $record->ean, $this->plain((string) $card['short_description']),
                $record->weight === null ? null : Decimal::trim(Decimal::multiply((string) $record->weight, (string) $settings['grams_per_weight_unit'])));
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array{regular: string, list: ?string}  $amounts
     */
    private function pricing(array $card, array $amounts, string $currency, ProductRecord $record): CatalogPricing
    {
        return new CatalogPricing($currency, $amounts['regular'], $card['price'] !== $amounts['regular'] ? $card['price'] : null, $amounts['list'], $card['base_price'],
            (int) ($record->min_qty ?? $record->qty_step ?? 1));
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $settings
     * @param  array{name: string, bucket: string}  $bucket
     */
    private function availability(ProductRecord $record, array $card, array $settings, array $bucket): CatalogAvailability
    {
        $rules = app(ProductRules::class);
        $from = $record->available_from?->toDateString();
        $from = $from !== null && $from > now()->timezone($settings['timezone'])->toDateString() ? $from : null;

        return new CatalogAvailability(match (true) {
            $from !== null => 'upcoming',
            $bucket['bucket'] === 'in_stock' => 'in_stock',
            $rules->effectiveOutOfStockAction($record->out_of_stock_actions, $settings['out_of_stock_action']) === 'B' => 'backorder',
            default => 'out_of_stock',
        }, $bucket['name'], $from, (bool) $card['available']);
    }

    private function plain(string $html): string
    {
        $spaced = (string) preg_replace('~<(br|/p|/li|/h[1-6]|/div|/tr|/td|/th)\b[^>]*>~i', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
