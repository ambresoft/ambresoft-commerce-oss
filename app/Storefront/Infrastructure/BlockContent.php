<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\ProductCategories;
use App\Catalog\Infrastructure\ProductRatings;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Customer\Infrastructure\Wishlists;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\BannerRules;
use App\Marketing\Infrastructure\BannerTitleSanitizer;
use App\Marketing\Infrastructure\StickerProducts;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Search\Infrastructure\SearchSettings;
use App\Search\Infrastructure\SearchTerms;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxLine;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Dynamic shopping content stays live; reusable public content uses the opt-in storefront cache. */
final class BlockContent
{
    /** Presentation settings of product carousels that travel with their payload. */
    public const PRODUCT_DISPLAY = ['per_row', 'show_price', 'show_add_to_cart', 'show_sku', 'show_availability', 'show_rating', 'show_brand', 'show_category', 'show_variations', 'show_favorites', 'card_shadow', 'autoscroll', 'drag_scroll', 'infinite_scroll', 'navigation', 'hide_arrows_mobile', 'image_width', 'image_height', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'];

    private ?string $defaultLocale = null;

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>,exclude_product?:string} $context
     * @return array<string,array<string,mixed>> */
    public function resolve(string $type, array $blocks, array $context): array
    {
        if ($context['customer'] === null && in_array($type, ['html', 'topbar', 'categories'], true)) {
            return app(StorefrontCache::class)->remember('blocks', ['content', $type, $blocks, $context['locale']], fn () => $this->resolveLive($type, $blocks, $context));
        }

        return $this->resolveLive($type, $blocks, $context);
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>,exclude_product?:string} $context
     * @return array<string,array<string,mixed>> */
    private function resolveLive(string $type, array $blocks, array $context): array
    {
        if (in_array($type, ['coupons', 'offers'], true)) {
            return app(StorefrontDeals::class)->resolve($type, $blocks, $context);
        }
        if ($type === 'reels') {
            return app(StorefrontReels::class)->resolve($blocks, $context);
        }
        if ($type === 'products') {
            return $this->products($blocks, $context);
        }
        // Product listing categories: both blocks read the same validated filters and counts (CategoryListing::prepare()).
        if ($type === 'product_filters') {
            return array_filter(array_map(fn (array $b): array => isset($context['main']['listing']) ? app(CategoryListing::class)->filters($context['main']['listing'], $b['settings']) : [], $blocks));
        }
        // A brand page's heading (picture, name, description) above its filters and products.
        if ($type === 'page_header') {
            return array_filter(array_map(fn (array $b): array => isset($context['main']['listing']['page']['brand']) ? ['brand_page' => $context['main']['listing']['page']['brand']] : [], $blocks));
        }
        if ($type === 'main_content' && isset($context['main']['listing'])) {
            return array_map(fn (array $b): array => ['listing' => app(CategoryListing::class)->results($context['main']['listing'], $b['settings'], $context)], $blocks);
        }
        if ($type === 'main_content') {
            // The page's own content, prepared by its controller; without it (another page) the block shows nothing.
            // The page's controller names what the block shows (['product' => …], ['category' => …], ['wishlist' => …], ['account' => …] or ['comparison' => …]).
            return array_map(fn (array $b): array => isset($context['main']) ? [...$context['main'], ...(isset($context['main']['product']) ? ['display' => ['hide_arrows_mobile' => (bool) ($b['settings']['hide_arrows_mobile'] ?? false)]] : []), ...(isset($context['main']['checkout']['quote']) ? ['checkout_options' => app(CheckoutOptions::class)->forBlock($b, $context['locale'], $context['customer'])] : []), 'section_order' => $b['settings']['section_order'] ?? FirstPartyBlock::PRODUCT_SECTION_ORDER, 'sections' => array_intersect_key($b['settings'], array_flip(FirstPartyBlock::MAIN_SECTIONS)), 'carousel_per_row' => array_combine(FirstPartyBlock::PRODUCT_CAROUSELS, array_map(fn (string $carousel): int => (int) ($b['settings'][$carousel.'_per_row'] ?? 5), FirstPartyBlock::PRODUCT_CAROUSELS)), 'per_row' => (int) ($b['settings']['per_row'] ?? 5),
                'card_shadow' => (bool) ($b['settings']['card_shadow'] ?? true), 'mobile_display' => (string) ($b['settings']['mobile_display'] ?? 'grid'), 'mobile_per_row' => (int) ($b['settings']['mobile_per_row'] ?? 2)] : [], $blocks);
        }
        if ($type === 'banners') {
            return $this->banners($blocks, $context);
        }
        if ($type === 'categories') {
            return $this->categories($blocks, $context);
        }
        if ($type === 'blog_posts') {
            return $this->blogPosts($blocks, $context);
        }
        if ($type === 'menu') {
            return $this->menus($blocks, $context);
        }
        if ($type === 'visual_menu') {
            return $this->visualMenus($blocks, $context);
        }
        if ($type === 'newsletter') {
            return $this->newsletters($blocks);
        }
        if ($type === 'footer') {
            return $this->footers($blocks, $context);
        }
        if ($type === 'link_directory') {
            return $this->linkDirectories($blocks, $context);
        }
        $result = [];
        foreach ($blocks as $id => $b) {
            if ($type === 'html') {
                $html = app(RichTextSanitizer::class)->sanitize($this->text($b['translations'], $context['locale'], 'html'));
                if (trim(strip_tags($html)) !== '') {
                    $result[$id] = ['html' => $html];
                }
            } elseif ($type === 'popular_searches') {
                // Links to search results lead nowhere without a search add-on.
                $terms = app(SearchSettings::class)->provider() === null ? [] : (($b['settings']['source'] ?? 'popular') === 'manual'
                    ? array_slice(app(SearchTerms::class)->picked($b['selections']['items'] ?? []), 0, (int) $b['settings']['limit'])
                    : app(SearchTerms::class)->popular((int) $b['settings']['limit']));
                if ($terms !== []) {
                    $result[$id] = ['popular_searches' => array_map(fn (string $term): array => ['term' => $term, 'url' => '/search?q='.rawurlencode($term)], $terms)];
                }
            } elseif ($type === 'checkout_header') {
                $company = DB::table('company_settings')->where('id', 1)->first();
                // The header is light: the e-mail logo (made for white backgrounds) when there is one, else the store logo.
                $logo = $company->logo_email_media_id ?? $company->logo_media_id ?? null;
                $images = app(PublicMedia::class)->images([$logo]);
                $result[$id] = ['checkout_header' => ['name' => $company?->name ?: config('app.name'), 'logo' => app(PublicMedia::class)->url($images[$logo] ?? null, 'company', '1'),
                    'background_color' => $b['settings']['background_color'] ?: '#ffffff', 'text_color' => $b['settings']['text_color'] ?: null]];
            } elseif ($type === 'identity') {
                $company = DB::table('company_settings')->where('id', 1)->first();
                $images = app(PublicMedia::class)->images([$company?->logo_media_id, $company?->logo_mobile_media_id]);
                // The header follows the store colors unless the block overrides them.
                $result[$id] = ['name' => $company?->name ?: config('app.name'), 'logo' => app(PublicMedia::class)->url($images[$company?->logo_media_id] ?? null, 'company', '1'), 'mobile_logo' => app(PublicMedia::class)->url($images[$company?->logo_mobile_media_id] ?? null, 'company', '1'), 'call_request_form' => ($b['settings']['show_call_request'] ?? false) ? $b['id'] : null, 'account_signed_in' => Auth::check(),
                    // Compact and Fashion bring their selected navigation menu; all styles share the language switcher.
                    ...(($b['settings']['header_style'] ?? 'classic') !== 'classic' ? [
                        'drawer' => isset($b['selections']['drawer_menu'][0]) ? (app(PublicMenus::class)->trees([$b['selections']['drawer_menu'][0]], $context['locale'], $context['customer'])[$b['selections']['drawer_menu'][0]] ?? []) : [],
                        'mobile_links' => $b['translations'][$context['locale']]['mobile_links'] ?? $b['translations'][app(LocaleCatalog::class)->defaultCode()]['mobile_links'] ?? MobileMenuLinks::defaults($context['locale']),
                    ] : []),
                    'languages' => ($b['settings']['show_language_switcher'] ?? true) ? $this->languages($context['locale']) : [],
                    'display' => [...array_intersect_key($b['settings'], array_flip(['header_style', 'icon_order', 'show_logo', 'show_search', 'show_account', 'show_call_request', 'show_favorites', 'show_cart', 'show_mini_cart', 'search_suggestions', 'show_language_switcher', 'show_mobile_account_links'])), 'full_bleed' => true,
                        // The search field appears only while a search add-on serves the store.
                        'show_search' => ($b['settings']['show_search'] ?? true) && app(SearchSettings::class)->provider() !== null,
                        'search_min_length' => app(ProductSearch::class)->minimum(),
                        // The search suggestions open below the header.
                        'allow_overflow' => true,
                        'background_color' => $b['settings']['background_color'] ?: (($b['settings']['header_style'] ?? 'classic') === 'fashion' ? '#ffffff' : 'var(--sf-primary)'), 'text_color' => $b['settings']['text_color'] ?: (($b['settings']['header_style'] ?? 'classic') === 'fashion' ? '#333333' : 'var(--sf-on-primary)'), 'icon_color' => $b['settings']['icon_color'] ?? '']];
            } elseif ($type === 'topbar') {
                $sanitizer = app(RichTextSanitizer::class);
                $left = $sanitizer->sanitize($this->text($b['translations'], $context['locale'], 'html'));
                $right = $b['settings']['layout'] === 'split' ? $sanitizer->sanitize($this->text($b['translations'], $context['locale'], 'html_right')) : '';
                if (trim(strip_tags($left.$right)) !== '') {
                    $result[$id] = ['html' => $left, 'html_right' => $right,
                        'display' => [...array_intersect_key($b['settings'], array_flip(['layout', 'font_size_desktop', 'font_size_tablet', 'font_size_mobile'])), 'full_bleed' => true,
                            'background_color' => $b['settings']['background_color'] ?: '#ffffff', 'text_color' => $b['settings']['text_color'] ?: 'var(--sf-text)']];
                }
            }
        }

        return $result;
    }

