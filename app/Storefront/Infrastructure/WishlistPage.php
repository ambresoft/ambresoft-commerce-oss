<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Customer\Infrastructure\Wishlists;
use App\Storefront\Application\Service\StorefrontRegistry;

/**
 * What the wishlist page's main content block shows: the customer's lists and the products of the chosen list as
 * ordinary storefront product cards (so they follow the product card style and the product blocks' presentation).
 * Products the customer may no longer see are left out; they stay saved and return if they become visible again.
 */
final class WishlistPage
{
    /**
     * @param  array{locale:string,customer:string}  $context
     * @return array<string,mixed>
     */
    public function data(array $context, ?string $requested): array
    {
        $wishlists = app(Wishlists::class);
        $lists = $wishlists->lists($context['customer']);
        $current = collect($lists)->firstWhere('id', $requested) ?? $lists[0];
        $ids = $wishlists->products($context['customer'], (int) $current['id']);
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($ids, $context, app(PublicCatalog::class)->products($context['customer']));
        $items = array_values(array_filter(array_map(fn (string $id) => $cards[$id] ?? null, $ids)));
        // Same presentation as a product carousel block with its default settings.
        $display = array_intersect_key(app(StorefrontRegistry::class)->type('products')?->definition()['defaults'] ?? [], array_flip(BlockContent::PRODUCT_DISPLAY));

        return ['lists' => $lists, 'current' => $current['id'], 'items' => $items, 'currency_format' => $format, 'display' => $display];
    }
}
