<?php

namespace App\Website\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\MediaMetadata;
use App\Storefront\Infrastructure\StorefrontBlogs;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read projections for the blog and blog post admin screens. */
final class BlogQueries
{
    /**
     * @param  array<string, mixed>  $filters  search, status, blog (posts only), page, per_page
     * @return array<string, mixed> Laravel paginator array.
     */
    public function listing(string $kind, array $filters): array
    {
        $q = $this->named($kind);
        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
            $q->where(fn ($w) => $w->whereRaw('lower(coalesce(c.name, d.name)) like ?', [$like])->orWhereRaw('coalesce(c.slug, d.slug) like ?', [$like]));
        }
        if (! empty($filters['status'])) {
            $q->where('r.status', $filters['status']);
        }
        if ($kind === 'blog') {
            $q->selectSub(DB::table('blog_posts')->selectRaw('count(*)')->whereColumn('blog_id', 'r.id'), 'posts_count')->orderBy('r.position');
        } else {
            if (! empty($filters['blog'])) {
                $q->where('r.blog_id', $filters['blog']);
            }
            // Drafts first, then the newest publication.
            $q->addSelect('r.blog_id', 'r.cover_media_id')->orderByRaw('r.published_at DESC NULLS FIRST');
        }
        $page = $q->orderBy('r.id', $kind === 'blog' ? 'asc' : 'desc')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $blogs = $kind === 'post' ? collect($this->blogOptions())->pluck('name', 'id') : collect();

        return $page->through(fn ($r) => [
            'id' => (string) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'status' => $r->status, 'version' => (int) $r->lock_version,
            'state' => $this->state($kind, $r),
            ...($kind === 'blog' ? ['position' => (int) $r->position, 'posts_count' => (int) $r->posts_count] : [
                'blog_id' => (string) $r->blog_id, 'blog' => $blogs[(string) $r->blog_id] ?? '#'.$r->blog_id,
                'published_at' => $this->iso($r->published_at),
                'cover' => $r->cover_media_id === null ? null : '/admin/blog-posts/'.$r->id.'/cover?v='.$r->cover_media_id,
            ]),
        ])->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $kind, string $id): ?array
    {
        [$table, $texts, $owner] = BlogAdministration::TABLES[$kind];
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $codes = DB::table('locales')->pluck('code', 'id');
        $tags = [];
        if ($kind === 'post') {
            foreach (DB::table('blog_post_tags')->where('post_id', $id)->orderBy('name')->get() as $tag) {
                $tags[$tag->locale_id][] = $tag->name;
            }
        }
        $translations = [];
        foreach (DB::table($texts)->where($owner, $id)->get() as $t) {
            $translations[$codes[$t->locale_id]] = [
                'name' => $t->name, 'content' => $t->content, 'slug' => $t->slug, 'seo_title' => $t->seo_title, 'meta_description' => $t->meta_description, 'meta_keywords' => $t->meta_keywords,
                ...($kind === 'post' ? ['excerpt' => $t->excerpt, 'cover_alt' => $t->cover_alt, 'tags' => implode(', ', $tags[$t->locale_id] ?? [])] : []),
            ];
        }

        // Staff preview the storefront address whatever the state; a post's address starts with its blog's.
        $path = $this->slug($translations);
        if ($kind === 'post' && $path !== '') {
            $blog = $this->slug(DB::table('blog_translations')->where('blog_id', $row->blog_id)->get()->mapWithKeys(fn (object $t): array => [$codes[$t->locale_id] => ['slug' => $t->slug]])->all());
            $path = $blog === '' ? '' : $blog.'/'.$path;
        }
        $preview = $path === '' ? null : StorefrontBlogs::PREFIX.$path.'?preview=1';

        return [
            'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'status' => $row->status, 'facebook_type' => $row->facebook_type,
            'available_from' => $this->iso($row->available_from), 'available_until' => $this->iso($row->available_until),
            'state' => $this->state($kind, $row), 'translations' => (object) $translations,
            'preview_url' => $preview,
            ...($kind === 'blog' ? ['position' => (int) $row->position] : [
                'blog_id' => (string) $row->blog_id, 'published_at' => $this->iso($row->published_at),
                'cover' => $row->cover_media_id === null ? null : ['url' => '/admin/blog-posts/'.$id.'/cover?v='.$row->cover_media_id, 'meta' => app(MediaMetadata::class)->one((string) $row->cover_media_id)],
                'cover_mobile' => $row->cover_mobile_media_id === null ? null : ['url' => '/admin/blog-posts/'.$id.'/cover-mobile?v='.$row->cover_mobile_media_id, 'meta' => app(MediaMetadata::class)->one((string) $row->cover_mobile_media_id)],
            ]),
        ];
    }

    /** @param array<string, array<string, mixed>> $translations */
    private function slug(array $translations): string
    {
        return (string) ($translations[app()->getLocale()]['slug'] ?? $translations[app(LocaleCatalog::class)->defaultCode()]['slug'] ?? '');
    }

    /** @return list<array{id: string, name: string}> */
    public function blogOptions(): array
    {
        return $this->named('blog')->orderBy('r.position')->orderBy('r.id')->get()
            ->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => $r->name])->all();
    }

    /** Rows with their name and SEO name in the admin language, falling back to the default language. */
    private function named(string $kind): Builder
    {
        [$table, $texts, $owner] = BlogAdministration::TABLES[$kind];
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)?->id;
        $current = $locales->where('code', app()->getLocale())->pluck('id')->first() ?? $default;

        return DB::table($table.' as r')
            ->leftJoin($texts.' as c', fn ($j) => $j->on('c.'.$owner, '=', 'r.id')->where('c.locale_id', $current))
            ->leftJoin($texts.' as d', fn ($j) => $j->on('d.'.$owner, '=', 'r.id')->where('d.locale_id', $default))
            ->selectRaw("r.id, r.status, r.lock_version, r.available_from, r.available_until, coalesce(c.name, d.name, '#' || r.id) as name, coalesce(c.slug, d.slug, '') as slug")
            ->addSelect($kind === 'blog' ? 'r.position' : 'r.published_at');
    }

    /** live | draft | scheduled | expired | hidden | disabled */
    private function state(string $kind, object $row): string
    {
        if ($row->status === 'disabled') {
            return 'disabled';
        }
        if ($kind === 'post' && $row->published_at === null) {
            return 'draft';
        }
        if (($row->available_from !== null && now()->lt($row->available_from)) || ($kind === 'post' && now()->lt($row->published_at))) {
            return 'scheduled';
        }
        if ($row->available_until !== null && now()->gte($row->available_until)) {
            return 'expired';
        }

        return $row->status === 'hidden' ? 'hidden' : 'live';
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : now()->parse($value)->utc()->toIso8601ZuluString();
    }
}
