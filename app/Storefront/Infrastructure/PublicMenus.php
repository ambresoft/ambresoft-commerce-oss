<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Support\Facades\DB;

/** Public menu trees: active menus and items only, links to categories only when the visitor may see them. */
final class PublicMenus
{
    private const MAX_NODES = 400;

    /** Levels below a generated (category) item or a category menu's top level. */
    private const GENERATED_DEPTH = 3;

    private const MAX_MANUAL_DEPTH = 4;

    private int $nodes = 0;

    /** @param list<string> $ids
     * @return array<string,list<array<string,mixed>>> */
    public function trees(array $ids, string $locale, ?string $customer): array
    {
        return $customer === null
            ? app(StorefrontCache::class)->remember('blocks', ['menus', $ids, $locale], fn () => $this->liveTrees($ids, $locale, null))
            : $this->liveTrees($ids, $locale, $customer);
    }

    /** @param list<string> $ids
     * @return array<string,list<array<string,mixed>>> */
    private function liveTrees(array $ids, string $locale, ?string $customer): array
    {
        $menus = $ids === [] ? [] : DB::table('website_menus')->whereIn('id', $ids)->where('status', 'active')->pluck('source', 'id')->all();
        $styles = $menus === [] ? [] : DB::table('website_menus')->whereIn('id', array_keys($menus))->pluck('style', 'id')->all();
        if ($menus === []) {
            return [];
        }
        $codes = array_values(array_unique([$locale, app(LocaleCatalog::class)->defaultCode(), 'en']));
        $pick = function (array $byLocale) use ($codes): string {
            foreach ($codes as $code) {
                if (trim((string) ($byLocale[$code] ?? '')) !== '') {
                    return (string) $byLocale[$code];
                }
            }

            return '';
        };
        $localeCodes = DB::table('locales')->pluck('code', 'id');
        $base = app(PublicCatalog::class)->categories($customer);

        $items = DB::table('website_menu_items')->whereIn('menu_id', array_keys(array_filter($menus, fn ($s) => $s === 'manual')))->where('status', 'active')->orderBy('position')->orderBy('id')->get();
        $itemNames = [];
        foreach (DB::table('website_menu_item_translations')->whereIn('item_id', $items->pluck('id'))->get() as $t) {
            $itemNames[$t->item_id][$localeCodes[$t->locale_id]] = $t->name;
        }

        // Categories used by category items, plus generated descendants (levels are fetched together).
        $seeds = $items->pluck('category_id')->filter()->map(strval(...))->unique()->values()->all();
        $generatedRoots = $items->where('generate_children', true)->pluck('category_id')->filter()->map(strval(...))->all();
        $categoryMenuRoots = in_array('categories', $menus, true) ? (clone $base)->whereNull('categories.parent_id')->orderBy('categories.position')->orderBy('categories.id')->limit(50)->pluck('categories.id')->map(strval(...))->all() : [];
        $allowed = $seeds === [] ? [] : (clone $base)->whereIn('categories.id', $seeds)->pluck('categories.id')->map(strval(...))->all();
        $childrenOf = [];
        $level = array_values(array_unique([...$generatedRoots, ...$categoryMenuRoots]));
        $known = [...$allowed, ...$categoryMenuRoots];
        for ($depth = 0; $level !== [] && $depth < self::GENERATED_DEPTH && count($known) < self::MAX_NODES; $depth++) {
            $found = (clone $base)->whereIn('categories.parent_id', $level)->orderBy('categories.position')->orderBy('categories.id')->get(['categories.id', 'categories.parent_id']);
            $level = [];
            foreach ($found as $row) {
                $childrenOf[(string) $row->parent_id][] = (string) $row->id;
                $level[] = (string) $row->id;
                $known[] = (string) $row->id;
            }
        }
        $categoryIds = array_values(array_unique($known));
        $categories = $categoryIds === [] ? collect() : DB::table('categories')->whereIn('id', $categoryIds)->get(['id', 'image_media_id', 'menu_icon_media_id'])->keyBy('id');
        $categoryNames = [];
        foreach ($categoryIds === [] ? [] : DB::table('category_translations')->whereIn('category_id', $categoryIds)->get(['category_id', 'locale_id', 'name']) as $t) {
            $categoryNames[$t->category_id][$localeCodes[$t->locale_id]] = $t->name;
        }
        $urls = app(CategoryUrls::class)->paths($categoryIds, $locale);
        $media = app(PublicMedia::class);
        $images = $media->images([...$items->pluck('image_media_id')->all(), ...$categories->pluck('image_media_id')->all(), ...$categories->pluck('menu_icon_media_id')->all()]);

        // A category's menu picture: its menu icon, then the picture of the menu entry itself (when the entry has one),
        // then the category's own image. Only pictures that are actually available count.
        $pictures = function (string $category, ?int $own) use ($categories, $images): ?int {
            foreach ([$categories[$category]->menu_icon_media_id ?? null, $own, $categories[$category]->image_media_id ?? null] as $candidate) {
                if ($candidate !== null && isset($images[$candidate])) {
                    return (int) $candidate;
                }
            }

            return null;
        };
        $this->nodes = 0;
        $categoryNode = function (string $id, int $depth) use (&$categoryNode, $childrenOf, $pick, $categoryNames, $urls, $images, $media, $pictures): ?array {
            $name = $pick($categoryNames[$id] ?? []);
            if ($name === '' || $this->nodes >= self::MAX_NODES) {
                return null;
            }
            $this->nodes++;
            $mediaId = $pictures($id, null);
            $children = [];
            if ($depth > 1) {
                foreach ($childrenOf[$id] ?? [] as $child) {
                    if ($node = $categoryNode($child, $depth - 1)) {
                        $children[] = $node;
                    }
                }
            }

            return ['id' => 'c'.$id, 'name' => $name, 'url' => $urls[$id] ?? null, 'new_window' => false, 'image' => $media->url($images[$mediaId] ?? null, 'category', $id), 'children' => $children];
        };

        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item->menu_id][$item->parent_id ?? 0][] = $item;
        }
        $itemNode = function (object $item, int $depth) use (&$itemNode, $styles, $byParent, $pick, $itemNames, $allowed, $urls, $images, $media, $categoryNode, $childrenOf, $pictures): ?array {
            $name = $pick($itemNames[$item->id] ?? []);
            $category = $item->category_id === null ? null : (string) $item->category_id;
            if ($name === '' || $this->nodes >= self::MAX_NODES || ($item->kind === 'category' && ! in_array($category, $allowed, true))
                || ($item->kind === 'url' && ! MenuRules::safeUrl((string) $item->url))) {
                return null;
            }
            $this->nodes++;
            $children = [];
            if ($item->generate_children && $category !== null) {
                foreach ($childrenOf[$category] ?? [] as $child) {
                    if ($node = $categoryNode($child, self::GENERATED_DEPTH)) {
                        $children[] = $node;
                    }
                }
            } elseif ($depth < self::MAX_MANUAL_DEPTH) {
                foreach ($byParent[$item->menu_id][$item->id] ?? [] as $child) {
                    if ($node = $itemNode($child, $depth + 1)) {
                        $children[] = $node;
                    }
                }
            }
            // A heading needs links under it, except in link-groups menus where a second-level heading is a plain text line.
            if ($item->kind === 'heading' && $children === [] && ! (($styles[$item->menu_id] ?? '') === 'link_groups' && $depth > 1)) {
                return null;
            }

            return ['id' => 'i'.$item->id, 'name' => $name, 'url' => match ($item->kind) {
                'url' => $item->url, 'category' => $urls[$category] ?? null, default => null,
            }, 'new_window' => (bool) $item->new_window, 'image' => $this->itemImage($item, $category, $images, $media, $pictures), 'children' => $children];
        };

        $result = [];
        foreach ($menus as $id => $source) {
            $tree = [];
            if ($source === 'categories') {
                foreach ($categoryMenuRoots as $root) {
                    if ($node = $categoryNode($root, self::GENERATED_DEPTH + 1)) {
                        $tree[] = $node;
                    }
                }
            } else {
                foreach ($byParent[$id][0] ?? [] as $item) {
                    if ($node = $itemNode($item, 1)) {
                        $tree[] = $node;
                    }
                }
            }
            $result[(string) $id] = $tree;
        }

        return $result;
    }

    /**
     * A menu entry's picture: for a category entry the category's menu icon wins, then the entry's own picture, then the
     * category's image; any other entry shows its own picture.
     *
     * @param  array<int|string, array<string, mixed>>  $images
     * @return array<string, mixed>|null
     */
    private function itemImage(object $item, ?string $category, array $images, PublicMedia $media, \Closure $pictures): ?array
    {
        if ($item->kind === 'category' && $category !== null) {
            $picture = $pictures($category, $item->image_media_id === null ? null : (int) $item->image_media_id);
            if ($picture !== null && $picture !== (int) $item->image_media_id) {
                // The icon or the category image, served as the category's picture.
                return $media->url($images[$picture] ?? null, 'category', $category);
            }
        }

        return $media->url($images[$item->image_media_id] ?? null, 'menu-item', (string) $item->id);
    }
}
