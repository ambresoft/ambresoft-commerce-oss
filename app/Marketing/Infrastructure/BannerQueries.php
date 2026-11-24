<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\BannerRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Bounded read projections for the banner admin screens. */
final class BannerQueries
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int|string, mixed>
     */
    public function listing(array $filters): LengthAwarePaginator
    {
        [$current, $default] = $this->localeIds();
        $now = now();
        $page = DB::table('banners as b')
            ->leftJoin('banner_groups as g', 'g.id', '=', 'b.group_id')
            ->leftJoin('banner_translations as c', fn ($j) => $j->on('c.banner_id', '=', 'b.id')->where('c.locale_id', $current))
            ->leftJoin('banner_translations as d', fn ($j) => $j->on('d.banner_id', '=', 'b.id')->where('d.locale_id', $default))
            ->selectRaw("b.id, b.position, b.status, b.object_type, b.available_from, b.available_until, b.lock_version, b.updated_at, b.group_id, g.name as group_name, coalesce(c.name, d.name, '#' || b.id) as name")
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->whereExists(fn ($e) => $e->selectRaw('1')->from('banner_translations as s')->whereColumn('s.banner_id', 'b.id')->where('s.name', 'ilike', $like));
            })
            ->when(($filters['group'] ?? null) === 'none', fn ($q) => $q->whereNull('b.group_id'))
            ->when(is_numeric($filters['group'] ?? null), fn ($q) => $q->where('b.group_id', (int) $filters['group']))
            ->when(($filters['state'] ?? null) === 'disabled', fn ($q) => $q->where('b.status', 'disabled'))
            ->when(($filters['state'] ?? null) === 'live', fn ($q) => $q->where('b.status', 'active')->where(fn ($q) => $q->whereNull('b.available_from')->orWhere('b.available_from', '<=', $now))->where(fn ($q) => $q->whereNull('b.available_until')->orWhere('b.available_until', '>', $now)))
            ->when(($filters['state'] ?? null) === 'scheduled', fn ($q) => $q->where('b.status', 'active')->where('b.available_from', '>', $now))
            ->when(($filters['state'] ?? null) === 'expired', fn ($q) => $q->where('b.status', 'active')->where('b.available_until', '<=', $now))
            // Grouped banners first (by group name), then ungrouped; inside, by position.
            ->orderByRaw('g.name IS NULL, lower(g.name), b.group_id, b.position, b.id')
            ->paginate(50)->withQueryString();
        $thumbs = $this->thumbnails($page->getCollection()->pluck('id')->all(), $current, $default);
        $page->setCollection($page->getCollection()->map(fn ($row): array => [
            'id' => (string) $row->id, 'name' => $row->name, 'position' => (int) $row->position, 'status' => $row->status,
            'state' => $this->state($row->status, $row->available_from, $row->available_until),
            'available_from' => $this->iso($row->available_from),
            'available_until' => $this->iso($row->available_until),
            'object_type' => $row->object_type, 'version' => (int) $row->lock_version,
            'group' => $row->group_id === null ? null : ['id' => (string) $row->group_id, 'name' => $row->group_name],
            'thumbnail' => $thumbs[$row->id] ?? null,
        ]));

        return $page;
    }

    /** @return list<array{id: string, name: string, banners: int}> */
    public function groups(): array
    {
        return DB::table('banner_groups as g')->leftJoin('banners as b', 'b.group_id', '=', 'g.id')
            ->selectRaw('g.id, g.name, count(b.id) as total')->groupBy('g.id', 'g.name')->orderByRaw('lower(g.name)')->get()
            ->map(fn ($row): array => ['id' => (string) $row->id, 'name' => $row->name, 'banners' => (int) $row->total])->all();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $banner = DB::table('banners')->where('id', $id)->first();
        if ($banner === null) {
            return null;
        }
        $codes = DB::table('locales')->pluck('code', 'id');
        $names = [];
        foreach (DB::table('banner_translations')->where('banner_id', $id)->get() as $row) {
            $names[$codes[$row->locale_id]] = $row->name;
        }
        $layouts = [];
        foreach (DB::table('banner_layouts')->where('banner_id', $id)->get() as $row) {
            $layout = (array) $row;
            unset($layout['banner_id'], $layout['device']);
            $layouts[$row->device] = $layout;
        }
        $contents = [];
        foreach (DB::table('banner_contents')->where('banner_id', $id)->get() as $row) {
            $contents[$row->device][$codes[$row->locale_id]] = [
                'title' => $row->title, 'description' => $row->description, 'button_text' => $row->button_text, 'youtube_video' => $row->youtube_video,
                'main_image' => $this->image($id, $row->main_image_media_id), 'background_image' => $this->image($id, $row->background_image_media_id),
                'background_video' => $row->background_video_id === null ? null : ['id' => (string) $row->background_video_id, 'url' => '/admin/banners/'.$id.'/videos/'.$row->background_video_id],
            ];
        }
        $settings = array_intersect_key((array) $banner, array_flip([...array_keys(BannerRules::SETTINGS), 'products_columns', 'products_rows', 'button_transition', 'youtube_autoplay', 'youtube_hide_controls', 'background_color', 'title_color', 'description_color', 'description_background', 'url', 'new_window', 'tablet_custom', 'phone_custom', 'position', 'status']));

        $settings['button_transition'] = (float) $banner->button_transition;

        return [
            'id' => (string) $banner->id, 'version' => (int) $banner->lock_version, 'group_id' => $banner->group_id === null ? null : (string) $banner->group_id,
            'created_on' => (string) $banner->created_on,
            'available_from' => $this->iso($banner->available_from),
            'available_until' => $this->iso($banner->available_until),
            'state' => $this->state($banner->status, $banner->available_from, $banner->available_until),
            ...$settings, 'names' => (object) $names, 'layouts' => (object) $layouts, 'contents' => (object) $contents,
            'products' => $this->products(DB::table('banner_products')->where('banner_id', $id)->orderBy('position')->pluck('product_id')->all()),
            'customer_groups' => DB::table('banner_customer_groups')->where('banner_id', $id)->orderBy('group_id')->pluck('group_id')->map(fn ($v): string => (string) $v)->all(),
        ];
    }

    /** @return list<array{id: string, name: string, sku: string|null, image: string|null}> */
    public function productCandidates(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }
        $like = '%'.addcslashes($search, '\\%_').'%';
        $ids = DB::table('products as p')->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->where('p.sku', 'ilike', $like)->orWhereExists(fn ($e) => $e->selectRaw('1')->from('product_translations as t')->whereColumn('t.product_id', 'p.id')->where('t.name', 'ilike', $like)))
            ->orderBy('p.id')->limit(20)->pluck('p.id')->all();

        return $this->products($ids);
    }

    /** @return list<array{id: string, name: string}> */
    public function customerGroups(): array
    {
        [$current, $default] = $this->localeIds();

        return DB::table('customer_groups as g')
            ->leftJoin('customer_group_translations as c', fn ($j) => $j->on('c.group_id', '=', 'g.id')->where('c.locale_id', $current))
            ->leftJoin('customer_group_translations as d', fn ($j) => $j->on('d.group_id', '=', 'g.id')->where('d.locale_id', $default))
            ->selectRaw("g.id, coalesce(c.name, d.name, '#' || g.id) as name")->orderBy('g.id')->get()
            ->map(fn ($row): array => ['id' => (string) $row->id, 'name' => $row->name])->all();
    }

    /** Owned-media guard for the private image route. */
    public function ownsImage(string $banner, string $media): bool
    {
        return DB::table('banner_contents')->where('banner_id', $banner)->where(fn ($q) => $q->where('main_image_media_id', $media)->orWhere('background_image_media_id', $media))->exists();
    }

    public function ownsVideo(string $banner, string $video): bool
    {
        return DB::table('banner_contents')->where('banner_id', $banner)->where('background_video_id', $video)->exists();
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array{id: string, name: string, sku: string|null, image: string|null}>
     */
    private function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$current, $default] = $this->localeIds();
        $rows = DB::table('products as p')
            ->leftJoin('product_translations as c', fn ($j) => $j->on('c.product_id', '=', 'p.id')->where('c.locale_id', $current))
            ->leftJoin('product_translations as d', fn ($j) => $j->on('d.product_id', '=', 'p.id')->where('d.locale_id', $default))
            ->leftJoin('product_images as i', fn ($j) => $j->on('i.product_id', '=', 'p.id')->where('i.is_primary', true))
            ->whereIn('p.id', $ids)->selectRaw("p.id, p.sku, i.media_file_id, coalesce(nullif(c.name, ''), nullif(d.name, ''), '#' || p.id) as name")->get()->keyBy('id');
        $images = Gate::allows('products.view');
        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $row = $rows[$id];
                $out[] = ['id' => (string) $row->id, 'name' => $row->name, 'sku' => $row->sku, 'image' => $images && $row->media_file_id !== null ? '/admin/product-media/'.$row->media_file_id : null];
            }
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, string>
     */
    private function thumbnails(array $ids, ?int $current, ?int $default): array
    {
        $thumbs = [];
        $rows = DB::table('banner_contents')->whereIn('banner_id', $ids)->where('device', 'pc')
            ->where(fn ($q) => $q->whereNotNull('main_image_media_id')->orWhereNotNull('background_image_media_id'))
            ->orderByRaw('CASE WHEN locale_id = ? THEN 0 WHEN locale_id = ? THEN 1 ELSE 2 END', [$current, $default])->get();
        foreach ($rows as $row) {
            $media = $row->background_image_media_id ?? $row->main_image_media_id;
            $thumbs[$row->banner_id] ??= '/admin/banners/'.$row->banner_id.'/media/'.$media;
        }

        return $thumbs;
    }

    /** @return array{id: string, url: string, meta: array{size: int, width: int, height: int}}|null */
    private function image(string $banner, ?int $media): ?array
    {
        if ($media === null) {
            return null;
        }
        $file = DB::table('media_files')->where('id', $media)->first(['byte_size', 'width', 'height']);

        return ['id' => (string) $media, 'url' => '/admin/banners/'.$banner.'/media/'.$media, 'meta' => $file === null ? null : ['size' => (int) $file->byte_size, 'width' => (int) $file->width, 'height' => (int) $file->height]];
    }

    private function state(string $status, mixed $from, mixed $until): string
    {
        if ($status !== 'active') {
            return 'disabled';
        }
        if ($from !== null && now()->lt($from)) {
            return 'scheduled';
        }

        return $until !== null && now()->gte($until) ? 'expired' : 'live';
    }

    /** An unambiguous instant (ISO 8601, UTC); the browser shows it in the administrator's own timezone. */
    private function iso(mixed $value): ?string
    {
        return $value === null ? null : now()->parse($value)->utc()->toIso8601ZuluString();
    }

    /** @return array{0: int|null, 1: int|null} */
    private function localeIds(): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)?->id;

        return [$locales->where('code', app()->getLocale())->pluck('id')->first() ?? $default, $default];
    }
}
