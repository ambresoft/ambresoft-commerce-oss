<?php

namespace App\Storefront\Infrastructure;

use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Http\Request;

/** Shared indexing policy for response headers, the HTML shell and storefront SEO. */
final class SearchEngineVisibility
{
    /** Paths that are never public content: the admin area and the identity pages outside it. */
    private const PATHS = ['admin', 'admin/*', 'login', 'register', 'forgot-password', 'reset-password/*', 'email/*', 'logout', 'account', 'account/*', 'wishlist', 'wishlists/*', 'comparison', 'comparison/*', 'cart', 'cart/*', 'checkout', 'checkout/*', 'search', 'search/*', 'order-status', 'orders/*/cancel', 'pay/*', 'newsletter/*', 'locale/*', 'storefront/products/*/documents/*'];

    public static function applies(Request $request): bool
    {
        return $request->is(...self::PATHS);
    }

    public static function storefrontIndexingDisabled(Request $request): bool
    {
        if ($request->is('admin', 'admin/*')) {
            return false;
        }
        $settings = app(GeneralSettings::class)->all();

        return $settings['storefront_noindex'] || ! $settings['storefront_open'];
    }
}