    /** @param array<string,array<string,mixed>> $translations */
    public function text(array $translations, string $locale, string $field): string
    {
        foreach (array_unique([$locale, ($this->defaultLocale ??= app(LocaleCatalog::class)->defaultCode()), 'en']) as $code) {
            if (trim((string) ($translations[$code][$field] ?? '')) !== '') {
                return (string) $translations[$code][$field];
            }
        }

        return '';
    }

    /** @param list<int|string> $ids
     * @return array<int|string,array<string,array<string,mixed>>> */
    public function translations(string $table, string $owner, array $ids): array
    {
        $group = match ($table) {
            'product_translations' => 'products',
            'category_translations' => 'categories',
            'brand_translations' => 'brands',
            default => null,
        };
        if ($group === null) {
            return $this->loadTranslations($table, $owner, $ids);
        }

        return app(StorefrontCache::class)->remember($group, ['translations', $table, $owner, $ids], fn () => $this->loadTranslations($table, $owner, $ids));
    }

    /** @param list<int|string> $ids
     * @return array<int|string,array<string,array<string,mixed>>> */
    private function loadTranslations(string $table, string $owner, array $ids): array
    {
        $codes = DB::table('locales')->pluck('code', 'id');
        $out = [];
        foreach (DB::table($table)->whereIn($owner, $ids)->get() as $r) {
            $out[$r->$owner][$codes[$r->locale_id]] = (array) $r;
        }

        return $out;
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>,exclude_product?:string} $context
     * @param array<string,string> $brandIds Optional brand constraint per block; manual and automatic filling both enforce it.
     * @return array<string,array<string,mixed>> */
    public function products(array $blocks, array $context, array $brandIds = []): array
    {
        $base = app(PublicCatalog::class)->listed($context['customer'], 'carousels');
        if (isset($context['exclude_product'])) {
            $base->where('products.id', '!=', $context['exclude_product']);
        }
        $preservedProducts = [];
        $explicitGroups = [];
        $selections = [];
        $auto = [];
        $scopes = [];
        foreach ($blocks as $id => $b) {
            $source = $b['settings']['source'];
            $limit = str_ends_with($b['template'], '_single') ? 1 : (int) $b['settings']['limit'];
            if ($source === 'manual') {
                $selections[$id] = array_slice($b['selections']['items'] ?? [], 0, $limit);
                if ($b['settings']['preserve_manual_products'] ?? true) {
                    $preservedProducts[$id] = array_map(strval(...), $selections[$id]);
                }
                $groups = array_map(strval(...), $b['selections']['variation_groups'] ?? []);
                if ($groups !== []) {
                    $resolved = array_values(array_unique(array_filter(app(VariationListings::class)->representatives($context['customer'], 'carousels', $groups))));
                    $explicitGroups[$id] = $resolved;
                    $selections[$id] = array_slice(array_values(array_unique([...$selections[$id], ...$resolved])), 0, $limit);
                }

                continue;
            }
            // Blocks that rank the same way over the same categories share one query.
            $categories = array_values(array_unique($b['selections']['filter_categories'] ?? []));
            sort($categories);
            $scopes[$id] = $source.'|'.implode(',', $categories);
            $auto[$scopes[$id]] = ['source' => $source, 'categories' => $categories, 'limit' => max($auto[$scopes[$id]]['limit'] ?? 0, $limit)];
        }
        $filled = [];
        $viewed = null;
        $newDays = (int) app(GeneralSettings::class)->all()['new_products_days'];
        foreach ($auto as $scope => ['source' => $source, 'categories' => $categories, 'limit' => $limit]) {
            $q = clone $base;
            if ($brandIds !== []) {
                $q->whereIn('products.brand_id', array_unique(array_values($brandIds)));
            }
            if ($categories !== []) {
                // A category stands for its whole subtree; no category means every category.
                $hierarchy = app(CategoryHierarchy::class);
                $q->whereRaw(...ProductCategories::in(array_values(array_unique(array_merge([], ...array_map($hierarchy->descendantIds(...), $categories))))));
            }
            if ($source === 'recently_viewed') {
                $viewed ??= app(RecentlyViewedProducts::class)->ids(request());
                $eligible = (clone $q)->whereIn('products.id', $viewed)->pluck('products.brand_id', 'products.id');
                // Filter first, keeping the visitor's view order; the block limit is applied below.
                foreach ($viewed as $product) {
                    if ($eligible->has($product)) {
                        $key = $scope.($brandIds === [] ? '' : ':'.$eligible[$product]);
                        $filled[$key][] = $product;
                    }
                }

                continue;
            }
            if ($source === 'bestsellers') {
                $q->joinSub(app(Bestsellers::class)->productUnitTotals(), 'sales', 'sales.product_id', '=', 'products.id')->orderByDesc('sales.units');
            } else {
                if ($source === 'newest') {
                    $q->where('products.created_at', '>=', now()->subDays($newDays));
                }
                $q->orderByDesc('products.created_at');
            }
            $q->orderByDesc('products.id');
            if ($source === 'sale') {
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('product_sales')->whereColumn('product_id', 'products.id')->where('is_enabled', true));
                $filled[$scope] = [];
                // Batch candidates and use authoritative pricing, including group/date/quantity/quota rules.
                for ($offset = 0; $this->needsProducts($filled, $scope, $limit, $brandIds); $offset += 100) {
                    $rows = (clone $q)->offset($offset)->limit(100)->get(['products.*']);
                    if ($rows->isEmpty()) {
                        break;
                    }
                    $sales = $this->prices($rows->all(), $context['customer']);
                    foreach ($rows as $p) {
                        if (isset($sales[$p->id]) && Decimal::compare($sales[$p->id]['price'], $p->price) < 0) {
                            $key = $brandIds === [] ? $scope : $scope.':'.$p->brand_id;
                            if (count($filled[$key] ?? []) < $limit) {
                                $filled[$key][] = (string) $p->id;
                            }
                        }
                    }
                    if ($rows->count() < 100) {
                        break;
                    }
                }
            } elseif ($brandIds !== []) {
                // One bounded ranked query for every brand, not one query for each brand block.
                $order = $source === 'bestsellers' ? 'sales.units desc, products.id desc' : 'products.created_at desc, products.id desc';
                $ranked = $q->reorder()->selectRaw('products.id, products.brand_id, row_number() over (partition by products.brand_id order by '.$order.') as rank');
                foreach (DB::query()->fromSub($ranked, 'ranked')->where('rank', '<=', $limit)->orderBy('brand_id')->orderBy('rank')->get() as $row) {
                    $filled[$scope.':'.$row->brand_id][] = (string) $row->id;
                }
            } else {
                $filled[$scope] = $q->limit($limit)->pluck('products.id')->map(strval(...))->all();
            }
        }
        foreach ($blocks as $id => $b) {
            $key = ($scopes[$id] ?? '').(isset($brandIds[$id]) ? ':'.$brandIds[$id] : '');
            $selections[$id] ??= array_slice($filled[$key] ?? [], 0, str_ends_with($b['template'], '_single') ? 1 : (int) $b['settings']['limit']);
        }
        $representatives = app(VariationListings::class)->representatives($context['customer'], 'carousels');
        foreach ($selections as $block => &$selection) {
            $preserved = $preservedProducts[$block] ?? [];
            $selection = array_values(array_unique(array_filter(array_map(
                fn ($id) => in_array((string) $id, $preserved, true) ? $id : (array_key_exists($id, $representatives) ? $representatives[$id] : $id),
                $selection,
            ))));
        }
        unset($selection);
        // Manual identities keep publication and stock-listing checks without automatic grouping.
        $cardScope = app(PublicCatalog::class)->listed($context['customer'], null);
        if (isset($context['exclude_product'])) {
            $cardScope->where('products.id', '!=', $context['exclude_product']);
        }
        $ids = array_values(array_unique(array_merge([], ...array_values($selections))));
        if ($brandIds !== []) {
            $ownership = (clone $cardScope)->whereIn('products.id', $ids)->pluck('products.brand_id', 'products.id');
            foreach ($selections as $id => &$selection) {
                $selection = array_values(array_filter($selection, fn ($product) => (string) ($ownership[$product] ?? '') === (string) $brandIds[$id]));
            }
            unset($selection);
            $ids = array_values(array_unique(array_merge([], ...array_values($selections))));
        }
        ['cards' => $cards, 'format' => $format] = $this->productCards($ids, $context, $cardScope, 'carousels');
        if (array_filter($blocks, fn (array $b): bool => (bool) ($b['settings']['show_variations'] ?? false)) !== []) {
            $cards = $this->withVariations($cards, $context);
        }
        if (array_filter($blocks, fn (array $b): bool => (bool) ($b['settings']['show_brand'] ?? false)) !== []) {
            $cards = $this->withBrands($cards, $context['locale']);
        }
        $out = $this->selected($selections, $cards);
        if ($preservedProducts !== []) {
            $preservedIds = array_values(array_unique(array_merge([], ...array_values($preservedProducts))));
            $productNames = $this->translations('product_translations', 'product_id', $preservedIds);
            foreach ($preservedProducts as $block => $members) {
                if (! isset($out[$block])) {
                    continue;
                }
                foreach ($out[$block]['items'] as &$item) {
                    if (in_array($item['id'], $members, true)) {
                        $item['name'] = $this->text($productNames[$item['id']] ?? [], $context['locale'], 'name');
                        $item['price_from'] = false;
                    }
                }
                unset($item);
            }
        }

