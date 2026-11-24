<?php

namespace App\Catalog\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use Illuminate\Support\Facades\DB;

/** Public storefront URLs of categories (slug paths from the top category down). */
final class CategoryUrls
{
    /** Storefront path of each category: the slugs of its ancestors and itself, e.g. /technologia/tilefonia/smartphones.
     * @param list<string> $ids
     * @return array<string,string> */
    public function paths(array $ids, string $locale): array
    {
        return app(StorefrontCache::class)->remember('categories', ['paths', $ids, $locale], fn () => $this->loadPaths($ids, $locale));
    }

    /** Storefront path of each category: the slugs of its ancestors and itself, e.g. /technologia/tilefonia/smartphones.
     * @param list<string> $ids
     * @return array<string,string> */
    private function loadPaths(array $ids, string $locale): array
    {
        $parents = [];
        $level = $ids;
        for ($depth = 0; $level !== [] && $depth < 20; $depth++) {
            $level = array_values(array_diff($level, array_keys($parents)));
            $found = DB::table('categories')->whereIn('id', $level)->pluck('parent_id', 'id')->all();
            $parents += $found;
            $level = array_values(array_unique(array_map(strval(...), array_filter($found))));
        }
        $default = app(LocaleCatalog::class)->defaultCode();
        $codes = DB::table('locales')->pluck('code', 'id');
        $slugs = [];
        foreach (DB::table('category_translations')->whereIn('category_id', array_keys($parents))->get(['category_id', 'locale_id', 'slug']) as $t) {
            $slugs[$t->category_id][$codes[$t->locale_id]] = $t->slug;
        }
        $slug = function (int|string $id) use ($slugs, $locale, $default): ?string {
            foreach (array_unique([$locale, $default, 'en']) as $code) {
                if (($slugs[$id][$code] ?? '') !== '') {
                    return $slugs[$id][$code];
                }
            }

            return null;
        };
        $out = [];
        foreach ($ids as $id) {
            $parts = [];
            for ($node = $id; $node !== null && count($parts) < 20; $node = $parents[$node] ?? null) {
                $part = $slug($node);
                if ($part === null) {
                    continue 2;
                }
                array_unshift($parts, rawurlencode($part));
            }
            $out[$id] = '/'.implode('/', $parts);
        }

        return $out;
    }
}
