<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use Illuminate\Support\Facades\DB;

final class BrandContent
{
    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string} $context
     * @return array<string,array<string,mixed>> */
    public function resolve(string $type, array $blocks, array $context): array
    {
        if ($type === 'brand_products') {
            $ids = [];
            foreach ($blocks as $id => $b) {
                if (isset($b['selections']['brand'][0])) {
                    $ids[$id] = $b['selections']['brand'][0];
                }
            }
            $brands = $this->cards(array_values($ids), $context['locale']);
            $ids = array_filter($ids, fn ($brand) => isset($brands[$brand]));
            $valid = array_intersect_key($blocks, $ids);
            if ($valid === []) {
                return [];
            }
            $out = app(BlockContent::class)->products($valid, $context, $ids);
            foreach ($out as $id => &$payload) {
                $payload['brand'] = $brands[$ids[$id]];
            }
            unset($payload);

            return $out;
        }

        // A selected brand turns this source into that brand's product bestsellers.
        // Keep older unfiltered placements showing ranked brand logos.
        $productBlocks = array_filter($blocks, fn ($b) => $b['settings']['source'] === 'bestsellers' && isset($b['selections']['brand'][0]));
        $productDefaults = (new FirstPartyBlock('products'))->definition()['defaults'];
        foreach ($productBlocks as &$block) {
            $settings = $block['settings'];
            $block['settings'] = array_replace($productDefaults, array_intersect_key($settings, $productDefaults));
            foreach (BrandBlock::PRODUCT_SIZES as $key => $productKey) {
                $block['settings'][$productKey] = $settings[$key] ?? $productDefaults[$productKey];
            }
        }
        unset($block);
        $productContent = $productBlocks === [] ? [] : $this->resolve('brand_products', $productBlocks, $context);
        $blocks = array_diff_key($blocks, $productBlocks);
        if ($blocks === []) {
            return $productContent;
        }

        $eligible = app(PublicCatalog::class)->listed($context['customer'])->whereNotNull('products.brand_id');
        // Live all-time paid, non-void unit totals; current product-to-brand ownership is the merchandising attribution.
        $sources = array_column(array_column($blocks, 'settings'), 'source');
        $ranked = in_array('bestsellers', $sources, true) ? (clone $eligible)->join('brands as brand', 'brand.id', 'products.brand_id')->where('brand.status', 'active')
            ->joinSub(app(Bestsellers::class)->productUnitTotals(), 'sales', 'sales.product_id', 'products.id')
            ->groupBy('products.brand_id')->selectRaw('products.brand_id, sum(sales.units) as units')->orderByDesc('units')->orderBy('products.brand_id')->limit(48)->pluck('products.brand_id')->map(strval(...))->all() : [];
        $eligibleIds = (clone $eligible)->select('products.brand_id');
        $alphabetical = in_array('alphabetical', $sources, true) ? app(BrandQueries::class)->named($context['locale'])->where('b.status', 'active')->whereIn('b.id', $eligibleIds)->orderBy('name')->orderBy('b.id')->limit(48)->pluck('b.id')->map(strval(...))->all() : [];
        $selections = [];
        foreach ($blocks as $id => $b) {
            $ids = match ($b['settings']['source']) {
                'bestsellers' => $ranked,
                'alphabetical' => $alphabetical,
                default => $b['selections']['items'] ?? [],
            };
            $selections[$id] = array_slice($ids, 0, str_ends_with($b['template'], '_single') ? 1 : (int) $b['settings']['limit']);
        }
        $ids = array_values(array_unique(array_merge([], ...array_values($selections))));
        $visible = DB::query()->fromSub((clone $eligible)->select('products.brand_id')->distinct(), 'eligible')->whereIn('brand_id', $ids)->pluck('brand_id')->map(strval(...))->all();
        $cards = $this->cards($visible, $context['locale']);
        $out = [];
        foreach ($selections as $id => $ids) {
            $items = array_values(array_filter(array_map(fn ($brand) => $cards[$brand] ?? null, $ids)));
            if ($items !== []) {
                $out[$id] = ['items' => $items];
            }
        }

        return $productContent + app(BlockContent::class)->display($blocks, $out, ['per_row', 'rows', 'autoscroll', 'drag_scroll', 'infinite_scroll', 'navigation', 'image_width', 'image_height', 'image_radius', 'background_color', 'full_width_background', 'title_size_desktop', 'title_size_tablet', 'title_size_mobile', 'title_color'], $context['locale']);
    }

    /** @param list<int|string> $ids
     * @return array<string,array<string,mixed>> */
    private function cards(array $ids, string $locale): array
    {
        return app(StorefrontCache::class)->remember('brands', ['cards', $ids, $locale], fn () => $this->loadCards($ids, $locale));
    }

    /** @param list<int|string> $ids
     * @return array<string,array<string,mixed>> */
    private function loadCards(array $ids, string $locale): array
    {
        $rows = app(BrandQueries::class)->named($locale)->whereIn('b.id', $ids)->where('b.status', 'active')->get();
        $images = app(PublicMedia::class)->images($rows->pluck('image_media_id')->all());
        $urls = app(CatalogLandingPages::class)->brandUrls($rows->pluck('code')->all());
        $out = [];
        foreach ($rows as $b) {
            if (trim($b->name) !== '') {
                $out[(string) $b->id] = ['id' => (string) $b->id, 'name' => $b->name, 'image_alt' => $b->image_alt ?: $b->name, 'image' => app(PublicMedia::class)->url($images[$b->image_media_id] ?? null, 'brand', (string) $b->id), 'url' => $urls[$b->code] ?? null];
            }
        }

        return $out;
    }
}
