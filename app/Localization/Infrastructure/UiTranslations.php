<?php

namespace App\Localization\Infrastructure;

use Illuminate\Support\Arr;
use LogicException;

final class UiTranslations
{
    /** @var list<string> */
    private array $groups = ['checkout', 'brand_import', 'product_import', 'cache', 'account_security', 'packages', 'reels', 'notifications', 'code_editor', 'brands', 'common', 'admin', 'navigation', 'auth', 'users', 'roles', 'permissions', 'categories', 'category_import', 'region_import', 'languages', 'products', 'reviews', 'sales', 'availability', 'currencies', 'features', 'variations', 'media', 'settings', 'company', 'menus', 'taxes', 'customers', 'account', 'carts', 'orders', 'calls', 'payments', 'shipping', 'newsletters', 'coupons', 'layouts', 'storefront', 'stickers', 'promotions', 'giftcards', 'social_login', 'reports', 'search', 'addons', 'geography', 'banners', 'pages', 'blogs', 'seo_rules', 'faqs', 'ai', 'editor', 'audit'];

    /** @return list<string> */
    public function groups(): array
    {
        return [...$this->groups, 'authorization', 'validation'];
    }

    // Enabled module providers may explicitly opt a bounded UI group into the payload.
    public function addGroup(string $group): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*::[a-z][a-z0-9_]*$/D', $group) !== 1 || in_array($group, $this->groups, true)) {
            throw new LogicException('UI translation groups require a unique trusted module namespace.');
        }
        $this->groups[] = $group;
    }

    /** @return array<string, string> */
    public function forLocale(string $locale): array
    {
        $strings = [];
        $fallbacks = array_reverse(array_unique([$locale, app(LocaleCatalog::class)->defaultCode(), 'en']));
        foreach ($this->groups as $group) {
            foreach ($fallbacks as $candidate) {
                $values = app('translator')->get($group, [], $candidate, false);
                if (is_array($values)) {
                    foreach (Arr::dot($values) as $key => $value) {
                        if (is_string($value)) {
                            $strings[$group.'.'.$key] = $value;
                        }
                    }
                }
            }
        }

        return $strings;
    }
}
