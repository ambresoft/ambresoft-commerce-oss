<?php

namespace App\Catalog\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Application\Service\PriceDisplay;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Frequently bought together: up to three complementary products chosen by the merchant for a main product. Only the
 * relationship is stored; names, pictures, prices and availability are always read live, so nothing goes stale.
 */
final class ProductCompanions
{
    public const MAX = 3;

    /** @return list<string> Companion ids in the merchant's order. */
    public function ids(string $product): array
    {
        return DB::table('product_companions')->where('product_id', $product)->orderBy('position')->pluck('companion_id')->map(strval(...))->all();
    }

    /**
     * Replaces the companions. New choices must be live (active, not deleted) products other than the main product;
     * companions saved earlier stay accepted while they still exist, so a later status change never blocks a save.
     *
     * @param  array<array-key, int|string>  $ids
     */
    public function sync(string $product, array $ids): void
    {
        $ids = array_values(array_map(strval(...), $ids));
        if (count($ids) > self::MAX) {
            throw ValidationException::withMessages(['companion_ids' => __('products.companions_max', ['max' => self::MAX])]);
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['companion_ids' => __('products.companions_duplicate')]);
        }
        if (in_array($product, $ids, true)) {
            throw ValidationException::withMessages(['companion_ids' => __('products.companions_self')]);
        }
        $saved = $this->ids($product);
        $valid = DB::table('products')->whereIn('id', $ids)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $saved))->pluck('id')->map(strval(...))->all();
        if (array_diff($ids, $valid) !== []) {
            throw ValidationException::withMessages(['companion_ids' => __('products.companions_unavailable')]);
        }
        if ($ids === $saved) {
            return;
        }
        DB::table('product_companions')->where('product_id', $product)->delete();
        foreach ($ids as $n => $id) {
            DB::table('product_companions')->insert(['product_id' => $product, 'companion_id' => $id, 'position' => $n + 1]);
        }
    }

    /** A duplicated product keeps the same companions (never itself). */
    public function copy(string $from, string $to): void
    {
        foreach (DB::table('product_companions')->where('product_id', $from)->orderBy('position')->get() as $row) {
            if ((string) $row->companion_id !== $to) {
                DB::table('product_companions')->insert(['product_id' => $to, 'companion_id' => $row->companion_id, 'position' => $row->position]);
            }
        }
    }

    /**
     * Admin rows for the editor: the saved companions, or with a search the products that may be added.
     *
     * @param  list<string>  $include  Ids that must be described (the current choice)
     * @return list<array{id: string, name: string, sku: string|null, image: string|null, price: string|null, status: string, deleted: bool}>
     */
    public function describe(array $include): array
    {
        return $this->rows(DB::table('products')->whereIn('products.id', $include === [] ? [0] : $include), withTrashed: true, order: $include);
    }

    /** @return list<array{id: string, name: string, sku: string|null, image: string|null, price: string|null, status: string, deleted: bool}> */
    public function search(string $product, string $search): array
    {
        $q = DB::table('products')->whereNull('products.deleted_at')->where('products.status', 'active')->where('products.id', '<>', $product);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($q) => $q->where('products.sku', 'ilike', $like)->orWhereExists(fn ($t) => $t->selectRaw('1')->from('product_translations as t')->whereColumn('t.product_id', 'products.id')->where('t.name', 'ilike', $like)));
        }

        return $this->rows($q->orderByDesc('products.id')->limit(20), withTrashed: false);
    }

    /** @param list<string> $order
     * @return list<array{id: string, name: string, sku: string|null, image: string|null, price: string|null, status: string, deleted: bool}> */
    private function rows(Builder $q, bool $withTrashed, array $order = []): array
    {
        $catalog = app(LocaleCatalog::class);
        $default = $catalog->active()->firstWhere('is_default', true)->id ?? 0;
        $current = $catalog->active()->firstWhere('code', app()->getLocale())->id ?? $default;
        $rows = $q->leftJoin('product_translations as c', fn ($j) => $j->on('c.product_id', '=', 'products.id')->where('c.locale_id', $current))
            ->leftJoin('product_translations as d', fn ($j) => $j->on('d.product_id', '=', 'products.id')->where('d.locale_id', $default))
            ->leftJoin('product_images as i', fn ($j) => $j->on('i.product_id', '=', 'products.id')->where('i.is_primary', true))
            ->get(['products.id', 'products.sku', 'products.price', 'products.status', 'products.deleted_at', 'i.media_file_id', DB::raw("coalesce(nullif(c.name, ''), nullif(d.name, ''), '') AS name")]);
        $prices = app(PriceDisplay::class);
        $converter = $prices->converter();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->id] = ['id' => (string) $r->id, 'name' => $r->name !== '' ? $r->name : __('products.untitled', ['id' => $r->id]), 'sku' => $r->sku,
                'image' => $r->media_file_id === null ? null : '/admin/product-media/'.$r->media_file_id, 'price' => $r->price === null ? null : $prices->format(Decimal::trim($r->price), null, $converter),
                'status' => $r->status, 'deleted' => $withTrashed && $r->deleted_at !== null];
        }

        return $order === [] ? array_values($out) : array_values(array_filter(array_map(fn (string $id) => $out[$id] ?? null, $order)));
    }
}
