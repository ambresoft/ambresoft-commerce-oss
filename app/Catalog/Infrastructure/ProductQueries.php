<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductMapper;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\MediaMetadata;
use App\Pricing\Application\Service\PriceDisplay;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Storefront\Infrastructure\ProductPage;
use App\Tax\Application\Contract\ProductTaxAssignment;
use App\Tax\Infrastructure\TaxQueries;
use App\Website\Infrastructure\FaqLinks;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

final class ProductQueries
{
    public function defaultId(): int
    {
        return app(LocaleCatalog::class)->active()->firstWhere('code', app(LocaleCatalog::class)->defaultCode())->id;
    }

    public function completenessSql(): string
    {
        return "(products.sku IS NOT NULL AND products.price IS NOT NULL AND EXISTS (SELECT 1 FROM product_translations pt WHERE pt.product_id = products.id AND pt.locale_id = ? AND length(btrim(pt.name)) > 0) AND EXISTS (SELECT 1 FROM categories c WHERE c.id = products.category_id AND c.system_key IS NULL AND c.status = 'active' AND NOT EXISTS (SELECT 1 FROM categories child WHERE child.parent_id = c.id)))";
    }

    /**
     * The list filters, shared by the product list and the CSV export so both always agree.
     *
     * @param  EloquentBuilder<ProductRecord>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(EloquentBuilder $query, array $filters, int $default): void
    {
        if (($filters['search'] ?? '') !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->where('sku', 'ilike', $term)->orWhere('ean', 'ilike', $term)->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $term)));
        }
        foreach (['status', 'type', 'brand_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['category_id'])) {
            // Products whose main or extra category it is.
            $query->whereRaw(...ProductCategories::in([$filters['category_id']]));
        }
        if (($filters['variation'] ?? '') !== '') {
            $membership = fn ($q) => $q->selectRaw('1')->from('variation_group_members as m')->whereColumn('m.product_id', 'products.id');
            $filters['variation'] === 'grouped' ? $query->whereExists($membership) : $query->whereNotExists($membership);
        }
        if (($filters['stock_action'] ?? '') !== '') {
            $filters['stock_action'] === 'custom' ? $query->whereNotNull('out_of_stock_actions') : $query->whereNull('out_of_stock_actions');
        }
        if (($filters['incomplete'] ?? '') !== '') {
            $query->whereRaw((($filters['incomplete'] === '1') ? 'NOT ' : '').$this->completenessSql(), [$default]);
        }
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters, bool $trash): array
    {
        $query = $trash ? ProductRecord::onlyTrashed() : ProductRecord::query();
        $default = $this->defaultId();
        $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
        $query->select('products.*')->selectRaw($this->completenessSql().' AS complete', [$default])->selectSub(DB::table('product_stickers')->whereColumn('product_id', 'products.id')->selectRaw('count(*)'), 'sticker_count')->with(['translations' => fn ($q) => $q->whereIn('locale_id', [$current, $default])]);
        $this->applyFilters($query, $filters, $default);
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? 'desc';
        if ($sort === 'name') {
            $query->orderByRaw("COALESCE((SELECT name FROM product_translations WHERE product_id = products.id AND locale_id = ?), (SELECT name FROM product_translations WHERE product_id = products.id AND locale_id = ?), '') ".($direction === 'asc' ? 'ASC' : 'DESC'), [$current, $default]);
        } else {
            $query->orderBy(in_array($sort, ['sku', 'type', 'price', 'quantity', 'status', 'updated_at'], true) ? $sort : 'updated_at', $direction === 'asc' ? 'asc' : 'desc');
        }
        $page = $query->orderBy('products.id', 'desc')->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();
        $paths = app(CategoryQueries::class)->paths($page->getCollection()->pluck('category_id')->filter()->map(strval(...))->unique()->values()->all());
        $images = DB::table('product_images')->whereIn('product_id', $page->getCollection()->pluck('id'))->where('is_primary', true)->pluck('media_file_id', 'product_id');
        $localeCodes = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $prices = app(PriceDisplay::class);
        $converter = $prices->converter();
        $groups = app(VariationGroupQueries::class)->groupsFor($page->getCollection()->pluck('id')->map(strval(...))->all());
        $settings = app(GeneralSettings::class)->all();
        $page->setCollection($page->getCollection()->map(function (ProductRecord $p) use ($current, $default, $paths, $images, $localeCodes, $prices, $converter, $groups, $settings): array {
            $entity = app(ProductMapper::class)->toDomain($p, $localeCodes);
            $name = $entity->translation($localeCodes[$current])?->fields['name'] ?: $entity->translation($localeCodes[$default])?->fields['name'];

            return ['sticker_count' => (int) $p->getAttribute('sticker_count'), 'id' => (string) $p->id, 'name' => $name ?: __('products.untitled', ['id' => $p->id]), 'confirmationName' => $p->translations->firstWhere('locale_id', $default)?->name ?: __('products.untitled', ['id' => $p->id]), 'sku' => $p->sku, 'type' => $p->type, 'price' => $p->price, 'price_formatted' => $p->price === null ? null : $prices->format(Decimal::trim($p->price), null, $converter), 'quantity' => $p->quantity, 'low_stock' => $settings['track_inventory'] && $p->quantity <= $settings['low_stock_threshold'],
                // Reference price is shown only when it is actually above the selling price; it never replaces it.
                'list_price_formatted' => $p->list_price !== null && $p->price !== null && bccomp($p->list_price, $p->price, 6) > 0 ? $prices->format(Decimal::trim($p->list_price), null, $converter) : null, 'status' => $p->status, 'incomplete' => ! $p->complete, 'category' => $paths[(string) $p->category_id] ?? [], 'updated_at' => $p->updated_at->toIso8601String(), 'version' => $p->lock_version, 'image' => isset($images[$p->id]) ? '/admin/product-media/'.$images[$p->id] : null, 'variation_group' => $groups[(string) $p->id] ?? null];
        }));

        return $page->toArray();
    }

    /** @return array<string, mixed> */
    public function editor(ProductRecord $p): array
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('code', 'id');
        $translations = [];
        foreach ($p->translations()->whereIn('locale_id', $locales->keys())->get() as $row) {
            $translations[$locales[$row->locale_id]] = $row->only(ProductInput::TEXT);
        }
        $files = DB::table('product_files as pf')->join('private_product_files as f', 'f.id', '=', 'pf.private_file_id')->where('pf.product_id', $p->id)->orderBy('pf.position')->get(['pf.id', 'pf.display_name', 'pf.position', 'pf.purpose', 'f.original_name', 'f.byte_size'])->map(fn ($f): array => ['id' => (string) $f->id, 'display_name' => $f->display_name, 'original_name' => $f->original_name, 'byte_size' => (int) $f->byte_size, 'purpose' => $f->purpose])->all();
        $images = DB::table('product_images')->where('product_id', $p->id)->orderBy('position')->get();

