<?php

namespace App\Storefront\Infrastructure;

use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Contract\BlockType;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Domain\Service\LayoutRules;
use App\Storefront\Domain\Service\PathRules;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class LayoutRenderer
{
    /**
     * @param  array<string,mixed>|null  $main  The page's own content for its main content block (the product on product pages).
     * @return array<string,mixed>
     */
    public function page(string $page, string $locale, ?string $customer, ?string $path = null, ?array $main = null): array
    {
        $registry = app(StorefrontRegistry::class);
        $themeId = config('storefront.theme', 'default');
        $theme = $registry->activeTheme($themeId);
        $definition = $registry->definition($page) ?? abort(404);
        $local = app(LayoutStore::class)->read($page, true);
        $shared = $definition['inherits'] ? app(LayoutStore::class)->read('default', true) : ['grids' => [], 'placements' => []];
        $overrides = array_column($local['overrides'], null, 'placement_id');
        foreach ($shared['placements'] as &$p) {
            $override = $overrides[$p['id']] ?? null;
            if ($override !== null) {
                $p['enabled'] = $override['enabled'];
                $p['block'] = $override['block'] ?? $p['block'];
            }
        }
        unset($p);
        $grids = [...$shared['grids'], ...$local['grids']];
        try {
            app(LayoutRules::class)->validate($shared['grids'], $theme['regions']);
            app(LayoutRules::class)->validate($local['grids'], $theme['regions']);
        } catch (\DomainException) {
            return ['theme' => $themeId, 'colors' => app(ThemeColors::class)->all(), 'blend_images' => (bool) app(GeneralSettings::class)->all()['blend_product_images'], 'card_style' => app(GeneralSettings::class)->all()['product_card_style'], 'signed_in' => $customer !== null, 'cart_count' => app(CurrentCart::class)->count(request()), 'cart_enabled' => (bool) app(GeneralSettings::class)->all()['cart_enabled'], 'wishlist_enabled' => (bool) app(GeneralSettings::class)->all()['wishlist_enabled'], 'reviews_enabled' => (bool) app(GeneralSettings::class)->all()['reviews_enabled'], 'max_width' => app(GeneralSettings::class)->all()['site_max_width'], 'show_back_to_top' => (bool) app(GeneralSettings::class)->all()['show_back_to_top'], 'regions' => []];
        }
        $byType = [];
        $placements = [];
        foreach ([...$shared['placements'], ...$local['placements']] as $p) {
            $b = $p['block'];
            $type = $registry->type($b['type']);
            if (! $p['enabled'] || $type === null || ! in_array($b['template'], $theme['templates'], true) || ! in_array($b['template'], $type->definition()['templates'], true)) {
                continue;
            }
            try {
                $b['settings'] = $this->settings($type, $b['settings']);
            } catch (\Throwable) {
                continue;
            }
            // Blocks can be hidden on chosen pages (for example the header on /checkout).
            if ($path !== null && ($b['settings']['hidden_paths'] ?? '') !== '' && app(PathRules::class)->matches($path, $b['settings']['hidden_paths'])) {
                continue;
            }
            $byType[$b['type']][$b['id']] = $b;
            $placements[] = $p;
        }
        $content = [];
        foreach ($byType as $type => $blocks) {
            try {
                $content += $registry->type($type)->resolveMany($blocks, ['locale' => $locale, 'customer' => $customer, 'main' => $main]);
            } catch (\Throwable $e) {
                Log::warning('Storefront block resolver failed', ['type' => $type, 'exception' => get_class($e)]);
            }
        }
        $byGrid = [];
        foreach ($placements as $p) {
            $b = $p['block'];
            $payload = $content[$b['id']] ?? [];
            if ($payload === []) {
                continue;
            }
            $byGrid[$p['grid_id']][] = ['id' => $p['id'], 'template' => $b['template'], 'title' => app(BlockContent::class)->text($b['translations'], $locale, 'title'), 'css_class' => preg_match('/^[a-zA-Z0-9_ -]{0,128}$/D', $b['css_class']) ? $b['css_class'] : '', 'mobile' => (bool) $p['mobile'], 'tablet' => (bool) $p['tablet'], 'desktop' => (bool) $p['desktop'], 'content' => $payload];
        }
        $regions = [];
        foreach ($theme['regions'] as $region) {
            $tree = $this->tree($grids, $byGrid, $region, null);
            if ($tree !== []) {
                $regions[$region] = $tree;
            }
        }

        return ['theme' => $themeId, 'colors' => app(ThemeColors::class)->all(), 'blend_images' => (bool) app(GeneralSettings::class)->all()['blend_product_images'], 'card_style' => app(GeneralSettings::class)->all()['product_card_style'], 'signed_in' => $customer !== null, 'cart_count' => app(CurrentCart::class)->count(request()), 'cart_enabled' => (bool) app(GeneralSettings::class)->all()['cart_enabled'], 'wishlist_enabled' => (bool) app(GeneralSettings::class)->all()['wishlist_enabled'], 'reviews_enabled' => (bool) app(GeneralSettings::class)->all()['reviews_enabled'], 'max_width' => app(GeneralSettings::class)->all()['site_max_width'], 'show_back_to_top' => (bool) app(GeneralSettings::class)->all()['show_back_to_top'], 'regions' => (object) $regions];
    }

    /**
     * A block's validated settings. A saved value its type no longer accepts (an option that was removed, a narrower
     * range) falls back to that setting's default, so the block still shows; staff saving the layout must still enter
     * valid values.
     *
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function settings(BlockType $type, array $settings): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $type->validate($settings);
            } catch (ValidationException $e) {
                $invalid = array_unique(array_map(fn (string $key): string => explode('.', $key)[0], array_keys($e->errors())));
                $remaining = array_diff_key($settings, array_flip($invalid));
                // Nothing left to drop (or a whole-settings error): the block stays hidden, as before.
                if ($attempt >= 3 || $remaining === $settings) {
                    throw $e;
                }
                $settings = $remaining;
            }
        }
    }

    /** @param list<array<string,mixed>> $grids
     * @param array<string,list<array<string,mixed>>> $blocks
     * @return list<array<string,mixed>> */
    private function tree(array $grids, array $blocks, string $region, ?string $parent): array
    {
        $nodes = array_filter($grids, fn ($g) => $g['region'] === $region && $g['parent_id'] === $parent);
        usort($nodes, fn ($a, $b) => [$a['row'], $a['position'], $a['id']] <=> [$b['row'], $b['position'], $b['id']]);
        $result = [];
        foreach ($nodes as $g) {
            $children = $this->tree($grids, $blocks, $region, $g['id']);
            $items = $blocks[$g['id']] ?? [];
            if ($children === [] && $items === []) {
                continue;
            }
            $result[] = ['id' => $g['id'], 'width' => $g['width'], 'row' => $g['row'], 'blocks' => $items, 'children' => $children];
        }

        return $result;
    }
}
