<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ReelQueries
{
    public function named(): Builder
    {
        $locales = app(LocaleCatalog::class);
        $default = $locales->active()->firstWhere('is_default', true)->id;
        $current = $locales->active()->firstWhere('code', app()->getLocale())->id ?? $default;

        return DB::table('reels')->leftJoin('reel_translations as c', fn ($j) => $j->on('c.reel_id', 'reels.id')->where('c.locale_id', $current))->leftJoin('reel_translations as d', fn ($j) => $j->on('d.reel_id', 'reels.id')->where('d.locale_id', $default))->selectRaw("reels.*, coalesce(nullif(c.name,''), d.name, '') as name");
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int|string,array{id:string,name:mixed,status:mixed,version:int,views:int,created_at:mixed,cover:string}> */
    public function listing(array $filters): LengthAwarePaginator
    {
        $q = $this->search($this->named(), $filters['search'] ?? '');
        if (! empty($filters['status'])) {
            $q->where('reels.status', $filters['status']);
        }
        $counts = DB::table('reel_views')->selectRaw('reel_id, count(*) as views')->groupBy('reel_id');
        $q->leftJoinSub($counts, 'v', 'v.reel_id', 'reels.id')->selectRaw('coalesce(v.views, 0) as views');
        ($filters['sort'] ?? '') === 'views' ? $q->orderByDesc('views') : $q->orderByDesc('reels.created_at');

        return $q->orderByDesc('reels.id')->paginate(25)->withQueryString()->through(fn ($r): array => ['id' => (string) $r->id, 'name' => $r->name, 'status' => $r->status, 'version' => (int) $r->lock_version, 'views' => (int) $r->views, 'created_at' => $r->created_at, 'cover' => '/admin/reels/'.$r->id.'/cover']);
    }

    /** @return array<string,mixed>|null */
    public function editor(string $id): ?array
    {
        $r = $this->named()->where('reels.id', $id)->first();
        if ($r === null) {
            return null;
        }
        $codes = app(LocaleCatalog::class)->active()->pluck('code', 'id');
        $texts = [];
        foreach (DB::table('reel_translations')->where('reel_id', $id)->whereIn('locale_id', $codes->keys())->get() as $t) {
            $texts[$codes[$t->locale_id]] = ['name' => $t->name];
        }

        return ['id' => $id, 'name' => $r->name, 'status' => $r->status, 'version' => (int) $r->lock_version, 'translations' => $texts, 'products' => DB::table('reel_products')->where('reel_id', $id)->orderBy('position')->pluck('product_id')->map(strval(...))->all(), 'video' => '/admin/reels/'.$id.'/video', 'cover' => '/admin/reels/'.$id.'/cover'];
    }

    /** @param list<string> $selected
     * @return list<array{id:string,name:string}> */
    public function options(string $search, array $selected): array
    {
        $q = $this->named();
        $chosen = (clone $q)->whereIn('reels.id', $selected)->get();

        return $chosen->concat($this->search($q, $search)->orderByDesc('reels.id')->limit(30)->get())->unique('id')->map(fn ($r): array => ['id' => (string) $r->id, 'name' => $r->name])->values()->all();
    }

    private function search(Builder $q, string $search): Builder
    {
        return $search === '' ? $q : $q->where(fn ($q) => $q->where('c.name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->orWhere('d.name', 'ilike', '%'.addcslashes($search, '\\%_').'%'));
    }
}
