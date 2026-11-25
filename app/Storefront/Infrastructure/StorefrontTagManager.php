<?php

namespace App\Storefront\Infrastructure;

use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Http\Request;

/**
 * Google Tag Manager (A147) and Google Analytics (A148) on public storefront pages only: the pages widgets may use. Private pages (account, checkout,
 * payment, order tracking, sign-in) never carry the container, and an in-app visit to one becomes a full page load so a
 * container loaded earlier stops running.
 */
final class StorefrontTagManager
{
    public const EVENTS = ['add_to_cart', 'remove_from_cart', 'add_to_wishlist', 'add_to_comparison', 'call_request'];

    /** First-party cookie holding the GA4 client and session IDs that gtag reported, for server-side purchases (A148). */
    public const ANALYTICS_COOKIE = 'cms_ga';

    /** @return array{container: string, dataLayer: string, events: array<string, string>}|null */
    public function current(Request $request): ?array
    {
        if (! $request->routeIs(...StorefrontWidgets::PAGES) || ! app(StorefrontAccess::class)->allowed($request)) {
            return null;
        }

        return $this->config();
    }

    /** @return array{measurementId: string, ecommerce: bool}|null */
    public function analytics(Request $request): ?array
    {
        if (! $request->routeIs(...StorefrontWidgets::PAGES) || ! app(StorefrontAccess::class)->allowed($request)) {
            return null;
        }

        return $this->analyticsConfig();
    }

    /**
     * The visitor's GA4 IDs as gtag reported them on a public page, or null when absent or malformed.
     *
     * @return array{clientId: string, sessionId: ?string}|null
     */
    public static function analyticsVisitor(Request $request): ?array
    {
        $value = $request->cookie(self::ANALYTICS_COOKIE);
        if (! is_string($value) || preg_match('/^(\d{1,20}\.\d{1,20})(?::(\d{1,20}))?$/D', $value, $m) !== 1) {
            return null;
        }

        return ['clientId' => $m[1], 'sessionId' => ($m[2] ?? '') === '' ? null : $m[2]];
    }

    /**
     * An in-app visit to a private storefront page from a document where third-party tags run (the storefront marks such
     * visits with X-Cms-Tags). A document without tags keeps normal visits, partial reloads and redirects.
     */
    public function needsFullLoad(Request $request): bool
    {
        return $request->isMethod('GET') && $request->header('X-Inertia') !== null && $request->header('X-Cms-Tags') === '1'
            && ! $request->routeIs(...StorefrontWidgets::PAGES) && ! $request->is('admin', 'admin/*');
    }

    /** @return array{measurementId: string, ecommerce: bool}|null */
    private function analyticsConfig(): ?array
    {
        $source = app(StorefrontRegistry::class)->googleAnalyticsSource();
        $runtime = app(AddonRuntime::class);
        if ($source === null || ! $runtime->isLoaded($source['addon']) || ! $runtime->isEnabled($source['addon'])) {
            return null;
        }
        $config = ($source['config'])();

        return $config === null || preg_match('/^G-[A-Z0-9]{4,15}$/D', $config['measurementId']) !== 1 ? null
            : ['measurementId' => $config['measurementId'], 'ecommerce' => $config['ecommerce']];
    }

    /** @return array{container: string, dataLayer: string, events: array<string, string>}|null */
    private function config(): ?array
    {
        $source = app(StorefrontRegistry::class)->googleTagManagerSource();
        $runtime = app(AddonRuntime::class);
        if ($source === null || ! $runtime->isLoaded($source['addon']) || ! $runtime->isEnabled($source['addon'])) {
            return null;
        }
        $config = ($source['config'])();
        // The values become a script address and a JavaScript global, so only the documented shapes pass.
        if ($config === null || preg_match('/^GTM-[A-Z0-9]{4,12}$/D', $config['container']) !== 1
            || preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/D', $config['dataLayer']) !== 1) {
            return null;
        }
        $events = array_filter($config['events'], fn (string $name, string $type): bool => in_array($type, self::EVENTS, true)
            && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $name) === 1, ARRAY_FILTER_USE_BOTH);

        return ['container' => $config['container'], 'dataLayer' => $config['dataLayer'], 'events' => $events];
    }
}
