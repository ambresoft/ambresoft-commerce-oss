<?php

namespace App\Storefront\Infrastructure;

use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Support\Facades\Log;

final class StorefrontWidgets
{
    /** The pages a widget may appear on. Anything else gets none: account, checkout, payment, order tracking and sign-in. */
    public const PAGES = ['foundation', 'storefront.product', 'storefront.search', 'storefront.new', 'storefront.bestsellers', 'storefront.offers', 'storefront.brands', 'storefront.sitemap', 'comparison', 'cart'];

    /**
     * Widgets of loaded, enabled add-ons for the current page.
     *
     * @return list<array{addon: string, src: string, title: string}>
     */
    public function current(): array
    {
        $request = request();
        if ($request->is('admin', 'admin/*') || ! $request->routeIs(...self::PAGES) || ! app(StorefrontAccess::class)->allowed(request())) {
            return [];
        }
        $widgets = [];
        foreach (app(StorefrontRegistry::class)->widgets() as $addon => $widget) {
            if ($this->allowed($addon, $widget['src'])) {
                $widgets[] = ['addon' => $addon, 'src' => $this->address($widget['src']), 'title' => __($widget['title'])];
            }
        }

        return $widgets;
    }

    /**
     * The frame page of a loaded, enabled add-on at this exact path.
     *
     * @return array{addon: string, src: string, title: string}|null
     */
    public function page(string $path): ?array
    {
        foreach (app(StorefrontRegistry::class)->framePages() as $addon => $page) {
            if ('/'.$page['slug'] === $path && $this->allowed($addon, $page['src'])) {
                return ['addon' => $addon, 'src' => $this->address($page['src']), 'title' => __($page['title'])];
            }
        }

        return null;
    }

    /**
     * Links to the frame pages visitors can reach right now, for the store's header.
     *
     * @return list<array{addon: string, url: string, title: string}>
     */
    public function pageLinks(): array
    {
        if (! app(StorefrontAccess::class)->allowed(request())) {
            return [];
        }
        $links = [];
        foreach (app(StorefrontRegistry::class)->framePages() as $addon => $page) {
            if ($this->allowed($addon, $page['src'])) {
                $links[] = ['addon' => $addon, 'url' => '/'.$page['slug'], 'title' => __($page['title'])];
            }
        }

        return $links;
    }

    private function allowed(string $addon, string $src): bool
    {
        $runtime = app(AddonRuntime::class);
        if (! $runtime->isLoaded($addon) || ! $runtime->isEnabled($addon)) {
            return false;
        }
        // The sandbox allows scripts and the frame's own origin: served from the store's host that would be no sandbox.
        if (parse_url($src, PHP_URL_HOST) === request()->getHost()) {
            Log::warning('storefront_widget_refused', ['addon' => $addon, 'reason' => 'same_host']);

            return false;
        }

        return true;
    }

    private function address(string $src): string
    {
        $colors = app(ThemeColors::class)->all();

        return strtr($src, ['{locale}' => rawurlencode(app()->getLocale()), '{primary}' => rawurlencode($colors['primary']), '{on_primary}' => rawurlencode($colors['on_primary'])]);
    }
}
