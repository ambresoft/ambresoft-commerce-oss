<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductAvailability;
use App\Catalog\Infrastructure\ProductPriceHistory;
use App\Catalog\Infrastructure\ProductRatings;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The storefront product page: which product an address shows and everything its main content block displays
 * (pictures, texts, price, availability, variations, features, ratings and the recommendation sections). Only public
 * fields of products the visitor may see are read; prices come from the shared product cards.
 */
final class ProductPage
{
    /** Number of feature values shown as highlights next to the price. */
    private const HIGHLIGHTS = 6;

    /**
     * The product behind a storefront address (its category path plus its slug). Another valid address of the same
     * product (an older language's slug, a moved category) redirects to the current one.
     *
     * @return array{product: string}|array{redirect: string}|null
     */
    public function resolve(string $path, string $locale, ?string $customer): ?array
    {
        $path = '/'.trim($path, '/');
        $slug = rawurldecode(substr($path, strrpos($path, '/') + 1));
        if (substr_count($path, '/') < 2 || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1) {
            return null;
        }
        $ids = DB::table('product_translations')->where('slug', $slug)->distinct()->pluck('product_id')->all();
        $visible = $ids === [] ? collect() : app(PublicCatalog::class)->products($customer)->whereIn('products.id', $ids)->get(['products.id', 'products.category_id']);
        $urls = $this->urls($visible, $locale);
        foreach ($urls as $id => $url) {
            if ($url === $path) {
                return ['product' => (string) $id];
            }
        }

        return count($urls) === 1 ? ['redirect' => (string) reset($urls)] : null;
    }

    /** The product's public storefront address in a language; null while visitors cannot see the product (not active, incomplete, restricted). */
    public function address(string $id, string $locale): ?string
    {
        return $this->addresses([$id], $locale)[$id] ?? null;
    }

    /** @param list<string> $ids
     * @return array<string,string> */
    public function addresses(array $ids, string $locale): array
    {
        $visible = app(PublicCatalog::class)->products(null)->whereIn('products.id', $ids)->get(['products.id', 'products.category_id']);

        return $this->urls($visible, $locale);
    }