        // Exact stored primary amount shown at the currency's precision; trailing zeros are never rounded away.
        $price = $p->price === null ? null : Decimal::atLeastScale($p->price, app(PriceDisplay::class)->primary()->decimals);

        $assignedTaxes = app(ProductTaxAssignment::class)->assigned((string) $p->id);

        return ['storefront_url' => app(ProductPage::class)->address((string) $p->id, app()->getLocale()), 'similar_products' => (bool) ($p->similar_products ?? true), 'reviews_enabled' => (bool) ($p->reviews_enabled ?? true), 'exclude_from_sitemap' => (bool) $p->exclude_from_sitemap, 'facebook_type' => $p->facebook_type ?? ProductRules::FACEBOOK_TYPE_DEFAULT, 'companion_ids' => app(ProductCompanions::class)->ids((string) $p->id), 'sticker_ids' => DB::table('product_stickers')->where('product_id', $p->id)->pluck('sticker_id')->map(strval(...))->all(), 'sales' => app(ProductSales::class)->editor((string) $p->id), 'in_stock_status_id' => $p->in_stock_status_id === null ? null : (string) $p->in_stock_status_id, 'out_of_stock_status_id' => $p->out_of_stock_status_id === null ? null : (string) $p->out_of_stock_status_id, 'availability' => app(ProductAvailability::class)->forProduct($p), 'faq_ids' => app(FaqLinks::class)->assigned('product', (string) $p->id), 'tax_ids' => $assignedTaxes, 'taxes' => app(TaxQueries::class)->productOptions($assignedTaxes), 'brand_id' => $p->brand_id === null ? '' : (string) $p->brand_id, ...$p->only(['type', 'sku', 'ean', 'mpn', 'isbn', 'upc', 'returnable', 'category_id', 'weight', 'length', 'width', 'height', 'status', 'quantity', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions']), 'general_out_of_stock_action' => app(GeneralSettings::class)->outOfStockAction(), 'price' => $price, 'list_price' => $p->list_price === null ? null : Decimal::atLeastScale($p->list_price, app(PriceDisplay::class)->primary()->decimals), ...$p->only(['free_shipping', 'box_min_items', 'box_max_items', 'box_length', 'box_width', 'box_height']), 'shipping_cost' => $p->shipping_cost === null ? null : Decimal::atLeastScale($p->shipping_cost, app(PriceDisplay::class)->primary()->decimals), 'available_from' => $p->available_from?->toDateString(), 'customer_groups' => app(CatalogCustomerGroups::class)->assigned('product', (string) $p->id), 'extra_category_ids' => app(ProductCategories::class)->extras((string) $p->id), 'id' => (string) $p->id, 'category_id' => $p->category_id === null ? '' : (string) $p->category_id, 'version' => $p->lock_version, 'translations' => (object) $translations, 'incomplete' => app(ProductState::class)->requiredErrors($p) !== [], 'images' => $images->pluck('media_file_id')->map(strval(...))->all(), 'image_meta' => (object) app(MediaMetadata::class)->for($images->pluck('media_file_id')->map(strval(...))->all()), 'primary_image' => ($primary = $images->firstWhere('is_primary', true)) ? (string) $primary->media_file_id : '', 'files' => $files];
    }

    /**
     * Read-only display of the saved primary price in each active secondary currency; never submitted back.
     *
     * @return list<array{code: string, formatted: string}>
     */
    public function conversions(ProductRecord $p): array
    {
        if ($p->price === null) {
            return [];
        }
        $prices = app(PriceDisplay::class);
        $converter = $prices->converter();
        $amount = Decimal::trim($p->price);

        return array_values(array_map(fn ($c): array => ['code' => $c->code, 'formatted' => $prices->format($amount, $c->code, $converter)], array_filter($converter->active(), fn ($c): bool => ! $c->primary)));
    }

    /** @return list<array{id: string, name: string}> */
    public function categories(string $search = '', ?string $selected = null): array
    {
        $query = CategoryRecord::whereNull('system_key')->where('status', 'active')->doesntHave('children');
        if ($search !== '') {
            $query->whereHas('translations', fn ($q) => $q->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%'));
        }
        $ids = $query->orderBy('id')->limit(50)->pluck('id')->map(strval(...))->all();
        if ($selected !== null) {
            $ids[] = $selected;
        }
        $paths = app(CategoryQueries::class)->paths(array_unique($ids));

        return array_map(fn ($id): array => ['id' => (string) $id, 'name' => implode(' → ', $paths[$id] ?? [])], array_values(array_unique($ids)));
    }

    /**
     * Choices for a product's extra categories: any real category (parents too), matching the search, plus the chosen ones.
     *
     * @param  list<string>  $selected
     * @return list<array{id: string, name: string}>
     */
    public function extraCategories(string $search = '', array $selected = []): array
    {
        $query = CategoryRecord::whereNull('system_key')->where('status', 'active');
        if ($search !== '') {
            $query->whereHas('translations', fn ($q) => $q->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%'));
        }
        $ids = array_values(array_unique([...$selected, ...$query->orderBy('id')->limit(50)->pluck('id')->map(strval(...))->all()]));
        $paths = app(CategoryQueries::class)->paths($ids);

        return array_map(fn (string $id): array => ['id' => $id, 'name' => implode(' → ', $paths[$id] ?? ['#'.$id])], $ids);
    }
}
