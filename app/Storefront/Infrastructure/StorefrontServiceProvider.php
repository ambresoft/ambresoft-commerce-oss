<?php

namespace App\Storefront\Infrastructure;

use App\Cart\Application\Contract\AbandonedCarts;
use App\Cart\Infrastructure\CustomerAbandonedCarts;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Storefront\Application\Contract\StorefrontCatalog;
use App\Storefront\Application\Contract\StorefrontUrls;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Support\ServiceProvider;

final class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Carts of customers who stopped before checkout, for add-ons such as cart reminders.
        $this->app->bind(AbandonedCarts::class, CustomerAbandonedCarts::class);
        // The public catalog for add-ons.
        $this->app->bind(StorefrontCatalog::class, GuestStorefrontCatalog::class);
        $this->app->bind(StorefrontUrls::class, IndexableStorefrontUrls::class);
        $this->app->singleton(StorefrontRegistry::class, function (): StorefrontRegistry {
            $registry = new StorefrontRegistry;
            foreach (['reels', 'coupons', 'offers', 'banners', 'products', 'categories', 'blog_posts', 'html', 'identity', 'topbar', 'menu', 'visual_menu', 'link_directory', 'footer', 'newsletter', 'main_content', 'product_filters', 'page_header', 'checkout_header', 'popular_searches'] as $type) {
                $registry->block(new FirstPartyBlock($type));
            }
            $registry->block(new BrandBlock('brands'));
            $registry->block(new BrandBlock('brand_products'));
            $shared = ['top_panel', 'header', 'navigation', 'before_content', 'after_content', 'footer'];
            $registry->theme('default', ['name' => 'Default', 'regions' => [...$shared, 'content'], 'templates' => ['reel_carousel', 'reel_single', 'coupon_carousel', 'offer_carousel', 'brand_carousel', 'brand_single', 'banner_carousel', 'banner_single', 'product_carousel', 'product_single', 'category_carousel', 'category_single', 'blog_carousel', 'blog_single', 'html', 'identity', 'topbar', 'menu', 'menu_tiles_carousel', 'menu_circles_carousel', 'menu_cards_carousel', 'link_directory', 'footer', 'newsletter', 'main_content', 'product_filters', 'page_header', 'checkout_header', 'popular_searches']]);
            $registry->page('default', ['label' => 'layouts.default', 'regions' => $shared, 'inherits' => false]);
            $registry->page('homepage', ['label' => 'layouts.homepage', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('products', ['label' => 'layouts.products', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('catalog_landing', ['label' => 'layouts.catalog_landing', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('category_landing', ['label' => 'layouts.category_landing', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('category', ['label' => 'layouts.category', 'regions' => ['content'], 'inherits' => true]);
            // Store-wide listings: /new, /bestsellers and /offers.
            $registry->page('new_products', ['label' => 'layouts.new_products', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('bestsellers', ['label' => 'layouts.bestsellers', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('offers', ['label' => 'layouts.offers', 'regions' => ['content'], 'inherits' => true]);
            // Product search results (/search).
            $registry->page('search', ['label' => 'layouts.search', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('cart', ['label' => 'layouts.cart', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('checkout', ['label' => 'layouts.checkout', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('wishlist', ['label' => 'layouts.wishlist', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('comparison', ['label' => 'layouts.comparison', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('order_tracking', ['label' => 'layouts.order_tracking', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('account', ['label' => 'layouts.account', 'regions' => ['content'], 'inherits' => true]);

            // A blog's post list (/blog) and its posts (/blog/post).
            $registry->page('blog', ['label' => 'layouts.blog', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('blog_post', ['label' => 'layouts.blog_post', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('sitemap', ['label' => 'layouts.sitemap', 'regions' => ['content'], 'inherits' => true]);
            $registry->page('brands', ['label' => 'layouts.brands', 'regions' => ['content'], 'inherits' => true]);

            return $registry;
        });
        $this->app->scoped(BlockContent::class);
        $this->app->scoped(StorefrontCache::class);
    }
}
