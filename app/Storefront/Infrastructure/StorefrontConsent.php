<?php

namespace App\Storefront\Infrastructure;

use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Http\Request;

/**
 * Cookie consent (A149). While a consent manager add-on is active, a visitor's choice lives in the first-party
 * `cms_consent` cookie: `{consent id}.{version}.{preferences}{statistics}{marketing}.{unix time}`, each flag 0 or 1.
 * Necessary cookies need no consent. Consented scripts reach public pages only, like Google's tags (A147).
 */
final class StorefrontConsent
{
    public const COOKIE = 'cms_consent';

    public const CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];

    private const RESOLVED = 'storefront_consent_config';

    public function active(): bool
    {
        return $this->resolved() !== null;
    }

    /** @return array<string, mixed>|null The banner configuration for this page; scripts only on public pages */
    public function current(Request $request): ?array
    {
        $config = $this->resolved();
        if ($config === null || $request->is('admin', 'admin/*')) {
            return null;
        }
        if (! $request->routeIs(...StorefrontWidgets::PAGES) || ! app(StorefrontAccess::class)->allowed($request)) {
            $config['scripts'] = [];
        }

        return $config;
    }

    /**
     * Whether the visitor allowed a category. Without an active consent manager the merchant answers for consent
     * elsewhere, so nothing is withheld; with one, only a current, unexpired choice counts.
     */
    public function granted(Request $request, string $category): bool
    {
        $config = $this->resolved();
        if ($config === null || $category === 'necessary') {
            return true;
        }
        $choice = self::choice($request);
        $index = array_search($category, self::CATEGORIES, true);
        if ($choice === null || $index === false || $choice['version'] !== $config['version']
            || $choice['time'] < time() - $config['months'] * 30 * 86400) {
            return false;
        }

        return $choice['flags'][$index - 1] === '1';
    }

    /** @return array{id: string, version: int, flags: string, time: int}|null */
    public static function choice(Request $request): ?array
    {
        $value = $request->cookie(self::COOKIE);
        if (! is_string($value) || preg_match('/^([0-9a-f-]{36})\.(\d{1,9})\.([01]{3})\.(\d{10})$/D', $value, $m) !== 1) {
            return null;
        }

        return ['id' => $m[1], 'version' => (int) $m[2], 'flags' => $m[3], 'time' => (int) $m[4]];
    }

    /** @return array<string, mixed>|null */
    private function resolved(): ?array
    {
        // Resolved once per request; settings saved meanwhile apply to the next request.
        $attributes = request()->attributes;
        if ($attributes->has(self::RESOLVED)) {
            return $attributes->get(self::RESOLVED);
        }
        $source = app(StorefrontRegistry::class)->consentManagerSource();
        $runtime = app(AddonRuntime::class);
        $config = $source !== null && $runtime->isLoaded($source['addon']) && $runtime->isEnabled($source['addon']) ? ($source['config'])() : null;

        $config = is_array($config) && is_int($config['version'] ?? null) && is_int($config['months'] ?? null) ? $config : null;
        $attributes->set(self::RESOLVED, $config);

        return $config;
    }
}
