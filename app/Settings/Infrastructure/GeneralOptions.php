<?php

namespace App\Settings\Infrastructure;

/** Typed defaults and allowlists shared by settings validation and the admin projection. */
final class GeneralOptions
{
    public const DEFAULTS = [
        'storefront_open' => true,
        'storefront_noindex' => false,
        'date_format' => 'd/m/Y',
        'time_format' => 'H:i',
        'timezone' => 'UTC',
        'calendar_date_format' => 'd/m/Y',
        'calendar_week_start' => 1,
        'warn_unsaved_changes' => true,
        'show_menu_descriptions' => true,
        'notification_duration' => 5,
        'site_max_width' => 1320,
        'show_back_to_top' => true,
        'phone_validation' => 'formatted',
        'weight_unit' => 'kg',
        'grams_per_weight_unit' => '1000',
        'low_stock_threshold' => 0,
        'allow_product_comparison' => true,
        'track_inventory' => true,
        'single_coupon' => true,
        // Storefront cart: the coupon and gift card code field; off hides it and ignores codes already entered.
        'cart_codes_enabled' => true,
        // Storefront only: hides every product price (admin still shows and edits them).
        'hide_product_prices' => false,
        // Storefront listings skip products without stock; the product page itself stays reachable.
        'hide_out_of_stock_listings' => false,
        // Storefront only: white picture backgrounds take the grey behind them (product cards and pages, not the gallery).
        'blend_product_images' => true,
        // Storefront product cards in carousels and blocks: Classic, Showcase or Signature.
        'product_card_style' => 'classic',
        // Storefront wishlists (hearts, the add-to-list window, /wishlist); off removes them everywhere.
        'wishlist_enabled' => true,
        // Storefront reviews and star ratings everywhere (product pages, cards, filters); off hides them and refuses new reviews.
        'reviews_enabled' => true,
        // Storefront cart and checkout; off closes /cart (and /checkout) and removes every add-to-cart button and the cart icon.
        'cart_enabled' => true,
        // Off: guests may check out; on: every order needs a signed-in customer (enforced when the order is placed).
        'checkout_requires_account' => false,
        'brand_landing_pages' => true,
        // Storefront product prices always include tax; on adds the price without tax beneath them.
        'show_prices_without_tax' => false,
        // Products created within this many days count as new for the storefront "newest" product blocks.
        'new_products_days' => 90,
        'bestsellers_days' => 0,
        'bestsellers_min_units' => 1,
        'bestsellers_min_orders' => 1,
        'reels_days' => 90,
        'comparison_max_products' => 4,
    ];

    public const PRODUCT_CARD_STYLES = ['classic', 'showcase', 'signature'];

    public const DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y'];

    public const TIME_FORMATS = ['H:i', 'h:i A'];

    /** @return list<string> */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
