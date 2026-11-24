<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Website\Infrastructure\PageContentSanitizer;
use Illuminate\Support\Facades\DB;

/**
 * Static pages (Website → Pages) on the storefront. A page is served at its SEO name (/privacy-policy) once a
 * merchant-added layout is linked to it; that layout supplies everything around the page. Visitors see a page that
 * is active or hidden (hidden: reachable by its address only), inside its availability period and, when it is
 * limited to customer groups, only as a member. Staff previewing a page see it whatever its state.
 */
final class StorefrontPages
{
    /**
     * The page at this address and the layout linked to it, if a visitor (or a previewing staff member) may see it.
     *
     * @return array{page:string,layout:string}|null
     */
    public function resolve(string $path, ?string $customer, bool $preview = false): ?array
    {
        $slug = rawurldecode(trim($path, '/'));
        if ($slug === '' || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1) {
            return null;
        }
        $q = DB::table('pages')->join('page_translations as t', 't.page_id', '=', 'pages.id')->join('storefront_layouts as l', 'l.page_id', '=', 'pages.id')
            ->where('t.slug', $slug);
        if (! $preview) {
            $q->whereIn('pages.status', ['active', 'hidden'])
                ->where(fn ($q) => $q->whereNull('pages.available_from')->orWhere('pages.available_from', '<=', now()))
                ->where(fn ($q) => $q->whereNull('pages.available_until')->orWhere('pages.available_until', '>', now()));
            $catalog = app(PublicCatalog::class);
            $catalog->restrict($q, 'page_customer_groups', 'page_id', 'pages.id', $catalog->groups($customer));
        }
        $row = $q->orderBy('pages.id')->first(['pages.id', 'l.page_type']);

        return $row === null ? null : ['page' => (string) $row->id, 'layout' => (string) $row->page_type];
    }

    /**
     * The pages among these a visitor may see, as resolve() decides (active or hidden, available, allowed groups).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function visible(array $ids, ?string $customer): array
    {
        if ($ids === []) {
            return [];
        }
        $q = DB::table('pages')->whereIn('pages.id', $ids)->whereIn('pages.status', ['active', 'hidden'])
            ->where(fn ($q) => $q->whereNull('pages.available_from')->orWhere('pages.available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('pages.available_until')->orWhere('pages.available_until', '>', now()));
        $catalog = app(PublicCatalog::class);
        $catalog->restrict($q, 'page_customer_groups', 'page_id', 'pages.id', $catalog->groups($customer));
        $found = $q->pluck('pages.id')->map(strval(...))->all();

        return array_values(array_filter($ids, fn (string $id): bool => in_array($id, $found, true)));
    }

    public static function path(string $slug): string
    {
        return '/'.$slug;
    }

    /** @param list<string> $ids
     * @return array<string,string> */
    public function addresses(array $ids, string $locale): array
    {
        $content = app(BlockContent::class);
        $urls = [];
        foreach ($content->translations('page_translations', 'page_id', $ids) as $id => $texts) {
            $slug = $content->text($texts, $locale, 'slug');
            if ($slug !== '' && $content->text($texts, $locale, 'name') !== '') {
                $urls[(string) $id] = self::path($slug);
            }
        }

        return $urls;
    }

    /** @return array{id:string,name:string,content:string,url:string,faqs:list<array{id:string,question:string,answer:string}>,seo:array{title:string,description:string,keywords:string}} */
    public function data(string $id, string $locale): array
    {
        $content = app(BlockContent::class);
        $texts = $content->translations('page_translations', 'page_id', [$id])[$id] ?? [];
        $text = fn (string $field): string => $content->text($texts, $locale, $field);
        // Stored content is sanitized on save; the page policy runs again on the way out, like product descriptions.
        $html = app(PageContentSanitizer::class)->sanitize($text('content'));

        return ['id' => $id, 'name' => $text('name'), 'content' => trim(strip_tags($html, '<img><iframe>')) === '' ? '' : $html, 'url' => self::path($text('slug')),
            'faqs' => app(StorefrontFaqs::class)->for('page', $id, $locale),
            'seo' => ['title' => $text('seo_title') ?: $text('name'), 'description' => $text('meta_description'), 'keywords' => $text('meta_keywords')]];
    }
}
