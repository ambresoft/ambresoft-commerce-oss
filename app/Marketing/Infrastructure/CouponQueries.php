<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\OrderMoney;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CouponQueries
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $q = DB::table('coupons')->whereNull('deleted_at');
        if (($filters['search'] ?? '') !== '') {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $q->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }
        if (($filters['status'] ?? '') !== '') {
            $q->where('status', $filters['status']);
        }
        $sort = in_array($filters['sort'] ?? '', ['name', 'code', 'starts_on', 'ends_on', 'status'], true) ? $filters['sort'] : 'name';
        $page = $q->select('coupons.*')->selectSub(DB::table('coupon_redemptions')->whereColumn('coupon_id', 'coupons.id')->whereNull('released_at')->selectRaw('count(*)'), 'used')
            ->orderBy($sort, ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $currency = app(CurrencyRepository::class)->currentPrimary();
        $rows = [];
        foreach ($page->items() as $row) {
            $rows[] = [
                'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'name' => $row->name, 'code' => $row->code, 'status' => $row->status,
                'discount' => $row->type === 'percentage' ? Decimal::trim($row->amount).'%' : app(OrderMoney::class)->format($row->amount, $currency->code, $currency->decimals),
                'free_shipping' => (bool) $row->free_shipping, 'starts_on' => $row->starts_on, 'ends_on' => $row->ends_on, 'used' => (int) $row->used, 'total_limit' => $row->total_limit,
            ];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $row = DB::table('coupons')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $data = (array) $row;
        unset($data['lock_version'], $data['created_at'], $data['updated_at']);
        $data['id'] = (string) $row->id;
        $data['version'] = (int) $row->lock_version;
        foreach (['amount', 'minimum'] as $field) {
            $data[$field] = Decimal::trim($row->{$field});
        }
        foreach (['starts_on', 'ends_on', 'total_limit', 'customer_limit'] as $field) {
            $data[$field] = (string) ($row->{$field} ?? '');
        }
        $data['product_ids'] = DB::table('coupon_products')->where('coupon_id', $id)->pluck('product_id')->map(strval(...))->all();
        $data['category_ids'] = DB::table('coupon_categories')->where('coupon_id', $id)->pluck('category_id')->map(strval(...))->all();

        return $data;
    }

    /** @return array<string, mixed> */
    public function history(string $id): array
    {
        $page = DB::table('coupon_redemptions as r')->join('orders as o', 'o.id', '=', 'r.order_id')->where('r.coupon_id', $id)->orderByDesc('r.id')
            ->paginate(25, ['r.*', 'o.order_number', 'o.first_name', 'o.last_name'])->withQueryString()->appends(['tab' => 'history']);
        $rows = [];
        foreach ($page->items() as $r) {
            $rows[] = [
                'id' => (string) $r->id, 'order_id' => (string) $r->order_id, 'order_number' => $r->order_number, 'customer' => trim($r->first_name.' '.$r->last_name),
                'amount' => app(OrderMoney::class)->format(Decimal::add($r->discount, $r->shipping_discount), $r->currency_code, (int) $r->currency_decimals),
                'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(), 'released' => $r->released_at !== null,
            ];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** Bounded, localized choices; selected records are retained without loading the whole catalog.
     * @param  list<string>  $selected
     * @return list<array{id: string, name: string}>
     */
    public function options(string $kind, string $search, array $selected = []): array
    {
        $table = $kind === 'product' ? 'products' : 'categories';
        $ids = DB::table('locales')->whereIn('code', [app()->getLocale(), app(LocaleCatalog::class)->defaultCode()])->pluck('id', 'code');
        $q = DB::table($table.' as e')
            ->leftJoin($kind.'_translations as c', fn ($j) => $j->on('c.'.$kind.'_id', '=', 'e.id')->where('c.locale_id', $ids[app()->getLocale()] ?? 0))
            ->leftJoin($kind.'_translations as d', fn ($j) => $j->on('d.'.$kind.'_id', '=', 'e.id')->where('d.locale_id', $ids[app(LocaleCatalog::class)->defaultCode()] ?? 0))
            ->select('e.id')->selectRaw("coalesce(nullif(c.name, ''), nullif(d.name, ''), '#' || e.id::text) AS name");
        if ($kind === 'product') {
            $q->whereNull('e.deleted_at')->addSelect('e.sku');
        } else {
            $q->whereNull('e.system_key');
        }
        $chosen = $selected === [] ? collect() : (clone $q)->whereIn('e.id', $selected)->get();
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(function ($q) use ($kind, $like): void {
                $q->where('c.name', 'ilike', $like)->orWhere('d.name', 'ilike', $like);
                if ($kind === 'product') {
                    $q->orWhere('e.sku', 'ilike', $like);
                }
            });
        }

        $rows = [];
        foreach ($chosen->concat($q->orderBy('e.id')->limit(30)->get())->unique('id') as $row) {
            $rows[] = ['id' => (string) $row->id, 'name' => (string) $row->name.($kind === 'product' && $row->sku ? ' · '.$row->sku : '')];
        }

        return $rows;
    }
}