    /** @param list<string> $viewed
     * @return array<string,mixed>|null Null when the product is not visible. */
    public function data(string $id, string $locale, ?string $customer, array $viewed = []): ?array
    {
        $context = ['locale' => $locale, 'customer' => $customer];
        $content = app(BlockContent::class);
        ['cards' => $cards, 'format' => $format] = $content->productCards([$id], $context, app(PublicCatalog::class)->products($customer));
        $card = $cards[$id] ?? null;
        $product = ProductRecord::query()->find($id);
        if ($card === null || $product === null) {
            return null;
        }
        $texts = $content->translations('product_translations', 'product_id', [$id])[$id] ?? [];
        $sanitizer = app(RichTextSanitizer::class);
        $settings = app(GeneralSettings::class)->all();
        $availability = app(ProductAvailability::class)->forProduct($product);
        $features = $this->features($id, $locale, $format['decimal']);
        $recommendations = app(ProductRecommendations::class);
        $sections = app(CategoryListing::class)->blockSettings('products', 'main_content');

        $variations = $this->variations($id, $context);
        $brand = $this->brand($product->brand_id === null ? null : (string) $product->brand_id, $locale);

        return [
            ...$card,
            'description' => $sanitizer->sanitize($content->text($texts, $locale, 'description')),
            'short_description' => $sanitizer->sanitize($card['short_description'] ?? ''),
            'currency_format' => $format,
            // What the struck-through reference price saves the visitor (both are already tax-display amounts).
            'brand' => $brand,
            'brand_name' => $brand['name'] ?? null,
            'sale' => $this->sale($product, $customer, $settings['timezone'], $locale),
            'quantity' => ['min' => (int) ($product->min_qty ?? $product->qty_step ?? 1), 'max' => $product->max_qty, 'step' => (int) ($product->qty_step ?? 1)],
            'saving' => $card['price'] !== null && $card['base_price'] !== null ? Decimal::subtract($card['base_price'], $card['price']) : null,
            'images' => $this->images($id),
            'category' => $this->category((string) $product->category_id, $locale),
            'availability' => ['label' => $availability['name'], 'in_stock' => $availability['bucket'] === 'in_stock'],
            'free_shipping' => (bool) $product->free_shipping,
            'returnable' => (bool) $product->returnable,
            'faqs' => app(StorefrontFaqs::class)->for('product', $id, $locale),
            'documents' => $this->documents($id),
            'brand_documents' => $this->brandDocuments($id, $product->brand_id === null ? null : (string) $product->brand_id),
            'price_history' => $settings['hide_product_prices'] ? [] : $this->priceHistory($id, $customer, true),
            'available_from' => $product->available_from !== null && $product->available_from->toDateString() > now()->timezone($settings['timezone'])->toDateString() ? $product->available_from->toDateString() : null,
            // Off for the store or this product: no stars, review list or form on the page (and no rating in its structured data).
            'reviews_enabled' => $settings['reviews_enabled'] && (bool) ($product->reviews_enabled ?? true),
            'rating' => app(ProductRatings::class)->summary($id),
            'features' => $features,
            'highlights' => array_slice(array_merge(...array_map(fn (array $g): array => $g['features'], $features ?: [['features' => []]])), 0, self::HIGHLIGHTS),
            'variations' => $variations['features'],
            'variation_alternatives' => ($sections['show_variation_alternatives'] ?? true) ? array_slice($variations['alternatives'], 0, (int) ($sections['variation_alternatives_limit'] ?? 12)) : [],
            'similar' => $recommendations->similar($id, $context, (int) ($sections['similar_limit'] ?? 12)),
            'newest' => ($sections['show_newest'] ?? true) ? $recommendations->newest($id, ($sections['newest_same_category'] ?? false) ? (string) $product->category_id : null, $context, (int) ($sections['newest_limit'] ?? 12)) : null,
            'recently_viewed' => ($sections['show_recently_viewed'] ?? true) ? $recommendations->carousel(array_slice(array_values(array_diff($viewed, [$id])), 0, (int) ($sections['recently_viewed_limit'] ?? 12)), $context) : null,
            'comparison' => app(ProductComparison::class)->state($id),
            'bought_together' => $recommendations->boughtTogether($id, $context),
            'package' => app(ProductPackage::class)->show($id, $context),
            // A customer reviews a product once; their own review's state replaces the form (published or awaiting approval).
            'review' => ['url' => route('reviews.store', ['product' => $id], false), 'signed_in' => $customer !== null,
                'mine' => $customer === null ? null : match (DB::table('product_reviews')->where('product_id', $id)->where('customer_id', $customer)->value('status')) {
                    null => null, 'approved' => 'published', 'pending' => 'pending', default => 'not_published',
                }],
        ];
    }

    /** @return array{name:string,image:array{url:string,width:int,height:int}|null,image_alt:string,url:?string}|null The product's active brand, with its brand page when it has one. */
    private function brand(?string $id, string $locale): ?array
    {
        return app(StorefrontCache::class)->remember('brands', ['product-brand', $id, $locale], fn () => $this->loadBrand($id, $locale));
    }

    /** @return array{name:string,image:array{url:string,width:int,height:int}|null,image_alt:string,url:?string}|null The product's active brand, with its brand page when it has one. */
    private function loadBrand(?string $id, string $locale): ?array
    {
        $row = $id === null ? null : app(BrandQueries::class)->named($locale)->where('b.id', $id)->where('b.status', 'active')->first();
        if ($row === null || trim((string) $row->name) === '') {
            return null;
        }
        $images = app(PublicMedia::class)->images([$row->image_media_id]);
        $image = app(PublicMedia::class)->url($images[$row->image_media_id] ?? null, 'brand', $id);

        return ['name' => $row->name, 'image' => $image, 'image_alt' => $row->image_alt ?: $row->name, 'url' => app(CatalogLandingPages::class)->brandUrls([$row->code])[$row->code] ?? null];
    }

