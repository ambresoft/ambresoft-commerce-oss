<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\ProductCompanions;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Catalog\Infrastructure\SimilarProducts;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Storefront\Application\Service\StorefrontRegistry;

/**
 * The recommendation sections of a product page, as storefront product cards: automatic similar products and the
 * merchant's frequently-bought-together companions with a live combined price. Both are read from current catalog
 * data on every request (no stored lists or totals). The product page itself is a later deliverable; it renders the
 * similar products with the existing product carousel and shows a section only when this returns content.
 */
final class ProductRecommendations
{
    /**
     * @param  array{locale:string,customer:?string}  $context
     * @return array{items: list<array<string,mixed>>, currency_format: array<string,mixed>, display: array<string,mixed>}|null Null when nothing suitable exists.
     */
    public function similar(string $product, array $context, int $limit = SimilarProducts::LIMIT): ?array
    {
        return $this->carousel(app(SimilarProducts::class)->ids($product, $context['customer'], $limit), $context);
    }

    /** Reuse the existing product block resolver, including its age window and public listing scope.
     * @param  array{locale:string,customer:?string}  $context
     * @return array<string,mixed>|null
     */
    public function newest(string $product, ?string $category, array $context, ?int $limit = null): ?array
    {
        $settings = app(StorefrontRegistry::class)->type('products')->definition()['defaults'];
        $settings['limit'] = $limit ?? $settings['limit'];
        $blocks = ['newest' => ['settings' => $settings, 'template' => 'product_carousel', 'translations' => [],
            'selections' => ['filter_categories' => $category === null ? [] : [$category]]]];
        $result = app(BlockContent::class)->resolve('products', $blocks, [...$context, 'exclude_product' => $product]);

        return $result['newest'] ?? null;
    }

    /** @param list<string> $ids
     * @param  array{locale:string,customer:?string}  $context
     * @return array<string,mixed>|null
     */
    public function carousel(array $ids, array $context): ?array
    {
        $representatives = app(VariationListings::class)->representatives($context['customer'], 'carousels');
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => array_key_exists($id, $representatives) ? $representatives[$id] : $id, $ids))));
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($ids, $context, app(PublicCatalog::class)->listed($context['customer'], 'carousels'), 'carousels');
        $items = array_values(array_filter(array_map(fn (string $id) => $cards[$id] ?? null, $ids)));
        $display = array_intersect_key(app(StorefrontRegistry::class)->type('products')->definition()['defaults'], array_flip(BlockContent::PRODUCT_DISPLAY));

        return $items === [] ? null : ['items' => $items, 'currency_format' => $format, 'display' => $display];
    }

    /**
     * The main product followed by its companions that the visitor may still see. Each item says whether it is
     * included in the total: only products that can be bought now and have a shown price are added, so the total never
     * silently contains an unavailable product. Items carry the product id and its smallest order quantity so a later
     * "add all to cart" can submit them as they are.
     *
     * @param  array{locale:string,customer:?string}  $context
     * @return array{items: list<array<string,mixed>>, total: string|null, included: int, currency_format: array<string,mixed>}|null
     */
    public function boughtTogether(string $product, array $context): ?array
    {
        $companions = app(ProductCompanions::class)->ids($product);
        if ($companions === []) {
            return null;
        }
        $ids = [$product, ...$companions];
        // The product page scope: a companion that is out of stock stays visible (and is marked as not included).
        $scope = app(PublicCatalog::class)->products($context['customer']);
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($ids, $context, $scope);
        if (! isset($cards[$product]) || array_intersect($companions, array_keys($cards)) === []) {
            return null;
        }
        $quantities = (clone $scope)->whereIn('products.id', $ids)->get(['products.id', 'products.min_qty', 'products.qty_step'])->mapWithKeys(fn ($r) => [(string) $r->id => (int) ($r->min_qty ?? $r->qty_step ?? 1)]);
        $hidden = app(GeneralSettings::class)->all()['hide_product_prices'];
        $items = [];
        $total = '0';
        $included = 0;
        foreach ($ids as $n => $id) {
            if (! isset($cards[$id])) {
                continue;
            }
            $card = $cards[$id];
            $in = $card['available'] && $card['price'] !== null;
            if ($in) {
                $total = Decimal::add($total, Decimal::multiply($card['price'], (string) $quantities[$id]));
                $included++;
            }
            $items[] = [...$card, 'main' => $n === 0, 'included' => $in, 'quantity' => $quantities[$id] ?? 1];
        }

        return ['items' => $items, 'total' => $hidden || $included === 0 ? null : Decimal::round($total, $format['decimals']), 'included' => $included, 'currency_format' => $format];
    }
}
