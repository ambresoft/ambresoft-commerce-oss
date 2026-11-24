<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Collator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Normalizer;

/** Live visitor-facing directories; visibility and URLs follow the existing storefront rules. */
final class DirectoryPages
{
    /** @return array<string,mixed> */
    public function sitemap(string $locale, ?string $customer): array
    {
        $categories = $this->categories($locale, $customer);
        $rootUrls = array_column($categories, 'url');
        $content = app(BlockContent::class);
        $candidates = DB::table('pages')->join('storefront_layouts as l', 'l.page_id', 'pages.id')
            ->where('pages.status', 'active')->orderBy('pages.position')->orderBy('pages.id')->pluck('pages.id')->map(strval(...))->all();
        $ids = app(StorefrontPages::class)->visible($candidates, $customer);
        $texts = $content->translations('page_translations', 'page_id', $ids);
        $pages = [];
        foreach ($ids as $id) {
            $name = $content->text($texts[$id] ?? [], $locale, 'name');
            $slug = $content->text($texts[$id] ?? [], $locale, 'slug');
            if ($name !== '' && $slug !== '' && ! in_array('/'.$slug, $rootUrls, true) && app('router')->getRoutes()->match(Request::create('/'.$slug))->isFallback) {
                $pages[] = ['name' => $name, 'url' => '/'.$slug];
            }
        }
        // Listed blogs (hidden ones are reachable by their address only); their posts are listed on each blog.
        $blogs = app(StorefrontBlogs::class)->blogs()->where('blogs.status', 'active')->orderBy('blogs.position')->orderBy('blogs.id')->pluck('blogs.id')->all();
        $blogTexts = $content->translations('blog_translations', 'blog_id', $blogs);
        foreach ($blogs as $id) {
            $slug = $content->text($blogTexts[$id] ?? [], $locale, 'slug');
            if ($slug !== '') {
                $pages[] = ['name' => $content->text($blogTexts[$id] ?? [], $locale, 'name'), 'url' => StorefrontBlogs::PREFIX.$slug];
            }
        }
        $pages[] = ['name' => __('storefront.brands_title'), 'url' => '/brands'];
        $settings = app(GeneralSettings::class)->all();
        $account = [['name' => __('storefront.account'), 'url' => '/account']];
        if ($settings['cart_enabled']) {
            $account[] = ['name' => __('storefront.cart_title'), 'url' => '/cart'];
            $account[] = ['name' => __('storefront.checkout_title'), 'url' => '/checkout'];
        }
        if ($settings['wishlist_enabled']) {
            $account[] = ['name' => __('storefront.wishlist_title'), 'url' => '/wishlist'];
        }

        return ['kind' => 'sitemap', 'pages' => $pages, 'account' => $account, 'categories' => $categories];
    }

    /** @return list<array<string,mixed>> */
    private function categories(string $locale, ?string $customer): array
    {
        $rows = app(PublicCatalog::class)->categories($customer)->orderBy('categories.position')->orderBy('categories.id')->get(['categories.id', 'categories.parent_id']);
        $ids = $rows->pluck('id')->map(strval(...))->all();
        $content = app(BlockContent::class);
        $texts = $content->translations('category_translations', 'category_id', $ids);
        $urls = app(CategoryUrls::class)->paths($ids, $locale);
        $children = [];
        foreach ($rows as $row) {
            $children[$row->parent_id ?? 0][] = (string) $row->id;
        }
        $tree = function (string $parent, int $depth = 0) use (&$tree, $children, $content, $texts, $urls, $locale): array {
            if ($depth >= 20) {
                return [];
            }
            $nodes = [];
            foreach ($children[$parent] ?? [] as $id) {
                $name = $content->text($texts[$id] ?? [], $locale, 'name');
                if ($name !== '' && isset($urls[$id])) {
                    $nodes[] = ['id' => $id, 'name' => $name, 'url' => $urls[$id], 'children' => $tree($id, $depth + 1)];
                }
            }

            return $nodes;
        };

        return $tree('0');
    }

    /** @return array<string,mixed> */
    public function brands(string $locale): array
    {
        $rows = app(BrandQueries::class)->named($locale)->where('b.status', 'active')->get()->filter(fn (object $b): bool => trim($b->name) !== '')->all();
        $collator = new Collator($locale);
        usort($rows, fn (object $a, object $b): int => $collator->compare($a->name, $b->name) ?: ($a->id <=> $b->id));
        $urls = app(CatalogLandingPages::class)->brandUrls(array_column($rows, 'code'));
        $groups = [];
        foreach ($rows as $brand) {
            $normalized = Normalizer::normalize(trim($brand->name), Normalizer::FORM_D) ?: trim($brand->name);
            $letter = mb_substr(mb_strtoupper(mb_substr($normalized, 0, 1)), 0, 1);
            if (preg_match('/^\p{L}$/u', $letter) !== 1) {
                $letter = '#';
            }
            $groups[$letter][] = ['id' => (string) $brand->id, 'name' => $brand->name, 'url' => $urls[$brand->code] ?? null];
        }
        uksort($groups, fn (string $a, string $b): int => $collator->compare($a, $b) ?: strcmp($a, $b));

        return ['kind' => 'brands', 'groups' => array_map(fn (string $letter, array $items): array => ['letter' => $letter, 'items' => $items], array_keys($groups), array_values($groups))];
    }
}