    /**
     * The sale behind the shown price, when one applies: its optional title and when it ends. Without an end time the
     * last day covers the whole day in the store's time zone. A null end is a sale without an end.
     *
     * @return array{title:string|null,ends_at:string|null}|null
     */
    private function sale(ProductRecord $product, ?string $customer, string $timezone, string $locale): ?array
    {
        $applied = app(ProductSales::class)->price((string) $product->id, (string) $product->price, (int) ($product->min_qty ?? $product->qty_step ?? 1), $customer, app(CurrencyRepository::class)->currentPrimary()->decimals);
        if ($applied === null) {
            return null;
        }
        $sale = DB::table('product_sales')->where('id', $applied['id'])->first(['ends_on', 'ends_time']);
        $titles = $this->texts('product_sale_translations', 'sale_id', [$applied['id']], 'title', $locale);
        $ends = null;
        if ($sale->ends_on !== null) {
            $ends = $sale->ends_time === null
                ? CarbonImmutable::parse($sale->ends_on, $timezone)->addDay()->startOfDay()
                : CarbonImmutable::parse($sale->ends_on.' '.$sale->ends_time, $timezone);
        }

        return ['title' => ($titles[$applied['id']] ?? '') ?: null, 'ends_at' => $ends?->toIso8601String()];
    }

    /** @param Collection<int,\stdClass> $rows
     * @return array<string,string> */
    private function urls(Collection $rows, string $locale): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $paths = app(CategoryUrls::class)->paths($rows->pluck('category_id')->filter()->map(strval(...))->unique()->values()->all(), $locale);
        $codes = DB::table('locales')->pluck('code', 'id');
        $slugs = [];
        foreach (DB::table('product_translations')->whereIn('product_id', $rows->pluck('id'))->get(['product_id', 'locale_id', 'slug']) as $t) {
            $slugs[$t->product_id][$codes[$t->locale_id]] = ['slug' => $t->slug];
        }
        $urls = [];
        foreach ($rows as $row) {
            $slug = app(BlockContent::class)->text($slugs[$row->id] ?? [], $locale, 'slug');
            if (isset($paths[(string) $row->category_id]) && $slug !== '') {
                $urls[(string) $row->id] = $paths[(string) $row->category_id].'/'.rawurlencode($slug);
            }
        }

