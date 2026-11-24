<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PromotionQueries
{
    private function query(?string $locale = null): Builder
    {
        $locales = DB::table('locales')->pluck('id', 'code');

        return DB::table('promotions as p')
            ->leftJoin('promotion_translations as c', fn ($j) => $j->on('c.promotion_id', '=', 'p.id')->where('c.locale_id', $locales[$locale ?? app()->getLocale()] ?? 0))
            ->leftJoin('promotion_translations as d', fn ($j) => $j->on('d.promotion_id', '=', 'p.id')->where('d.locale_id', $locales[app(LocaleCatalog::class)->defaultCode()] ?? 0))
            ->leftJoin('coupons as coupon', 'coupon.id', '=', 'p.coupon_id')
            ->select('p.*', 'coupon.code as coupon_code', 'coupon.total_limit')->selectRaw("coalesce(c.name, d.name, '#' || p.id::text) as name");
    }

    /** Bounded layout picker; selected inactive offers remain removable in the editor.
     * @param list<string> $selected
     * @return list<array{id:string,name:string}> */
    public function options(string $search, array $selected): array
    {
        $q = $this->query()->where('p.mode', 'automatic');
        $chosen = (clone $q)->whereIn('p.id', $selected)->get();
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($q) => $q->where('c.name', 'ilike', $like)->orWhere('d.name', 'ilike', $like));
        }

        return $chosen->concat($q->orderBy('p.id')->limit(30)->get())->unique('id')->map(fn ($p): array => ['id' => (string) $p->id, 'name' => $p->name])->values()->all();
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $q = $this->query();
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $q->where(fn ($q) => $q->where('c.name', 'ilike', $like)->orWhere('d.name', 'ilike', $like)->orWhere('coupon.code', 'ilike', $like));
        }
        foreach (['status', 'mode', 'benefit'] as $filter) {
            if (! empty($filters[$filter])) {
                $q->where('p.'.$filter, $filters[$filter]);
            }
        }
        $sort = in_array($filters['sort'] ?? '', ['name', 'priority', 'starts_at', 'ends_at', 'status'], true) ? $filters['sort'] : 'priority';
        $q->selectSub(DB::table('coupon_redemptions')->whereColumn('coupon_id', 'p.coupon_id')->whereNull('released_at')->selectRaw('count(*)'), 'coupon_used');
        $page = $q->selectSub(DB::table('order_promotions')->whereColumn('promotion_id', 'p.id')->whereNull('released_at')->selectRaw('count(*)'), 'used')
            ->orderBy($sort === 'name' ? 'name' : 'p.'.$sort, ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc')->orderBy('p.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $rows = [];
        foreach ($page->items() as $p) {
            $rows[] = [...$this->row($p), 'used' => (int) ($p->coupon_id === null ? $p->used : $p->coupon_used), 'total_limit' => $p->total_limit];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** Bounded by the requested IDs for an editor; active definitions for a calculation.
     * @param  list<string>|null  $ids
     * @return list<array<string, mixed>>
     */
    public function definitions(?array $ids = null, bool $pricing = false, ?string $coupon = null, ?string $locale = null): array
    {
        $q = $this->query($locale);
        if ($ids !== null) {
            $q->whereIn('p.id', $ids);
        }
        if ($pricing) {
            $q->where(function ($q) use ($coupon): void {
                $q->where(fn ($q) => $q->where('p.mode', 'automatic')->where('p.status', 'active')->where(fn ($q) => $q->whereNull('p.starts_at')->orWhere('p.starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('p.ends_at')->orWhere('p.ends_at', '>', now())));
                if ($coupon !== null) {
                    $q->orWhere('p.coupon_id', $coupon);
                }
            });
        }
        $rows = $q->orderBy('p.priority')->orderBy('p.id')->get();
        $promotionIds = $rows->pluck('id')->all();
        $conditions = DB::table('promotion_conditions')->whereIn('promotion_id', $promotionIds)->orderBy('id')->get();
        $conditionTargets = $benefitTargets = [];
        foreach (Promotions::TARGETS as $kind => [$table, $column]) {
            $conditionTargets[$kind] = DB::table('promotion_condition_'.$table)->whereIn('condition_id', $conditions->pluck('id'))->get()->groupBy('condition_id');
            if ($kind !== 'groups') {
                $benefitTargets[$kind] = DB::table('promotion_'.$table)->whereIn('promotion_id', $promotionIds)->get()->groupBy('promotion_id');
            }
        }
        $translations = DB::table('promotion_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('promotion_id', $promotionIds)->get(['t.*', 'l.code'])->groupBy('promotion_id');
        $result = [];
        foreach ($rows as $p) {
            $data = $this->row($p);
            $data['translations'] = [];
            foreach ($translations[$p->id] ?? [] as $t) {
                $data['translations'][$t->code] = ['name' => $t->name, 'description' => $t->description];
            }
            $data['conditions'] = [];
            foreach ($conditions->where('promotion_id', $p->id) as $condition) {
                $c = ['type' => $condition->type, 'minimum' => $condition->minimum === null ? null : Decimal::trim($condition->minimum), 'maximum' => $condition->maximum === null ? null : Decimal::trim($condition->maximum), 'quantity' => $condition->quantity === null ? null : (string) $condition->quantity, 'quantity_scope' => $condition->quantity_scope, 'match' => $condition->match];
                foreach (Promotions::TARGETS as $kind => [$table, $column]) {
                    $c[$kind] = collect($conditionTargets[$kind][$condition->id] ?? [])->map(fn (object $r): ?string => $r->{$column} === null ? null : (string) $r->{$column})->all();
                }
                $data['conditions'][] = $c;
            }
            foreach (Promotions::TARGETS as $kind => [$table, $column]) {
                if ($kind === 'groups') {
                    continue;
                }
                foreach ($kind === 'shipping' ? [$kind => false] : [$kind => false, 'excluded_'.$kind => true] as $field => $excluded) {
                    $data[$field] = collect($benefitTargets[$kind][$p->id] ?? [])->where('excluded', $excluded)->map(fn (object $r): ?string => $r->{$column} === null ? null : (string) $r->{$column})->all();
                }
            }
            $result[] = $data;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function row(object $p): array
    {
        $timezone = app(GeneralSettings::class)->all()['timezone'];
        $start = $p->starts_at === null ? null : CarbonImmutable::parse($p->starts_at);
        $end = $p->ends_at === null ? null : CarbonImmutable::parse($p->ends_at);

        return ['id' => (string) $p->id, 'version' => (int) $p->lock_version, 'name' => $p->name, 'status' => $p->status, 'state' => $p->status === 'disabled' ? 'disabled' : ($end !== null && now()->gte($end) ? 'expired' : ($start !== null && now()->lt($start) ? 'scheduled' : 'active')),
            'mode' => $p->mode, 'coupon_id' => $p->coupon_id === null ? null : (string) $p->coupon_id, 'coupon_code' => $p->coupon_code,
            'starts_at' => $start?->timezone($timezone)->format('Y-m-d\TH:i'), 'ends_at' => $end?->timezone($timezone)->format('Y-m-d\TH:i'),
            'starts_utc' => $start?->utc()->format('Y-m-d H:i:s'), 'ends_utc' => $end?->utc()->format('Y-m-d H:i:s'),
            'priority' => (string) $p->priority, 'stop_other' => (bool) $p->stop_other, 'match' => $p->match, 'benefit' => $p->benefit, 'scope' => $p->scope,
            'amount' => $p->amount === null ? null : Decimal::trim($p->amount), 'all_products' => (bool) $p->all_products, 'exclude_sale' => (bool) $p->exclude_sale, 'all_shipping' => (bool) $p->all_shipping];
    }
}
