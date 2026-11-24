<?php

namespace App\Storefront\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Infrastructure\PageContentSanitizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Blogs on the storefront: a blog at /blog/{blog} lists its posts; a post is at /blog/{blog}/{post}. Visitors see a blog that
 * is active or hidden and inside its availability; a post additionally needs a publication date that has passed, its
 * own active or hidden status and availability. Hidden items are reachable by address only and never listed.
 * Staff previewing see either whatever its state.
 */
final class StorefrontBlogs
{
    public const PER_PAGE = 12;

    /** Every blog address starts here: /blog/{blog} and /blog/{blog}/{post}. */
    public const PREFIX = '/blog/';

    /**
     * @return array{blog:string,post?:string}|null
     */
    public function resolve(string $path, bool $preview = false): ?array
    {
        if (! str_starts_with($path, self::PREFIX)) {
            return null;
        }
        $parts = explode('/', rawurldecode(trim(substr($path, strlen(self::PREFIX)), '/')));
        if (count($parts) > 2 || array_filter($parts, fn (string $slug): bool => preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1) !== []) {
            return null;
        }
        $blog = $this->blogs($preview)->join('blog_translations as t', 't.blog_id', '=', 'blogs.id')->where('t.slug', $parts[0])->orderBy('blogs.id')->value('blogs.id');
        if ($blog === null) {
            return null;
        }
        if (count($parts) === 1) {
            return ['blog' => (string) $blog];
        }
        $post = $this->posts([(string) $blog], false, $preview)->join('blog_post_translations as t', 't.post_id', '=', 'blog_posts.id')->where('t.slug', $parts[1])->orderBy('blog_posts.id')->value('blog_posts.id');

        return $post === null ? null : ['blog' => (string) $blog, 'post' => (string) $post];
    }

    public static function path(string $blogSlug, ?string $postSlug = null): string
    {
        return self::PREFIX.$blogSlug.($postSlug === null ? '' : '/'.$postSlug);
    }

    /** @param list<string> $ids
     * @return array<string,string> */
    public function addresses(array $ids, string $locale, bool $posts): array
    {
        $content = app(BlockContent::class);
        $rows = $posts ? DB::table('blog_posts')->whereIn('id', $ids)->pluck('blog_id', 'id')->all() : array_combine($ids, $ids);
        $blogs = $content->translations('blog_translations', 'blog_id', array_map(strval(...), array_values(array_unique($rows))));
        $texts = $posts ? $content->translations('blog_post_translations', 'post_id', $ids) : [];
        $urls = [];
        foreach ($rows as $id => $blog) {
            $blogSlug = $content->text($blogs[$blog] ?? [], $locale, 'slug');
            $postSlug = $posts ? $content->text($texts[$id] ?? [], $locale, 'slug') : null;
            if ($blogSlug !== '' && $postSlug !== '') {
                $urls[(string) $id] = self::path($blogSlug, $postSlug);
            }
        }

        return $urls;
    }

    /** Blogs a visitor may open (active or hidden, available). */
    public function blogs(bool $preview = false): Builder
    {
        return $preview ? DB::table('blogs') : $this->available(DB::table('blogs')->whereIn('blogs.status', ['active', 'hidden']), 'blogs');
    }

    /**
     * Posts of these blogs a visitor may open; listed ones exclude hidden posts.
     *
     * @param  list<string>  $blogs
     */
    public function posts(array $blogs, bool $listed, bool $preview = false): Builder
    {
        $q = DB::table('blog_posts')->whereIn('blog_posts.blog_id', $blogs);

        return $preview ? $q : $this->available($q->whereIn('blog_posts.status', $listed ? ['active'] : ['active', 'hidden'])->where('blog_posts.published_at', '<=', now()), 'blog_posts');
    }

    /** @return array<string,mixed> The blog with one page of its listed posts. */
    public function blog(string $id, string $locale, int $page = 1): array
    {
        $content = app(BlockContent::class);
        $texts = $content->translations('blog_translations', 'blog_id', [$id])[$id] ?? [];
        $text = fn (string $field): string => $content->text($texts, $locale, $field);
        $html = app(PageContentSanitizer::class)->sanitize($text('content'));
        $url = self::path($text('slug'));
        $posts = $this->posts([$id], true)->orderByDesc('published_at')->orderByDesc('id')->paginate(self::PER_PAGE, ['id'], 'page', $page);
        $items = array_values($this->cards($posts->pluck('id')->map(strval(...))->all(), $locale));

        return ['id' => $id, 'name' => $text('name'), 'content' => trim(strip_tags($html, '<img><iframe>')) === '' ? '' : $html, 'url' => $url,
            'posts' => $items, 'page' => $posts->currentPage(), 'last_page' => $posts->lastPage(),
            'seo' => ['title' => $text('seo_title') ?: $text('name'), 'description' => $text('meta_description'), 'keywords' => $text('meta_keywords')],
            'facebook_type' => (string) DB::table('blogs')->where('id', $id)->value('facebook_type')];
    }