        return $urls;
    }

    /** @return list<array{url:string,width:int,height:int}> Pictures in the merchant's order, primary first. */
    public function images(string $id): array
    {
        return $this->imagesFor([$id])[$id] ?? [];
    }

    /**
     * Gallery images of many products in one read, primary image first.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{url:string,width:int,height:int}>>
     */
    public function imagesFor(array $ids): array
    {
        return app(StorefrontCache::class)->remember('products', ['gallery', $ids], fn () => $this->loadImagesFor($ids));
    }

    /**
     * Gallery images of many products in one read, primary image first.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{url:string,width:int,height:int}>>
     */
    private function loadImagesFor(array $ids): array
    {
        $rows = DB::table('product_images')->whereIn('product_id', $ids)->orderByDesc('is_primary')->orderBy('position')->get(['product_id', 'media_file_id']);
        $media = app(PublicMedia::class);
        $images = $media->images($rows->pluck('media_file_id')->all());
        $out = [];
        foreach ($rows as $row) {
            $url = $media->url($images[$row->media_file_id] ?? null, 'product', (string) $row->product_id);
            if ($url !== null) {
                $out[(string) $row->product_id][] = $url;
            }
        }

        return $out;
    }

    /** @return array{name:string,url:string|null,path:list<array{name:string,url:string|null}>}|null */
    private function category(string $id, string $locale): ?array
    {
        if ($id === '') {
            return null;
        }
        $ids = app(CategoryHierarchy::class)->pathIds($id);
        $urls = app(CategoryUrls::class)->paths($ids, $locale);
        $names = $this->texts('category_translations', 'category_id', $ids, 'name', $locale);
        $path = array_map(fn (string $c): array => ['name' => $names[$c] ?? '', 'url' => $urls[$c] ?? null], $ids);
        $leaf = end($path) ?: ['name' => '', 'url' => null];

        return ['name' => $leaf['name'], 'url' => $leaf['url'], 'path' => $path];
    }

    /**
     * Feature values grouped like the admin groups them: active features that apply to the product and have a value.
     *
     * @return list<array{name:string,features:list<array{id:string,name:string,value:string}>}>
     */
    public function features(string $id, string $locale, string $decimal): array
    {
        return $this->featuresFor([$id], $locale, $decimal)[$id] ?? [];
    }

    /**
     * The same answer for many products, in one read of each table.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{name:string,features:list<array{id:string,name:string,value:string}>}>>
     */
    public function featuresFor(array $ids, string $locale, string $decimal): array
    {
        return app(StorefrontCache::class)->remember('products', ['features', $ids, $locale, $decimal], fn () => $this->loadFeaturesFor($ids, $locale, $decimal));
    }

    /**
     * The same answer for many products, in one read of each table.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{name:string,features:list<array{id:string,name:string,value:string}>}>>
     */
    private function loadFeaturesFor(array $ids, string $locale, string $decimal): array
    {
        $rows = DB::table('product_feature_assignments as a')->join('features as f', 'f.id', '=', 'a.feature_id')->join('feature_groups as g', 'g.id', '=', 'f.group_id')
            ->whereIn('a.product_id', $ids)->where('a.is_applicable', true)->where('f.status', 'active')
            ->orderBy('g.position')->orderBy('g.id')->orderBy('f.position')->orderBy('f.id')
            ->get(['a.product_id', 'a.id as assignment', 'f.id', 'f.type', 'f.unit', 'f.group_id', 'a.number_value', 'a.boolean_value']);
        if ($rows->isEmpty()) {
            return [];
        }
        $featureNames = $this->texts('feature_translations', 'feature_id', $rows->pluck('id')->all(), 'name', $locale);
        $groupNames = $this->texts('feature_group_translations', 'group_id', $rows->pluck('group_id')->unique()->all(), 'name', $locale);
        $choices = DB::table('product_feature_choices as c')->join('feature_options as o', 'o.id', '=', 'c.option_id')->whereIn('c.assignment_id', $rows->pluck('assignment'))
            ->where('o.status', 'active')->orderBy('o.position')->orderBy('o.id')->get(['c.assignment_id', 'o.id']);
        $labels = $this->texts('feature_option_translations', 'option_id', $choices->pluck('id')->all(), 'label', $locale);
        $values = $this->texts('product_feature_texts', 'assignment_id', $rows->pluck('assignment')->all(), 'value', $locale);
        $groups = [];
        foreach ($rows as $f) {
            $value = match ($f->type) {
                'number' => $f->number_value === null ? '' : trim(str_replace('.', $decimal, Decimal::trim((string) $f->number_value)).' '.(string) $f->unit),
                'boolean' => $f->boolean_value === null ? '' : __($f->boolean_value ? 'storefront.yes' : 'storefront.no', [], $locale),
                'text' => trim($values[$f->assignment] ?? ''),
                default => implode(', ', array_filter($choices->where('assignment_id', $f->assignment)->map(fn ($c) => $labels[$c->id] ?? '')->all())),
            };
            $name = $featureNames[$f->id] ?? '';
            if ($value === '' || $name === '') {
                continue;
            }
            $groups[$f->product_id][$f->group_id] ??= ['name' => $groupNames[$f->group_id] ?? '', 'features' => []];
            $groups[$f->product_id][$f->group_id]['features'][] = ['id' => (string) $f->id, 'name' => $name, 'value' => $value];
        }

        return array_map(array_values(...), $groups);
    }

    /**
     * The variation group's choices (for example colour and memory): every option offered by a visible member, linking
     * to members of the selected colour, preserving other choices where possible. Changing colour may update other choices.
     *
     * @param  array{locale:string,customer:?string}  $context
     * @return array{features:list<array<string,mixed>>,alternatives:list<array<string,mixed>>}
     */
    private function variations(string $id, array $context): array
    {
        $group = DB::table('variation_group_members')->where('product_id', $id)->value('group_id');
        if ($group === null) {
            return ['features' => [], 'alternatives' => []];
        }
        $members = app(PublicCatalog::class)->products($context['customer'])->join('variation_group_members as m', 'm.product_id', '=', 'products.id')
            ->where('m.group_id', $group)->pluck('products.id')->map(strval(...))->all();
        if (count($members) < 2) {
            return ['features' => [], 'alternatives' => []];
        }
        $features = DB::table('variation_group_features as v')->join('features as f', 'f.id', '=', 'v.feature_id')->where('v.group_id', $group)->orderBy('v.position')->get(['f.id', 'f.variation_style']);
        $chosen = [];
        foreach (DB::table('product_feature_choices as c')->join('product_feature_assignments as a', 'a.id', '=', 'c.assignment_id')->whereIn('a.product_id', $members)->whereIn('c.feature_id', $features->pluck('id'))->get(['a.product_id', 'c.feature_id', 'c.option_id']) as $c) {
            $chosen[(string) $c->product_id][(string) $c->feature_id] = (string) $c->option_id;
        }
        $optionIds = array_values(array_unique(array_merge(...array_values(array_map(array_values(...), $chosen ?: [[]])))));
        $options = DB::table('feature_options')->whereIn('id', $optionIds)->orderBy('position')->orderBy('id')->get(['id', 'feature_id', 'swatch_color']);
        $labels = $this->texts('feature_option_translations', 'option_id', $optionIds, 'label', $context['locale']);
        $names = $this->texts('feature_translations', 'feature_id', $features->pluck('id')->all(), 'name', $context['locale']);
        ['cards' => $cards] = app(BlockContent::class)->productCards($members, $context, app(PublicCatalog::class)->products($context['customer']));
        $current = $chosen[$id] ?? [];
        $colours = $features->where('variation_style', 'color');
        $out = [];
        $hasAlternatives = false;
        foreach ($features as $f) {
            $fid = (string) $f->id;
            $list = [];
            foreach ($options->where('feature_id', $f->id) as $o) {
                $oid = (string) $o->id;
                $holders = array_keys(array_filter($chosen, fn (array $c): bool => ($c[$fid] ?? null) === $oid));
                $target = null;
                $bestMatches = -1;
                $bestPreferences = [];
                foreach ($holders as $member) {
                    if (! isset($cards[$member])) {
                        continue;
                    }
                    // Memory/storage never change the selected colour. Colour choices can select another colour.
                    $sameColour = true;
                    foreach ($colours as $colour) {
                        $colourId = (string) $colour->id;
                        if ($colourId !== $fid && ($chosen[$member][$colourId] ?? null) !== ($current[$colourId] ?? null)) {
                            $sameColour = false;
                            break;
                        }
                    }
                    if (! $sameColour) {
                        continue;
                    }
                    $preferences = [];
                    foreach ($features as $other) {
                        $otherId = (string) $other->id;
                        if ($otherId !== $fid) {
                            $preferences[] = (int) (($chosen[$member][$otherId] ?? null) === ($current[$otherId] ?? null));
                        }
                    }
                    $matches = array_sum($preferences);
                    if ($matches > $bestMatches || ($matches === $bestMatches && $preferences > $bestPreferences)) {
                        $target = $member;
                        $bestMatches = $matches;
                        $bestPreferences = $preferences;
                    }
                }
                $hasAlternatives = $hasAlternatives || $bestMatches < $features->count() - 1;
                $compatible = $target !== null;
                // Keep the option visible when it only exists in another colour.
                $preview = $target ?? $holders[0] ?? null;
                if ($preview === null || ! isset($cards[$preview])) {
                    continue;
                }
                $card = $cards[$preview];
                $list[] = ['id' => $oid, 'label' => $labels[$oid] ?? '', 'selected' => ($current[$fid] ?? null) === $oid, 'compatible' => $compatible && $card['url'] !== null, 'url' => $compatible ? $card['url'] : null, 'image' => $card['image'],
                    'price' => $card['price'], 'available' => $card['available'], 'swatch' => $o->swatch_color];
            }
            if (count($list) > 1) {
                $out[] = ['id' => $fid, 'name' => $names[$fid] ?? '', 'style' => $f->variation_style, 'options' => $list];
            }
        }

        $alternatives = [];
        if ($hasAlternatives) {
            foreach ($cards as $member => $card) {
                if ((string) $member !== $id) {
                    $alternatives[] = ['id' => (string) $member, 'name' => $card['name'], 'url' => $card['url'], 'image' => $card['image'], 'price' => $card['price']];
                }
            }
        }

        return ['features' => $out, 'alternatives' => $alternatives];
    }

    /** @return list<array{id:string,name:string,type:string,url:string}> Product documents (attachments) in the merchant's order. */
    private function documents(string $id): array
    {
        return DB::table('product_files as pf')->join('private_product_files as f', 'f.id', '=', 'pf.private_file_id')
            ->where('pf.product_id', $id)->where('pf.purpose', 'attachment')->where('f.ready', true)->orderBy('pf.position')
            ->get(['pf.id', 'pf.display_name', 'f.original_name'])
            ->map(fn (object $f): array => ['id' => (string) $f->id, 'name' => $f->display_name, 'type' => strtolower(pathinfo($f->original_name, PATHINFO_EXTENSION)),
                'url' => route('storefront.product-document', ['product' => $id, 'file' => $f->id], false)])->all();
    }

    /** @return list<array{id:string,name:string,type:string,url:string}> */
    private function brandDocuments(string $product, ?string $brand): array
    {
        if ($brand === null) {
            return [];
        }

        return DB::table('brand_files as bf')->join('brands as b', 'b.id', 'bf.brand_id')->join('private_product_files as f', 'f.id', 'bf.private_file_id')
            ->where('bf.brand_id', $brand)->where('b.status', 'active')->where('f.ready', true)->orderBy('bf.position')->get(['bf.id', 'bf.display_name', 'f.original_name'])
            ->map(fn ($file) => ['id' => (string) $file->id, 'name' => $file->display_name, 'type' => strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)), 'url' => route('storefront.brand-document', ['product' => $product, 'file' => $file->id], false)])->all();
    }

    /**
     * The regular price over time (the merchant's price changes, not temporary sales), shown the way catalog prices are
     * shown: with or without tax like every other storefront price.
     *
     * @return list<array{at:string,price:string}>
     */
    private function priceHistory(string $id, ?string $customer, bool $withTax): array
    {
        $points = app(ProductPriceHistory::class)->series($id);
        if ($points === []) {
            return [];
        }
        $lines = array_map(fn (array $p): TaxLine => new TaxLine($id, $p['price']), $points);
        $context = $customer === null ? null : app(CustomerTaxProfiles::class)->context($customer, 'unit_price');
        $taxes = app(TaxCalculationService::class)->forLines($lines, $context ?? new TaxContext(method: 'unit_price'));

        return array_map(fn (array $p, int $n): array => ['at' => $p['at'], 'price' => $taxes->groups[$taxes->lines[$n]]->display($withTax)], $points, array_keys($points));
    }

    /**
     * One translated column for several owners: the current language, then the default language, then English.
     *
     * @param  list<int|string>  $ids
     * @return array<string,string>
     */
    private function texts(string $table, string $owner, array $ids, string $column, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $codes = DB::table('locales')->pluck('code', 'id');
        $by = [];
        foreach (DB::table($table)->whereIn($owner, $ids)->get([$owner, 'locale_id', $column]) as $r) {
            $by[(string) $r->$owner][$codes[$r->locale_id]] = [$column => $r->$column];
        }
        $out = [];
        foreach ($by as $key => $translations) {
            $out[$key] = app(BlockContent::class)->text($translations, $locale, $column);
        }

        return $out;
    }
}