        if ($explicitGroups !== []) {
            $explicitIds = array_values(array_unique(array_merge([], ...array_values($explicitGroups))));
            $groupCards = $this->productCards($explicitIds, $context, $cardScope, 'carousels', $explicitIds)['cards'];
            foreach ($explicitGroups as $block => $members) {
                if (isset($out[$block])) {
                    foreach ($out[$block]['items'] as &$item) {
                        if (in_array($item['id'], $members, true) && isset($groupCards[$item['id']])) {
                            $item['name'] = $groupCards[$item['id']]['name'];
                            $item['price_from'] = true;
                        }
                    }
                    unset($item);
                }
            }
        }

        $images = DB::table('media_files')->whereIn('id', array_filter([...array_column($blocks, 'image_media_id'), ...array_column($blocks, 'image_mobile_media_id')]))->get(['id', 'width', 'height'])->keyBy('id');
        $media = fn (object $image, string $block): array => app(PublicMedia::class)->url(['id' => (string) $image->id, 'width' => (int) $image->width, 'height' => (int) $image->height], 'block', $block);
        foreach ($out as $id => &$payload) {
            $payload['currency_format'] = $format;
            $image = $images[$blocks[$id]['image_media_id'] ?? 0] ?? null;
            if ($image !== null && ! str_ends_with($blocks[$id]['template'], '_single')) {
                $mobile = $images[$blocks[$id]['image_mobile_media_id'] ?? 0] ?? null;
                // Phones get their own picture when there is one, else the desktop picture.
                $payload['side_image'] = [...$media($image, (string) $id), 'mobile' => $mobile === null ? null : $media($mobile, (string) $id),
                    'side' => (string) ($blocks[$id]['settings']['image_side'] ?? 'left'), 'fit' => (string) ($blocks[$id]['settings']['image_fit'] ?? 'contain'), 'fit_mobile' => (string) ($blocks[$id]['settings']['image_fit_mobile'] ?? 'contain'),
                    'link' => MenuRules::safeUrl((string) ($blocks[$id]['settings']['image_link'] ?? '')) ? (string) $blocks[$id]['settings']['image_link'] : null];
            }
        }
        unset($payload);

