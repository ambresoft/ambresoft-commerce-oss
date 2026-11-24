<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Command\IndexableUrl;
use App\Storefront\Application\Contract\StorefrontUrls;
use App\Website\Domain\Service\RedirectRules;
use App\Website\Infrastructure\RedirectMap;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Core owns public visibility and addresses; add-ons own indexing artifacts and their publication. */
final class IndexableStorefrontUrls implements StorefrontUrls
{
    /** @var array<string,bool> */
    private array $layouts = [];

    public function origin(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
            throw new RuntimeException('Configure APP_URL as the canonical storefront origin.');
        }

        return $origin;
    }

    public function urls(string $section, ?string $sourceId = null): iterable
    {
        $settings = app(GeneralSettings::class)->all();
        if (! $settings['storefront_open'] || $settings['storefront_noindex']) {
            return;
        }
        $locale = app(LocaleCatalog::class)->defaultCode();
        $this->layouts = DB::table('storefront_layouts')->pluck('indexable', 'page_type')->all();
        $rows = match ($section) {
            'homepage' => $this->homepage(),
            'products' => $this->products($locale, $sourceId),
            'categories' => $this->categories($locale, $sourceId),
            'pages' => $this->pages($locale, $sourceId),
            'brands' => $this->brands($locale, $sourceId),
            'blogs', 'posts' => $this->blogs($section, $locale, $sourceId),
            default => throw new InvalidArgumentException('Unknown public content section.'),
        };
        $claims = $section === 'homepage' || $section === 'categories' ? [] : app(CategoryUrls::class)->paths(app(PublicCatalog::class)->categories()->pluck('categories.id')->map(fn ($id): string => (string) $id)->all(), $locale);
        $claimed = array_fill_keys(array_values($claims), true);
        $origin = $this->origin();
        foreach ($rows as [$id, $path, $lastmod]) {
            if ($path === null || $path === '' || ($sourceId !== null && $id !== $sourceId) || isset($claimed[$path])) {
                continue;
            }
            if ($path !== '/' && (RedirectRules::reserved($path) || ! app('router')->getRoutes()->match(Request::create($origin.$path))->isFallback)) {
                continue;
            }
            $redirect = app(RedirectMap::class)->resolve(RedirectRules::path($path) ?? $path);
            if ($redirect !== null && RedirectRules::internalPath($redirect[0]) !== $path) {
                continue;
            }
            $date = $lastmod === null ? null : (new DateTimeImmutable((string) $lastmod))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            yield new IndexableUrl($section, $id, $origin.$path, $date);
        }
    }

    private function indexable(string $layout): bool
    {
        return ($this->layouts[$layout] ?? true) !== false;
    }

    /** @return iterable<array{string,?string,?string}> */
    private function homepage(): iterable
    {
        if ($this->indexable('homepage')) {
            yield ['1', '/', DB::table('storefront_layouts')->whereIn('page_type', ['default', 'homepage'])->max('updated_at')];
        }
    }

    /** Categories whose entire ancestry is guest-discoverable without an age challenge.
     * @return list<string> */
    private function categoryIds(): array
    {
        $rows = app(PublicCatalog::class)->categories()->get(['categories.id', 'categories.parent_id', 'categories.exclude_from_discovery', 'categories.exclude_from_sitemap', 'categories.age_verification', 'categories.age_limit'])->keyBy('id');

        return $rows->filter(function (object $row) use ($rows): bool {
            $seen = [];
            for ($node = $row; $node !== null; $node = $rows[$node->parent_id] ?? null) {
                if (isset($seen[$node->id]) || $node->exclude_from_discovery || $node->exclude_from_sitemap || ($node->age_verification && $node->age_limit !== null)) {
                    return false;
                }
                $seen[$node->id] = true;
            }

            return true;
        })->keys()->map(fn ($id): string => (string) $id)->all();
    }

    private function filter(Builder $q, ?string $id, string $column = 'id'): Builder
    {
        return $id === null ? $q : $q->where($column, $id);
    }

    /** @return iterable<array{string,?string,?string}> */
    private function products(string $locale, ?string $id): iterable
    {
        if (! $this->indexable('products')) {
            return;
        }
        $settings = app(GeneralSettings::class)->all();
        $q = app(PublicCatalog::class)->products()->where('products.exclude_from_sitemap', false)->whereIn('products.category_id', $this->categoryIds())->select(['products.id', 'products.category_id', 'products.quantity', 'products.available_from', 'products.out_of_stock_actions', 'products.min_qty', 'products.max_qty', 'products.qty_step', 'products.updated_at']);
        $rules = app(ProductRules::class);
        foreach ($this->filter($q, $id, 'products.id')->orderBy('products.id')->lazyById(500, 'products.id', 'id')->chunk(500) as $batch) {
            $urls = app(ProductPage::class)->addresses($batch->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(), $locale);
            $dates = DB::table('product_translations')->whereIn('product_id', $batch->pluck('id'))->selectRaw('product_id, max(updated_at) as modified')->groupBy('product_id')->pluck('modified', 'product_id');
            foreach ($batch as $p) {
                $quantity = (int) ($p->min_qty ?? $p->qty_step ?? 1);
                if (($p->available_from !== null && $p->available_from > now()->timezone($settings['timezone'])->toDateString()) || ! $rules->canPurchase($quantity, (int) $p->quantity, $rules->effectiveOutOfStockAction($p->out_of_stock_actions, $settings['out_of_stock_action']), $p->min_qty, $p->max_qty, $p->qty_step, $settings['track_inventory'])) {
                    continue;
                }
                yield [(string) $p->id, $urls[$p->id] ?? null, $this->latest($p->updated_at, $dates[$p->id] ?? null)];
            }
        }
    }

    /** @return iterable<array{string,?string,?string}> */
    private function categories(string $locale, ?string $id): iterable
    {
        $q = DB::table('categories')->whereIn('id', $this->categoryIds());
        foreach ($this->filter($q, $id)->orderBy('id')->lazyById(500)->chunk(500) as $batch) {
            $urls = app(CategoryUrls::class)->paths($batch->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(), $locale);
            $dates = DB::table('category_translations')->whereIn('category_id', $batch->pluck('id'))->selectRaw('category_id, max(updated_at) as modified')->groupBy('category_id')->pluck('modified', 'category_id');
            foreach ($batch as $row) {
                if ($this->indexable($row->is_landing_page ? 'category_landing' : 'category')) {
                    yield [(string) $row->id, $urls[$row->id] ?? null, $this->latest($row->updated_at, $dates[$row->id] ?? null)];
                }
            }
        }
    }

    /** @return iterable<array{string,?string,?string}> */
    private function pages(string $locale, ?string $id): iterable
    {
        $q = DB::table('pages')->join('storefront_layouts as l', 'l.page_id', 'pages.id')->where('pages.status', 'active')->where('l.indexable', true);
        foreach ($this->filter($q, $id, 'pages.id')->orderBy('pages.id')->select('pages.id', 'pages.updated_at')->lazyById(500, 'pages.id', 'id')->chunk(500) as $batch) {
            $pages = app(StorefrontPages::class);
            $visible = $pages->visible($batch->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(), null);
            $urls = $pages->addresses($visible, $locale);
            foreach ($batch as $row) {
                if (isset($urls[$row->id])) {
                    yield [(string) $row->id, $urls[$row->id], $row->updated_at];
                }
            }
        }
    }

    /** @return iterable<array{string,?string,?string}> */
    private function brands(string $locale, ?string $id): iterable
    {
        if (! $this->indexable('catalog_landing')) {
            return;
        }
        $landings = app(CatalogLandingPages::class);
        if (app(GeneralSettings::class)->all()['brand_landing_pages']) {
            $q = app(BrandQueries::class)->named($locale)->where('b.status', 'active');
            if ($id !== null) {
                $q->where('b.id', str_replace('brand:', '', $id));
            }
            $rows = $q->orderBy('b.id')->get();
            $paths = $landings->brandUrls($rows->pluck('code')->all());
            foreach ($rows as $row) {
                if (trim($row->name) === '') {
                    continue;
                }
                $path = $paths[$row->code] ?? null;
                yield ['brand:'.$row->id, $path, $row->updated_at];
            }

            return;
        }
        $q = DB::table('feature_options as o')->join('features as f', 'f.id', 'o.feature_id')->where('f.purpose', 'landing_pages')->where('f.status', 'active')->where('o.status', 'active');
        if ($id !== null) {
            $q->where('o.id', str_replace('option:', '', $id));
        }
        $texts = app(BlockContent::class);
        foreach ($q->get(['o.id']) as $row) {
            $slug = $texts->text($texts->translations('feature_option_translations', 'option_id', [(string) $row->id])[$row->id] ?? [], $locale, 'slug');
            $found = $landings->resolve('/'.$slug, $locale);
            if (($found['id'] ?? null) === (string) $row->id) {
                yield ['option:'.$row->id, $found['url'], null];
            }
        }
    }

    /** @return iterable<array{string,?string,?string}> */
    private function blogs(string $section, string $locale, ?string $id): iterable
    {
        if (! $this->indexable($section === 'blogs' ? 'blog' : 'blog_post')) {
            return;
        }
        $blogs = app(StorefrontBlogs::class);
        $parents = $blogs->blogs()->where('blogs.status', 'active')->pluck('blogs.id')->map(fn ($id): string => (string) $id)->all();
        $q = $section === 'blogs' ? $blogs->blogs()->whereIn('blogs.id', $parents) : $blogs->posts($parents, true);
        $table = $section === 'blogs' ? 'blogs' : 'blog_posts';
        foreach ($this->filter($q, $id, $table.'.id')->orderBy($table.'.id')->select($table.'.id', $table.'.updated_at')->lazyById(500, $table.'.id', 'id')->chunk(500) as $batch) {
            $urls = $blogs->addresses($batch->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(), $locale, $section === 'posts');
            foreach ($batch as $row) {
                yield [(string) $row->id, $urls[$row->id] ?? null, $row->updated_at];
            }
        }
    }

    private function latest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }
}
