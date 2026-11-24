<?php

namespace App\Catalog\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\MediaMetadata;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BrandQueries
{
    public function named(?string $locale = null): Builder
    {
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)->id;
        $current = $locales->firstWhere('code', $locale ?? app()->getLocale())->id ?? $default;

        return DB::table('brands as b')->leftJoin('brand_translations as c', fn ($j) => $j->on('c.brand_id', 'b.id')->where('c.locale_id', $current))
            ->leftJoin('brand_translations as d', fn ($j) => $j->on('d.brand_id', 'b.id')->where('d.locale_id', $default))
            ->select('b.*')->selectRaw("coalesce(c.name,d.name,'') as name, coalesce(c.description,d.description,'') as description, coalesce(c.image_alt,d.image_alt,'') as image_alt, coalesce(nullif(c.banner_alt,''),d.banner_alt,'') as banner_alt");
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed> */
    public function listing(array $filters): array
    {
        $query = $this->named()->selectSub(DB::table('products')->selectRaw('count(*)')->whereColumn('brand_id', 'b.id'), 'products_count');
        $this->search($query, $filters['search'] ?? '');
        if (! empty($filters['status'])) {
            $query->where('b.status', $filters['status']);
        }
        $sort = $filters['sort'] ?? 'position';
        $page = $query->orderBy(in_array($sort, ['name', 'products_count'], true) ? $sort : 'b.'.$sort, $filters['direction'] ?? 'asc')->orderBy('b.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $page->setCollection($page->getCollection()->map(fn ($b) => ['id' => (string) $b->id, 'name' => $b->name ?: $b->code, 'code' => $b->code, 'status' => $b->status, 'position' => (int) $b->position, 'products_count' => (int) $b->products_count, 'version' => (int) $b->lock_version, 'updated_at' => $b->updated_at, 'image' => $b->image_media_id === null ? null : '/admin/brands/'.$b->id.'/image?v='.$b->image_media_id]));

        return $page->toArray();
    }

    /** @return array<string,mixed> */
    public function editor(string $id): array
    {
        $b = $this->named()->where('b.id', $id)->firstOrFail();
        $texts = DB::table('brand_translations as t')->join('locales as l', 'l.id', 't.locale_id')->where('t.brand_id', $id)->get(['t.*', 'l.code'])->mapWithKeys(fn ($t) => [$t->code => ['name' => $t->name, 'description' => $t->description, 'image_alt' => $t->image_alt, 'banner_alt' => $t->banner_alt]])->all();

        return ['id' => (string) $b->id, 'name' => $b->name ?: $b->code, 'code' => $b->code, 'status' => $b->status, 'position' => (int) $b->position, 'version' => (int) $b->lock_version, 'translations' => (object) $texts, 'products_count' => DB::table('products')->where('brand_id', $id)->count(),
            'files' => DB::table('brand_files as bf')->join('private_product_files as f', 'f.id', 'bf.private_file_id')->where('bf.brand_id', $id)->orderBy('bf.position')->get(['bf.id', 'bf.display_name', 'f.original_name', 'f.byte_size'])->map(fn ($f) => ['id' => (string) $f->id, 'display_name' => $f->display_name, 'original_name' => $f->original_name, 'byte_size' => (int) $f->byte_size, 'url' => '/admin/brands/'.$id.'/files/'.$f->id])->all(),
            'banner_link' => $b->banner_link ?? '',
            'banner_image' => $b->banner_image_media_id === null ? null : ['id' => (string) $b->banner_image_media_id, 'url' => '/admin/brands/'.$id.'/banner-image?v='.$b->banner_image_media_id, 'meta' => app(MediaMetadata::class)->one((string) $b->banner_image_media_id)],
            'image' => $b->image_media_id === null ? null : ['id' => (string) $b->image_media_id, 'url' => '/admin/brands/'.$id.'/image?v='.$b->image_media_id, 'meta' => app(MediaMetadata::class)->one((string) $b->image_media_id)]];
    }

    /** @param list<string> $selected
     * @return list<array{id:string,name:string}> */
    public function options(string $search = '', array $selected = []): array
    {
        $q = $this->named();
        $available = (clone $q)->where('b.status', 'active');
        $this->search($available, $search);

        return $available->orderBy('b.position')->orderBy('name')->orderBy('b.id')->limit(30)->get()->merge((clone $q)->whereIn('b.id', $selected)->get())->unique('id')->map(fn ($b) => ['id' => (string) $b->id, 'name' => $b->name ?: $b->code])->values()->all();
    }

    /** @param list<string> $selected
     * @return list<array{id:string,name:string}> */
    public function productOptions(?string $brand, string $search = '', array $selected = []): array
    {
        if ($brand === null || $brand === '') {
            return [];
        }
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)->id;
        $current = $locales->firstWhere('code', app()->getLocale())->id ?? $default;
        $q = DB::table('products as p')->where('p.brand_id', $brand)->whereNull('p.deleted_at')
            ->leftJoin('product_translations as c', fn ($j) => $j->on('c.product_id', 'p.id')->where('c.locale_id', $current))
            ->leftJoin('product_translations as d', fn ($j) => $j->on('d.product_id', 'p.id')->where('d.locale_id', $default))
            ->select('p.id', 'p.brand_id')->selectRaw("coalesce(c.name,d.name,p.sku,'#' || p.id) as name");
        $available = clone $q;
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $available->where(fn ($q) => $q->where('c.name', 'ilike', $like)->orWhere('d.name', 'ilike', $like)->orWhere('p.sku', 'ilike', $like));
        }

        return $available->orderBy('p.id')->limit(30)->get()->merge($q->whereIn('p.id', $selected)->get())->unique('id')->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name, 'brand_id' => (string) $p->brand_id])->values()->all();
    }

    private function search(Builder $q, string $search): void
    {
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $q->where(fn ($q) => $q->where('c.name', 'ilike', $like)->orWhere('d.name', 'ilike', $like)->orWhere('b.code', 'ilike', $like));
        }
    }
}