        return $this->display($blocks, $out, self::PRODUCT_DISPLAY, $context['locale']);
    }

    /** @param array<string,list<string>> $filled
     * @param array<string,string> $brands */
    private function needsProducts(array $filled, string $scope, int $limit, array $brands): bool
    {
        foreach ($brands === [] ? [''] : array_unique(array_values($brands)) as $brand) {
            if (count($filled[$scope.($brand === '' ? '' : ':'.$brand)] ?? []) < $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Storefront product cards (name, link, picture, stickers, live price with tax display, reference price and
     * availability) for the given ids that the scope still allows, plus the money format. Shared by product blocks
     * and the product recommendations.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $explicitGroups
     * @param  array{locale:string,customer:?string}  $context
     * @return array{cards: array<string,array<string,mixed>>, format: array<string,mixed>, pricing: array<string,array{regular:string,list:?string}>}
     */
    public function productCards(array $ids, array $context, Builder $scope, ?string $surface = null, array $explicitGroups = []): array
    {
        $base = $scope;
        $rows = (clone $base)->whereIn('products.id', $ids)->get(['products.*']);
        $translations = $this->translations('product_translations', 'product_id', $ids);
        $primary = DB::table('product_images')->whereIn('product_id', $ids)->where('is_primary', true)->pluck('media_file_id', 'product_id');
        $images = app(PublicMedia::class)->images($primary->values()->all());
        $prices = $this->prices($rows->all(), $context['customer']);
        $settings = app(GeneralSettings::class)->all();
        $currency = app(CurrencyRepository::class)->currentPrimary();
        $lines = [];
        foreach ($rows as $p) {
            $lines[] = new TaxLine((string) $p->id, $prices[$p->id]['price'] ?? $p->price);
            $lines[] = new TaxLine((string) $p->id, $p->price);
            $lines[] = new TaxLine((string) $p->id, $p->list_price ?? $p->price);
        }
        $taxContext = $context['customer'] === null ? null : app(CustomerTaxProfiles::class)->context($context['customer'], 'unit_price');
        $taxes = app(TaxCalculationService::class)->forLines($lines, $taxContext ?? new TaxContext(method: 'unit_price'));
        $categoryIds = $rows->pluck('category_id')->unique()->map(strval(...))->values()->all();
        $categoryPaths = app(CategoryUrls::class)->paths($categoryIds, $context['locale']);
        $categoryTexts = $categoryIds === [] ? [] : $this->translations('category_translations', 'category_id', $categoryIds);
        $stickers = app(StickerProducts::class)->forProducts($rows->pluck('id')->map(strval(...))->all(), $context['locale'], 'card', $context['customer']);
        // Approved-review summaries for every card in one grouped query (shown when the block asks for them).
        $ratings = app(ProductRatings::class)->summaries($rows->pluck('id')->map(strval(...))->all());
        // Hearts: which cards are in the signed-in customer's general wishlist (one query).
        $saved = $context['customer'] === null || ! $settings['wishlist_enabled'] ? [] : array_flip(app(Wishlists::class)->favourites((string) $context['customer'], $rows->pluck('id')->map(strval(...))->all()));
        $cards = [];
        $pricing = [];
        $grouped = $surface === null ? [] : DB::table('variation_group_members as m')->join('variation_groups as g', 'g.id', '=', 'm.group_id')
            ->where(fn ($q) => $q->where('g.'.match ($surface) {
                'carousels' => 'single_carousel', 'search' => 'single_search', default => 'single_listing'
            }, true)->orWhereIn('m.product_id', $explicitGroups))
            ->whereIn('m.product_id', $ids)->pluck('g.id', 'm.product_id')->all();
        $groupNames = $grouped === [] ? [] : $this->translations('variation_group_translations', 'group_id', array_map(strval(...), array_values(array_unique($grouped))));
        // Carousel presentation is deliberately unchanged; these controls apply to filtered listing cards.
        $cardGroups = in_array($surface, ['lists', 'search'], true) ? array_map(strval(...), array_values(array_unique($grouped))) : [];
        $cardFeatures = app(VariationListings::class)->cardFeatures($cardGroups, $context['locale'], $context['customer'], array_map(strval(...), $ids));
        $viewGroups = $cardGroups === [] ? [] : DB::table('variation_groups')->whereIn('id', $cardGroups)->where('view_product', true)->pluck('id')->map(strval(...))->all();
        foreach ($rows as $index => $p) {
            $name = $this->text($translations[$p->id] ?? [], $context['locale'], 'name');
            if ($name === '') {
                continue;
            }
            $rules = app(ProductRules::class);
            $quantity = (int) ($p->min_qty ?? $p->qty_step ?? 1);
            $available = ($p->available_from === null || now()->timezone($settings['timezone'])->toDateString() >= $p->available_from) && $rules->canPurchase($quantity, (int) $p->quantity, $rules->effectiveOutOfStockAction($p->out_of_stock_actions, $settings['out_of_stock_action']), $p->min_qty, $p->max_qty, $p->qty_step, $settings['track_inventory']);
            $price = $taxes->groups[$taxes->lines[$index * 3]]->display(true);
            $basePrice = $taxes->groups[$taxes->lines[$index * 3 + 1]]->display(true);
            $netPrice = $taxes->groups[$taxes->lines[$index * 3]]->display(false);
            // The struck-through reference is the highest of the regular price and the merchant's list price.
            $listPrice = $taxes->groups[$taxes->lines[$index * 3 + 2]]->display(true);
            $pricing[(string) $p->id] = ['regular' => $basePrice, 'list' => $p->list_price === null ? null : $listPrice];
            $basePrice = Decimal::compare($listPrice, $basePrice) > 0 ? $listPrice : $basePrice;
            $categoryName = $this->text($categoryTexts[$p->category_id] ?? [], $context['locale'], 'name');
            $cards[(string) $p->id] = ['id' => (string) $p->id, 'view_product' => isset($grouped[$p->id]) && in_array((string) $grouped[$p->id], $viewGroups, true), 'variation_features' => $cardFeatures[$grouped[$p->id] ?? ''] ?? [], 'price_from' => isset($grouped[$p->id]), 'name' => isset($grouped[$p->id]) ? ($this->text($groupNames[$grouped[$p->id]] ?? [], $context['locale'], 'name') ?: $name) : $name, 'sku' => $p->sku, 'category' => $categoryName === '' ? null : ['name' => $categoryName, 'url' => $categoryPaths[(string) $p->category_id] ?? null], 'favorite' => isset($saved[(string) $p->id]), 'rating' => $settings['reviews_enabled'] && $p->reviews_enabled && ($ratings[(string) $p->id]['count'] ?? 0) > 0 ? ['average' => $ratings[(string) $p->id]['average'], 'count' => $ratings[(string) $p->id]['count']] : null, 'short_description' => $this->text($translations[$p->id] ?? [], $context['locale'], 'short_description'), 'url' => $this->productUrl($categoryPaths[(string) $p->category_id] ?? null, $this->text($translations[$p->id] ?? [], $context['locale'], 'slug')), 'stickers' => $this->stickers($stickers[$p->id] ?? []), 'image' => app(PublicMedia::class)->url($images[$primary[$p->id] ?? 0] ?? null, 'product', (string) $p->id), 'price' => $settings['hide_product_prices'] ? null : $price, 'price_without_tax' => $settings['hide_product_prices'] || ! $settings['show_prices_without_tax'] || Decimal::compare($netPrice, $price) === 0 ? null : $netPrice, 'base_price' => $settings['hide_product_prices'] || Decimal::compare($price, $basePrice) >= 0 ? null : $basePrice,
                // Whole-percent reduction from the reference price (shown as a badge by the showcase card).
                'discount_percent' => $settings['hide_product_prices'] || Decimal::compare($price, $basePrice) >= 0 || Decimal::compare($basePrice, '0') <= 0 ? null : (int) Decimal::round(Decimal::multiply(Decimal::divide(Decimal::subtract($basePrice, $price), $basePrice, 6), '100'), 0), 'currency' => $currency->code, 'available' => $available];
        }

        return ['cards' => $cards, 'format' => $this->currencyFormat(), 'pricing' => $pricing];
    }

    /**
     * Adds each card's variation group as small pictures (the members the visitor can see, the card's own included),
     * for cards whose block shows variations. Products outside a group, or alone in it, get none.
     *
     * @param  array<string,array<string,mixed>>  $cards
     * @param  array{locale:string,customer:?string}  $context
     * @return array<string,array<string,mixed>>
     */
    public function withVariations(array $cards, array $context): array
    {
        $groups = DB::table('variation_group_members')->whereIn('product_id', array_keys($cards))->pluck('group_id', 'product_id');
        if ($groups->isEmpty()) {
            return $cards;
        }
        $members = app(PublicCatalog::class)->products($context['customer'])->join('variation_group_members as m', 'm.product_id', '=', 'products.id')
            ->whereIn('m.group_id', $groups->unique()->values()->all())->orderBy('products.id')->get(['products.id', 'products.category_id', 'm.group_id']);
        $ids = $members->pluck('id')->map(strval(...))->all();
        $texts = $this->translations('product_translations', 'product_id', $ids);
        $paths = app(CategoryUrls::class)->paths($members->pluck('category_id')->unique()->map(strval(...))->values()->all(), $context['locale']);
        $primary = DB::table('product_images')->whereIn('product_id', $ids)->where('is_primary', true)->pluck('media_file_id', 'product_id');
        $media = app(PublicMedia::class);
        $images = $media->images($primary->values()->all());
        $byGroup = [];
        foreach ($members as $m) {
            $name = $this->text($texts[$m->id] ?? [], $context['locale'], 'name');
            if ($name !== '') {
                $byGroup[$m->group_id][] = ['id' => (string) $m->id, 'name' => $name, 'url' => $this->productUrl($paths[(string) $m->category_id] ?? null, $this->text($texts[$m->id] ?? [], $context['locale'], 'slug')),
                    'image' => $media->url($images[$primary[$m->id] ?? 0] ?? null, 'product', (string) $m->id)];
            }
        }
        $colours = $this->variantColours($ids);
        foreach ($cards as $id => &$card) {
            // One picture per colour: members that differ only in other choices (memory, size) would repeat it. The card's own product stands for its colour.
            $seen = isset($colours[$id]) ? [$colours[$id] => true] : [];
            $siblings = array_values(array_filter($byGroup[$groups[$id] ?? 0] ?? [], function (array $s) use ($id, $colours, &$seen): bool {
                $colour = $colours[$s['id']] ?? null;
                if ($s['id'] === (string) $id || $colour === null) {
                    return true;
                }
                if (isset($seen[$colour])) {
                    return false;
                }

                return $seen[$colour] = true;
            }));
            $card['variants'] = count($siblings) > 1 ? $siblings : [];
        }
        unset($card);

        return $cards;
    }

    /** @param list<string> $ids
     * @return array<string,string> Member => its chosen options of the group's colour features; members without one are absent. */
    private function variantColours(array $ids): array
    {
        $rows = DB::table('variation_group_members as m')->join('variation_group_features as gf', 'gf.group_id', '=', 'm.group_id')
            ->join('features as f', 'f.id', '=', 'gf.feature_id')->where('f.variation_style', 'color')
            ->join('product_feature_assignments as a', fn ($j) => $j->on('a.product_id', '=', 'm.product_id')->on('a.feature_id', '=', 'gf.feature_id'))
            ->join('product_feature_choices as c', 'c.assignment_id', '=', 'a.id')
            ->whereIn('m.product_id', $ids)->orderBy('c.feature_id')->orderBy('c.option_id')->get(['m.product_id', 'c.feature_id', 'c.option_id']);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->product_id] = ($out[(string) $r->product_id] ?? '').$r->feature_id.':'.$r->option_id.';';
        }

        return $out;
    }

    /**
     * Adds each card's brand (name, and its brand page while brand pages are on and own that address) for blocks that
     * show it above the name. Products without an active brand get none.
     *
     * @param  array<string,array<string,mixed>>  $cards
     * @return array<string,array<string,mixed>>
     */
    public function withBrands(array $cards, string $locale): array
    {
        $owners = DB::table('products')->whereIn('id', array_keys($cards))->whereNotNull('brand_id')->pluck('brand_id', 'id');
        $brands = $owners->isEmpty() ? collect() : app(BrandQueries::class)->named($locale)->whereIn('b.id', $owners->unique()->values()->all())->where('b.status', 'active')->get()->keyBy('id');
        $urls = app(CatalogLandingPages::class)->brandUrls($brands->pluck('code')->all());
        foreach ($cards as $id => &$card) {
            $brand = $brands[$owners[$id] ?? 0] ?? null;
            $card['brand_link'] = $brand === null || trim($brand->name) === '' ? null : ['name' => $brand->name, 'url' => $urls[$brand->code] ?? null];
        }
        unset($card);

        return $cards;
    }

    /** Money formatting comes from the primary currency's settings (Settings → Currencies).
     * @return array<string,mixed> */
    public function currencyFormat(): array
    {
        $currency = app(CurrencyRepository::class)->currentPrimary();

        return ['code' => $currency->code, 'symbol' => $currency->symbol, 'position' => $currency->symbolPosition, 'thousands' => $currency->thousandsSeparator, 'decimal' => $currency->decimalSeparator, 'decimals' => $currency->decimals];
    }

    /** Public sticker payload: presentation only; image stickers point at the checked storefront media route.
     * @param list<array<string,mixed>> $stickers
     * @return list<array<string,mixed>> */
    private function stickers(array $stickers): array
    {
        return array_map(fn (array $s): array => [...array_diff_key($s, ['image_media_id' => true, 'width' => true, 'height' => true]),
            'image' => $s['image_media_id'] === null ? null : ['url' => '/storefront/media/sticker/'.$s['id'].'/'.$s['image_media_id'], 'width' => $s['width'], 'height' => $s['height']]], $stickers);
    }

    /** Product URL: the category's full path plus the product slug, e.g. /technology/phones/smartphones/iphone-15. */
    private function productUrl(?string $category, string $slug): ?string
    {
        return $category === null || $slug === '' ? null : $category.'/'.rawurlencode($slug);
    }

    /** Presentation settings travel with the payload; they contain no admin or customer data.
     * @param array<string,array<string,mixed>> $blocks
     * @param array<string,array<string,mixed>> $out
     * @param list<string> $keys
     * @return array<string,array<string,mixed>> */
    public function display(array $blocks, array $out, array $keys, string $locale): array
    {
        foreach ($out as $id => &$payload) {
            $payload['display'] = array_intersect_key($blocks[$id]['settings'], array_flip([...$keys, 'hide_arrows_mobile']));
            // Optional "see all" link next to the title: shown only when both its text and URL are filled in.
            $text = trim($this->text($blocks[$id]['translations'], $locale, 'link_text'));
            $url = (string) ($blocks[$id]['settings']['link_url'] ?? '');
            if ($text !== '' && MenuRules::safeUrl($url)) {
                $payload['link'] = ['text' => $text, 'url' => $url];
            }
        }

        return $out;
    }

    /** @param list<object> $rows
     * @return array<int|string,array{id:string,price:string}|null> */
    private function prices(array $rows, ?string $customer): array
    {
        $requests = [];
        foreach ($rows as $p) {
            $requests[$p->id] = ['base' => $p->price, 'quantity' => (int) ($p->min_qty ?? $p->qty_step ?? 1)];
        }

        return app(ProductSales::class)->prices($requests, $customer, app(CurrencyRepository::class)->currentPrimary()->decimals);
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<string,array<string,mixed>> */
    private function categories(array $blocks, array $context): array
    {
        $selections = [];
        $base = app(PublicCatalog::class)->categories($context['customer']);
        $root = [];
        if (collect($blocks)->contains(fn ($b) => $b['settings']['source'] === 'root')) {
            $root = (clone $base)->whereNull('categories.parent_id')->orderBy('categories.position')->orderBy('categories.id')->limit(48)->pluck('categories.id')->map(strval(...))->all();
        }
        foreach ($blocks as $id => $b) {
            $selections[$id] = array_slice($b['settings']['source'] === 'root' ? $root : ($b['selections']['items'] ?? []), 0, str_ends_with($b['template'], '_single') ? 1 : (int) $b['settings']['limit']);
        }
        $ids = array_unique(array_merge([], ...array_values($selections)));
        $rows = (clone $base)->whereIn('categories.id', $ids)->get();
        $translations = $this->translations('category_translations', 'category_id', $ids);
        // The dedicated block image wins; the category image remains the fallback.
        $images = app(PublicMedia::class)->images([...$rows->pluck('block_image_media_id')->all(), ...$rows->pluck('image_media_id')->all()]);
        $paths = app(CategoryUrls::class)->paths($rows->pluck('id')->map(strval(...))->all(), $context['locale']);
        $cards = [];
        foreach ($rows as $c) {
            $name = $this->text($translations[$c->id] ?? [], $context['locale'], 'name');
            if ($name === '') {
                continue;
            }
            $cards[$c->id] = ['id' => (string) $c->id, 'name' => $name, 'url' => $paths[(string) $c->id] ?? null, 'image' => app(PublicMedia::class)->url(($images[$c->block_image_media_id] ?? $images[$c->image_media_id] ?? null), 'category', (string) $c->id)];
        }

        return $this->display($blocks, $this->selected($selections, $cards), ['per_row', 'rows', 'card_shadow', 'autoscroll', 'drag_scroll', 'infinite_scroll', 'navigation', 'image_width', 'image_height', 'image_radius', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], $context['locale']);
    }

    /**
     * Blog post cards: only posts a visitor may see in a list (published, active, available, of a visible blog).
     *
     * @param  array<string,array<string,mixed>>  $blocks
     * @param  array{locale:string,customer:?string,main?:array<string,mixed>}  $context
     * @return array<string,array<string,mixed>>
     */
    private function blogPosts(array $blocks, array $context): array
    {
        $blogs = app(StorefrontBlogs::class);
        $visible = $blogs->blogs()->pluck('blogs.id')->map(strval(...))->all();
        $selections = [];
        foreach ($blocks as $id => $b) {
            $limit = str_ends_with($b['template'], '_single') ? 1 : (int) $b['settings']['limit'];
            $q = $blogs->posts($visible, true);
            if ($b['settings']['source'] === 'manual') {
                $picked = $b['selections']['items'] ?? [];
                $shown = $q->whereIn('blog_posts.id', $picked)->pluck('blog_posts.id')->map(strval(...))->all();
                $selections[$id] = array_slice(array_values(array_intersect($picked, $shown)), 0, $limit);
            } else {
                if (isset($b['selections']['blog'][0])) {
                    $q->where('blog_posts.blog_id', $b['selections']['blog'][0]);
                }
                $selections[$id] = $q->orderByDesc('blog_posts.published_at')->orderByDesc('blog_posts.id')->limit($limit)->pluck('blog_posts.id')->map(strval(...))->all();
            }
        }
        $cards = array_map(fn (array $card): array => [...$card, 'image' => $card['cover'], 'image_mobile' => $card['cover_mobile'], 'image_alt' => $card['cover_alt']], $blogs->cards(array_values(array_unique(array_merge([], ...array_values($selections)))), $context['locale']));

        return $this->display($blocks, $this->selected($selections, $cards), ['per_row', 'show_date', 'show_excerpt', 'card_shadow', 'autoscroll', 'drag_scroll', 'infinite_scroll', 'navigation', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], $context['locale']);
    }

    /** @return list<array{code:string,name:string,url:string,active:bool}> */
    private function languages(string $locale): array
    {
        $languages = app(LocaleCatalog::class)->active()->map(fn ($l): array => ['code' => $l->code, 'name' => $l->native_name, 'url' => '/locale/'.$l->code, 'active' => $l->code === $locale])->values()->all();

        return count($languages) > 1 ? $languages : [];
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<string,array<string,mixed>> */
    private function menus(array $blocks, array $context): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            foreach (['main', 'secondary'] as $slot) {
                if (isset($b['selections'][$slot][0])) {
                    $ids[] = $b['selections'][$slot][0];
                }
            }
        }
        $trees = app(PublicMenus::class)->trees(array_values(array_unique($ids)), $context['locale'], $context['customer']);
        $languages = $this->languages($context['locale']);
        $out = [];
        foreach ($blocks as $id => $b) {
            $main = $trees[$b['selections']['main'][0] ?? ''] ?? [];
            $secondary = $trees[$b['selections']['secondary'][0] ?? ''] ?? [];
            if ($main === [] && $secondary === []) {
                continue;
            }
            $s = $b['settings'];
            $out[$id] = ['main' => $main, 'secondary' => $secondary, 'languages' => ($s['show_language_switcher'] ?? true) && count($languages) > 1 ? $languages : [],
                'display' => [...array_intersect_key($s, array_flip(['main_desktop', 'main_tablet', 'main_mobile', 'secondary_desktop', 'secondary_tablet', 'secondary_mobile', 'show_language_switcher', 'mobile_style', 'full_width_dropdown', 'dim_page_on_open', 'open_on_click', 'dropdown_style'])), 'full_bleed' => true, 'allow_overflow' => true,
                    'background_color' => $s['background_color'] ?: '#ffffff', 'text_color' => $s['text_color'] ?: 'var(--sf-text)']];
        }

        return $out;
    }

    /** The newsletter sign-up form of each block: it names no list publicly, only whether a confirmation e-mail follows.
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,array<string,mixed>> */
    private function newsletters(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            if (isset($b['selections']['list'][0])) {
                $ids[] = $b['selections']['list'][0];
            }
        }
        $lists = $ids === [] ? collect() : DB::table('newsletter_lists')->whereIn('id', $ids)->where('status', 'active')->get(['id', 'confirmation_id'])->keyBy('id');
        $confirming = $lists->pluck('confirmation_id')->filter()->all();
        $active = $confirming === [] ? [] : DB::table('newsletters')->whereIn('id', $confirming)->where('is_active', true)->pluck('id')->map(strval(...))->all();
        $out = [];
        foreach ($blocks as $id => $b) {
            $list = $lists[$b['selections']['list'][0] ?? ''] ?? null;
            if ($list === null) {
                continue;
            }
            $url = (string) ($b['settings']['terms_url'] ?? '');
            $out[$id] = ['form' => (string) $id, 'confirm' => $list->confirmation_id !== null && in_array((string) $list->confirmation_id, $active, true), 'terms_url' => MenuRules::safeUrl($url) ? $url : null];
        }

        return $this->display($blocks, $out, ['background_color', 'full_width_background', 'padding_top', 'padding_bottom', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], app()->getLocale());
    }

    /** The store footer: link-group columns, social links, app links, payment pictures and a bottom line.
     * {store_name} and {year} in column titles, links and the bottom line are replaced from the company settings.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<string,array<string,mixed>> */
    private function footers(array $blocks, array $context): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            if (isset($b['selections']['menu'][0])) {
                $ids[] = $b['selections']['menu'][0];
            }
        }
        $trees = app(PublicMenus::class)->trees(array_values(array_unique($ids)), $context['locale'], $context['customer']);
        $company = DB::table('company_settings')->where('id', 1)->first();
        $name = trim((string) ($company->name ?? '')) !== '' ? (string) $company->name : (string) config('app.name');
        $text = fn (string $value): string => str_replace(['{store_name}', '{year}'], [$name, (string) now()->year], $value);
        $fill = function (array $nodes) use (&$fill, $text): array {
            return array_map(fn (array $n): array => [...$n, 'name' => $text($n['name']), 'children' => $fill($n['children'])], $nodes);
        };
        // Social pages and app links in the merchant's order; a preset without its own picture uses the built-in mark.
        $links = DB::table('company_links')->orderBy('position')->orderBy('id')->get();
        $linkImages = app(PublicMedia::class)->images($links->pluck('media_file_id')->filter()->all());
        $social = $apps = [];
        foreach ($links as $link) {
            if (! MenuRules::safeUrl($link->url)) {
                continue;
            }
            $image = $link->media_file_id === null ? null : app(PublicMedia::class)->url($linkImages[$link->media_file_id] ?? null, 'social-icon', (string) $link->id);
            if ($link->preset === null && $image === null) {
                continue;
            }
            $item = ['network' => $link->preset ?? 'custom', 'name' => $link->name, 'url' => $link->url, 'image' => $image];
            if ($link->kind === 'app') {
                $apps[] = $item;
            } else {
                $social[] = $item;
            }
        }
        $icons = DB::table('company_payment_icons')->orderBy('position')->orderBy('id')->get(['id', 'name', 'media_file_id']);
        $images = app(PublicMedia::class)->images($icons->pluck('media_file_id')->all());
        $payments = [];
        foreach ($icons as $icon) {
            if ($image = app(PublicMedia::class)->url($images[$icon->media_file_id] ?? null, 'payment-icon', (string) $icon->id)) {
                $payments[] = ['name' => $icon->name, 'image' => $image];
            }
        }
        $out = [];
        foreach ($blocks as $id => $b) {
            $s = $b['settings'];
            $groups = $fill($trees[$b['selections']['menu'][0] ?? ''] ?? []);
            $bottom = $text(app(RichTextSanitizer::class)->sanitize($this->text($b['translations'], $context['locale'], 'html')));
            $extras = ['social' => $s['show_social'] ? $social : [], 'apps' => $s['show_apps'] ? $apps : [], 'payments' => $s['show_payments'] ? $payments : []];
            if ($groups === [] && trim(strip_tags($bottom)) === '' && array_filter($extras) === []) {
                continue;
            }
            $out[$id] = ['groups' => $groups, 'html' => $bottom, ...$extras,
                'display' => [...array_intersect_key($s, array_flip(['columns', 'mobile_style', 'show_top_border', 'heading_size', 'text_size'])), 'full_bleed' => true, 'allow_overflow' => true,
                    'background_color' => $s['background_color'] ?: '#ffffff', 'text_color' => $s['text_color'] ?: 'var(--sf-text)']];
        }

        return $out;
    }

    /** Free manual link groups (headings with links) shown as a directory.
     * @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<string,array<string,mixed>> */
    private function linkDirectories(array $blocks, array $context): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            if (isset($b['selections']['menu'][0])) {
                $ids[] = $b['selections']['menu'][0];
            }
        }
        $trees = app(PublicMenus::class)->trees(array_values(array_unique($ids)), $context['locale'], $context['customer']);
        $out = [];
        foreach ($blocks as $id => $b) {
            $groups = $trees[$b['selections']['menu'][0] ?? ''] ?? [];
            if ($groups !== []) {
                $out[$id] = ['groups' => $groups];
            }
        }

        return $this->display($blocks, $out, ['columns', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], $context['locale']);
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string} $context
     * @return array<string,array<string,mixed>> */
    private function visualMenus(array $blocks, array $context): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (isset($block['selections']['menu'][0])) {
                $ids[] = $block['selections']['menu'][0];
            }
        }
        $trees = app(PublicMenus::class)->trees(array_values(array_unique($ids)), $context['locale'], $context['customer']);
        $out = [];
        foreach ($blocks as $id => $block) {
            $items = array_slice($trees[$block['selections']['menu'][0] ?? ''] ?? [], 0, (int) $block['settings']['limit']);
            if ($items !== []) {
                $out[$id] = ['items' => array_map(fn (array $item): array => array_intersect_key($item, array_flip(['id', 'name', 'url', 'new_window', 'image'])), $items)];
            }
        }

        return $this->display($blocks, $out, ['navigation', 'drag_scroll', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], $context['locale']);
    }

    public function eligibleBanners(?string $customer): Builder
    {
        $q = DB::table('banners')->where('banners.status', 'active')->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>', now()));
        app(PublicCatalog::class)->restrict($q, 'banner_customer_groups', 'banner_id', 'banners.id', app(PublicCatalog::class)->groups($customer));

        return $q;
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<string,array<string,mixed>> */
    private function banners(array $blocks, array $context): array
    {
        $selections = [];
        $eligible = $this->eligibleBanners($context['customer']);
        $sources = [];
        $groupIds = [];
        $latest = false;
        foreach ($blocks as $b) {
            $latest = $latest || $b['settings']['source'] === 'latest';
            if ($b['settings']['source'] === 'group' && isset($b['selections']['group'][0])) {
                $groupIds[] = $b['selections']['group'][0];
            }
        }
        if ($latest) {
            $sources['latest:'] = (clone $eligible)->orderByDesc('created_on')->orderByDesc('id')->limit(48)->pluck('id')->map(strval(...))->all();
        }
        if ($groupIds !== []) {
            $ranked = (clone $eligible)->whereIn('group_id', array_unique($groupIds))->selectRaw('banners.id, group_id, row_number() over (partition by group_id order by position asc, id desc) as rank');
            foreach (DB::query()->fromSub($ranked, 'ranked')->where('rank', '<=', 48)->orderBy('group_id')->orderBy('rank')->get() as $row) {
                $sources['group:'.$row->group_id][] = (string) $row->id;
            }
        }
        foreach ($blocks as $id => $b) {
            $source = $b['settings']['source'];
            $limit = str_ends_with($b['template'], '_single') ? 1 : (int) $b['settings']['limit'];
            $group = $source === 'group' ? ($b['selections']['group'][0] ?? '') : '';
            $selections[$id] = array_slice($source === 'manual' ? ($b['selections']['items'] ?? []) : ($sources[$source.':'.$group] ?? []), 0, $limit);
        }
        $ids = array_unique(array_merge([], ...array_values($selections)));
        $rows = (clone $eligible)->whereIn('id', $ids)->get();
        $saved = app(StorefrontCache::class)->remember('blocks', ['banner-compositions', $ids], fn (): array => [
            'codes' => DB::table('locales')->pluck('code', 'id')->all(),
            'contents' => DB::table('banner_contents')->whereIn('banner_id', $ids)->get()->map(fn (object $r): array => (array) $r)->all(),
            'layouts' => DB::table('banner_layouts')->whereIn('banner_id', $ids)->get()->map(fn (object $r): array => (array) $r)->all(),
        ]);
        $codes = $saved['codes'];
        $contents = collect($saved['contents'])->map(fn (array $r): object => (object) $r);
        $layouts = [];
        foreach ($saved['layouts'] as $values) {
            $row = (object) $values;
            $layouts[$row->banner_id][$row->device] = array_intersect_key((array) $row, BannerRules::LAYOUT);
        }
        $images = app(PublicMedia::class)->images([...$contents->pluck('main_image_media_id')->all(), ...$contents->pluck('background_image_media_id')->all()]);
        $videos = app(PublicMedia::class)->videos($contents->pluck('background_video_id')->all());
        $products = $this->bannerProducts($rows->where('object_type', 'products')->pluck('id')->all(), $context);
        $texts = [];
        foreach ($contents as $r) {
            $texts[$r->banner_id][$r->device][$codes[$r->locale_id]] = (array) $r;
        }
        $cards = [];
        foreach ($rows as $b) {
            // Only public presentation settings: never names, customer-group rules or audit fields.
            $settings = array_intersect_key((array) $b, array_flip([...array_keys(BannerRules::SETTINGS), 'products_columns', 'products_rows', 'button_transition', 'youtube_autoplay', 'youtube_hide_controls', 'background_color', 'title_color', 'description_color', 'description_background', 'url', 'new_window', 'tablet_custom', 'phone_custom']));
            $settings['url'] = MenuRules::safeUrl((string) $b->url) ? $b->url : null;
            $settings['button_transition'] = (float) $b->button_transition;
            $byDevice = $texts[$b->id] ?? [];
            $pc = $byDevice['pc'] ?? [];
            $baseLayout = array_replace(BannerRules::LAYOUT_DEFAULTS, array_filter($layouts[$b->id]['pc'] ?? [], fn ($v) => $v !== null && $v !== ''));
            $devices = [];
            foreach (BannerRules::DEVICES as $device) {
                $custom = $device !== 'pc' && $b->{($device === 'tablet' ? 'tablet_custom' : 'phone_custom')};
                $own = $custom ? ($byDevice[$device][$context['locale']] ?? []) : [];
                $content = [];
                foreach (['title', 'description', 'button_text'] as $field) {
                    $content[$field] = trim((string) ($own[$field] ?? '')) !== '' ? $own[$field] : $this->text($pc, $context['locale'], $field);
                }
                $content['title'] = app(BannerTitleSanitizer::class)->sanitize($content['title']);
                $content['youtube_video'] = BannerRules::youtube($this->text($pc, $context['locale'], 'youtube_video')) ?? '';
                foreach (['main_image', 'background_image', 'background_video'] as $slot) {
                    $field = $slot === 'background_video' ? 'background_video_id' : $slot.'_media_id';
                    $media = $own[$field] ?? $this->text($pc, $context['locale'], $field);
                    $content[$slot] = $slot === 'background_video'
                        ? (isset($videos[$media]) ? '/storefront/media/banner-video/'.$b->id.'/'.$media : null)
                        : (app(PublicMedia::class)->url($images[$media] ?? null, 'banner', (string) $b->id)['url'] ?? null);
                    // A removed/missing device asset inherits the PC asset, like the editor preview.
                    if ($content[$slot] === null && $device !== 'pc') {
                        $content[$slot] = $devices['pc']['content'][$slot];
                    }
                }
                $devices[$device] = ['layout' => $custom ? array_replace($baseLayout, array_filter($layouts[$b->id][$device] ?? [], fn ($v) => $v !== null && $v !== '')) : $baseLayout, 'content' => $content];
            }
            $hasContent = false;
            foreach ($devices as $d) {
                $hasContent = $hasContent || trim(strip_tags($d['content']['title'].$d['content']['description'].$d['content']['button_text'])) !== '' || $d['content']['main_image'] !== null || $d['content']['background_image'] !== null || $d['content']['background_video'] !== null || $d['content']['youtube_video'] !== '';
            }
            if (! $hasContent && ($products[$b->id] ?? []) === []) {
                continue;
            }
            $cards[$b->id] = ['id' => (string) $b->id, 'banner' => ['settings' => $settings, 'devices' => $devices, 'products' => $products[$b->id] ?? []]];
        }

        return $this->display($blocks, $this->selected($selections, $cards), ['visible_desktop', 'visible_tablet', 'height_desktop', 'height_tablet', 'autoplay_seconds', 'navigation', 'navigation_style', 'drag_scroll', 'infinite_scroll', 'full_width', 'remove_border_radius', 'fill_height', 'preload'], $context['locale']);
    }

    /** Product objects inside a banner use current public catalog eligibility, never admin projections.
     * @param list<int|string> $ids
     * @param array{locale:string,customer:?string,main?:array<string,mixed>} $context
     * @return array<int|string,list<array<string,mixed>>> */
    private function bannerProducts(array $ids, array $context): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = app(PublicCatalog::class)->products($context['customer'])->join('banner_products as bp', 'bp.product_id', '=', 'products.id')->whereIn('bp.banner_id', $ids)->orderBy('bp.position')->orderBy('products.id')->get(['products.id', 'bp.banner_id']);
        $productIds = $rows->pluck('id')->unique()->all();
        $translations = $this->translations('product_translations', 'product_id', $productIds);
        $primary = DB::table('product_images')->whereIn('product_id', $productIds)->where('is_primary', true)->pluck('media_file_id', 'product_id');
        $images = app(PublicMedia::class)->images($primary->values()->all());
        $result = [];
        foreach ($rows as $p) {
            $name = $this->text($translations[$p->id] ?? [], $context['locale'], 'name');
            if ($name !== '') {
                $result[$p->banner_id][] = ['id' => (string) $p->id, 'name' => $name, 'sku' => null, 'image' => app(PublicMedia::class)->url($images[$primary[$p->id] ?? 0] ?? null, 'product', (string) $p->id)['url'] ?? null];
            }
        }

        return $result;
    }

    /** @param array<string,list<string>> $selections
     * @param array<int|string,array<string,mixed>> $cards
     * @return array<string,array<string,mixed>> */
    private function selected(array $selections, array $cards): array
    {
        $out = [];
        foreach ($selections as $id => $ids) {
            $items = array_values(array_filter(array_map(fn ($id) => $cards[$id] ?? null, $ids)));
            if ($items !== []) {
                $out[$id] = ['items' => $items];
            }
        }

        return $out;
    }
}