    /**
     * List cards of these posts (name, address, excerpt, date, cover), keyed and ordered by id as given; the caller
     * decides which posts are visible.
     *
     * @param  list<string>  $ids
     * @return array<string,array<string,mixed>>
     */
    public function cards(array $ids, string $locale): array
    {
        $content = app(BlockContent::class);
        $rows = DB::table('blog_posts')->whereIn('id', $ids)->get(['id', 'blog_id', 'published_at', 'cover_media_id', 'cover_mobile_media_id'])->keyBy('id');
        $texts = $content->translations('blog_post_translations', 'post_id', $ids);
        $blogTexts = $content->translations('blog_translations', 'blog_id', $rows->pluck('blog_id')->unique()->all());
        $covers = app(PublicMedia::class)->images([...$rows->pluck('cover_media_id')->all(), ...$rows->pluck('cover_mobile_media_id')->filter()->values()->all()]);
        $cards = [];
        foreach ($ids as $id) {
            $post = $rows[$id] ?? null;
            $t = $texts[$id] ?? [];
            if ($post === null || $content->text($t, $locale, 'name') === '') {
                continue;
            }
            $cards[$id] = [
                'id' => $id, 'name' => $content->text($t, $locale, 'name'), 'url' => self::path($content->text($blogTexts[$post->blog_id] ?? [], $locale, 'slug'), $content->text($t, $locale, 'slug')),
                'excerpt' => $this->excerpt($t, $locale), 'published_at' => $this->iso($post->published_at),
                'cover' => app(PublicMedia::class)->url($covers[$post->cover_media_id] ?? null, 'blog-post', $id), 'cover_alt' => $content->text($t, $locale, 'cover_alt'),
                'cover_mobile' => app(PublicMedia::class)->url($covers[$post->cover_mobile_media_id] ?? null, 'blog-post', $id),
            ];
        }

        return $cards;
    }

    /** @return array<string,mixed> */
    public function post(string $id, string $locale): array
    {
        $row = DB::table('blog_posts')->where('id', $id)->first();
        $content = app(BlockContent::class);
        $texts = $content->translations('blog_post_translations', 'post_id', [$id])[$id] ?? [];
        $text = fn (string $field): string => $content->text($texts, $locale, $field);
        $blogTexts = $content->translations('blog_translations', 'blog_id', [$row->blog_id])[$row->blog_id] ?? [];
        $blogUrl = self::path($content->text($blogTexts, $locale, 'slug'));
        $html = app(PageContentSanitizer::class)->sanitize($text('content'));
        $covers = app(PublicMedia::class)->images(array_values(array_filter([$row->cover_media_id, $row->cover_mobile_media_id])));
        $cover = app(PublicMedia::class)->url($covers[$row->cover_media_id] ?? null, 'blog-post', $id);
        // Tags follow the texts' language fallback: the visitor's language, the default one, then English.
        $tags = DB::table('blog_post_tags as g')->join('locales as l', 'l.id', '=', 'g.locale_id')->where('g.post_id', $id)->get(['l.code', 'g.name'])->groupBy('code');
        $excerpt = $this->excerpt($texts, $locale);

        return ['id' => $id, 'name' => $text('name'), 'content' => trim(strip_tags($html, '<img><iframe>')) === '' ? '' : $html, 'excerpt' => $text('excerpt'),
            'url' => self::path($content->text($blogTexts, $locale, 'slug'), $text('slug')), 'published_at' => $this->iso($row->published_at), 'cover' => $cover, 'cover_alt' => $text('cover_alt'),
            'cover_mobile' => app(PublicMedia::class)->url($covers[$row->cover_mobile_media_id] ?? null, 'blog-post', $id),
            'tags' => collect(array_unique([$locale, app(LocaleCatalog::class)->defaultCode(), 'en']))->map(fn (string $code) => $tags[$code] ?? null)->filter()->first()?->pluck('name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all() ?? [],
            'blog' => ['name' => $content->text($blogTexts, $locale, 'name'), 'url' => $blogUrl],
            'seo' => ['title' => $text('seo_title') ?: $text('name'), 'description' => $text('meta_description') ?: $excerpt, 'keywords' => $text('meta_keywords')],
            'facebook_type' => $row->facebook_type];
    }

    /**
     * The post's excerpt, else the start of its content as plain text.
     *
     * @param  array<string,array<string,mixed>>  $texts
     */
    private function excerpt(array $texts, string $locale): string
    {
        $content = app(BlockContent::class);
        $excerpt = $content->text($texts, $locale, 'excerpt');

        return $excerpt !== '' ? $excerpt : Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content->text($texts, $locale, 'content'))))), 200);
    }

    private function available(Builder $q, string $table): Builder
    {
        return $q->where(fn ($w) => $w->whereNull($table.'.available_from')->orWhere($table.'.available_from', '<=', now()))
            ->where(fn ($w) => $w->whereNull($table.'.available_until')->orWhere($table.'.available_until', '>', now()));
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : now()->parse($value)->utc()->toIso8601ZuluString();
    }
}
