<?php

namespace App\Pricing\Infrastructure;

use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Domain\Service\ProductSaleRules;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Product saves hold the catalog/product lock; order writes lock all affected products in ID order before pricing. */
final class ProductSales
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sales_submitted' => ['sometimes', 'boolean'], 'sales' => ['sometimes', 'array', 'max:100'], 'sales.*' => ['array'],
            'sales.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'sales.*.customer_group_id' => ['nullable', 'integer', 'min:1'],
            'sales.*.minimum_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'sales.*.priority' => ['required', 'integer', 'min:0', 'max:99999'],
            'sales.*.kind' => ['required', Rule::in(['fixed', 'percentage'])],
            'sales.*.amount' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D'],
            'sales.*.stock_limit' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'sales.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'sales.*.ends_on' => ['nullable', 'date_format:Y-m-d'],
            'sales.*.starts_time' => ['nullable', 'date_format:H:i'],
            'sales.*.ends_time' => ['nullable', 'date_format:H:i'],
            'sales.*.titles' => ['sometimes', 'array', 'max:50'],
            'sales.*.titles.*' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function editor(string $product): array
    {
        $rows = DB::table('product_sales')->where('product_id', $product)->where('is_enabled', true)->orderBy('minimum_quantity')->orderBy('priority')->orderBy('id')->get();
        $usage = $this->usage($rows->pluck('id')->all());
        $titles = [];
        foreach (DB::table('product_sale_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('t.sale_id', $rows->pluck('id'))->get(['t.sale_id', 'l.code', 't.title']) as $t) {
            $titles[$t->sale_id][$t->code] = $t->title;
        }

        return $rows->map(function (object $s) use ($usage, $titles): array {
            $used = $usage[$s->id] ?? 0;

            return ['id' => (string) $s->id, 'customer_group_id' => (string) $s->customer_group_id, 'minimum_quantity' => (string) $s->minimum_quantity, 'priority' => (string) $s->priority, 'kind' => $s->kind, 'amount' => Decimal::trim($s->amount), 'stock_limit' => $s->stock_limit === null ? '' : (string) $s->stock_limit, 'starts_on' => $s->starts_on ?? '', 'ends_on' => $s->ends_on ?? '', 'starts_time' => substr((string) $s->starts_time, 0, 5), 'ends_time' => substr((string) $s->ends_time, 0, 5), 'titles' => (object) ($titles[$s->id] ?? []), 'used' => $used];
        })->all();
    }

    /** @param list<array<string, mixed>> $rows */
    public function sync(string $product, array $rows): void
    {
        $existing = DB::table('product_sales')->where('product_id', $product)->where('is_enabled', true)->get()->keyBy('id');
        $kept = [];
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        foreach ($rows as $index => $input) {
            $id = empty($input['id']) ? null : (string) $input['id'];
            $old = $id === null ? null : $existing[$id] ?? null;
            if ($id !== null && ($old === null || in_array($id, $kept, true))) {
                throw ValidationException::withMessages(['sales.'.$index.'.id' => __('sales.stale')]);
            }
            $groupId = ($input['customer_group_id'] ?? '') === '' || ($input['customer_group_id'] ?? null) === null ? null : (string) $input['customer_group_id'];
            if ($groupId !== null) {
                $group = DB::table('customer_groups')->where('id', $groupId)->sharedLock()->first();
                if ($group === null || ($group->status !== 'active' && (string) ($old->customer_group_id ?? '') !== (string) $group->id)) {
                    throw ValidationException::withMessages(['sales.'.$index.'.customer_group_id' => __('sales.group_invalid')]);
                }
            }
            if (! empty($input['starts_on']) && ! empty($input['ends_on']) && $input['ends_on'] < $input['starts_on']) {
                throw ValidationException::withMessages(['sales.'.$index.'.ends_on' => __('sales.date_invalid')]);
            }
            foreach (['starts', 'ends'] as $edge) {
                if (! empty($input[$edge.'_time']) && empty($input[$edge.'_on'])) {
                    throw ValidationException::withMessages(['sales.'.$index.'.'.$edge.'_on' => __('sales.date_required')]);
                }
            }
            if (! empty($input['starts_on']) && ! empty($input['ends_on']) && $input['ends_on'] === $input['starts_on'] && ($input['ends_time'] ?? '') !== '' && ($input['starts_time'] ?? '') !== '' && $input['ends_time'] <= $input['starts_time']) {
                throw ValidationException::withMessages(['sales.'.$index.'.ends_time' => __('sales.date_invalid')]);
            }
            $amount = Decimal::trim((string) $input['amount']);
            if (($input['kind'] === 'percentage' && Decimal::compare($amount, '100') > 0) || ($input['kind'] === 'fixed' && Decimal::scale($amount) > $scale)) {
                throw ValidationException::withMessages(['sales.'.$index.'.amount' => __('sales.amount_invalid', ['decimals' => $scale])]);
            }
            $used = $id === null ? 0 : (int) DB::table('product_sale_allocations')->where('sale_id', $id)->sum('quantity');
            $limit = ($input['stock_limit'] ?? '') === '' || ($input['stock_limit'] ?? null) === null ? null : (int) $input['stock_limit'];
            if ($limit !== null && $limit < $used) {
                throw ValidationException::withMessages(['sales.'.$index.'.stock_limit' => __('sales.stock_used', ['count' => $used])]);
            }
            $values = ['customer_group_id' => $groupId, 'minimum_quantity' => $input['minimum_quantity'], 'priority' => $input['priority'], 'kind' => $input['kind'], 'amount' => $amount, 'stock_limit' => $limit, 'starts_on' => empty($input['starts_on']) ? null : $input['starts_on'], 'ends_on' => empty($input['ends_on']) ? null : $input['ends_on'], 'starts_time' => empty($input['starts_on']) || empty($input['starts_time']) ? null : $input['starts_time'], 'ends_time' => empty($input['ends_on']) || empty($input['ends_time']) ? null : $input['ends_time'], 'updated_at' => now()];
            if ($id === null) {
                $id = (string) DB::table('product_sales')->insertGetId([...$values, 'product_id' => $product, 'created_at' => now()]);
            } else {
                DB::table('product_sales')->where('id', $id)->update($values);
            }
            $this->syncTitles($id, (array) ($input['titles'] ?? []));
            $kept[] = $id;
        }
        foreach ($existing as $id => $row) {
            if (in_array((string) $id, $kept, true)) {
                continue;
            }
            if (DB::table('product_sale_allocations')->where('sale_id', $id)->exists()) {
                DB::table('product_sales')->where('id', $id)->update(['is_enabled' => false, 'updated_at' => now()]);
            } else {
                DB::table('product_sales')->where('id', $id)->delete();
            }
        }
    }

    /** @param array<string, mixed> $titles Locale code => title; blank titles are removed, unknown languages ignored. */
    private function syncTitles(string $sale, array $titles): void
    {
        $locales = DB::table('locales')->pluck('id', 'code');
        $keep = [];
        foreach ($titles as $code => $title) {
            $title = trim((string) $title);
            if ($title === '' || ! isset($locales[$code])) {
                continue;
            }
            DB::table('product_sale_translations')->updateOrInsert(['sale_id' => $sale, 'locale_id' => $locales[$code]], ['title' => $title]);
            $keep[] = $locales[$code];
        }
        DB::table('product_sale_translations')->where('sale_id', $sale)->whereNotIn('locale_id', $keep)->delete();
    }

    public function copy(string $source, string $target): void
    {
        foreach (DB::table('product_sales')->where('product_id', $source)->where('is_enabled', true)->get() as $sale) {
            $row = (array) $sale;
            unset($row['id']);
            $id = DB::table('product_sales')->insertGetId([...$row, 'product_id' => $target, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('product_sale_translations')->where('sale_id', $sale->id)->get(['locale_id', 'title']) as $t) {
                DB::table('product_sale_translations')->insert(['sale_id' => $id, 'locale_id' => $t->locale_id, 'title' => $t->title]);
            }
        }
    }

    /** @return array{id: string, price: string}|null */
    public function price(string $product, string $base, int $quantity, ?string $customer, int $scale): ?array
    {
        return $this->prices([$product => ['base' => $base, 'quantity' => $quantity]], $customer, $scale)[$product] ?? null;
    }

    /** Shared batch pricing for catalog rendering; same groups, quotas, dates and precedence as order pricing.
     * @param  array<int|string, array{base: string, quantity: int}>  $products
     * @return array<int|string, array{id: string, price: string}|null>
     */
    public function prices(array $products, ?string $customer, int $scale): array
    {
        if ($products === []) {
            return [];
        }
        $groups = $customer === null ? [] : DB::table('customer_group_members')->where('customer_id', $customer)->pluck('group_id')->all();
        if ($groups === []) {
            $groups = DB::table('customer_groups')->where('is_default', true)->pluck('id')->all();
        }
        $rows = DB::table('product_sales as s')->leftJoin('customer_groups as g', 'g.id', '=', 's.customer_group_id')->whereIn('s.product_id', array_keys($products))->where('s.is_enabled', true)->where(fn ($query) => $query->whereNull('s.customer_group_id')->orWhere(fn ($q) => $q->where('g.status', 'active')->whereIn('g.id', $groups)))->get(['s.*']);
        $usage = $this->usage($rows->pluck('id')->all());
        $offers = [];
        foreach ($rows as $s) {
            $used = $usage[$s->id] ?? 0;
            $offers[$s->product_id][] = ['id' => (string) $s->id, 'kind' => $s->kind, 'amount' => $s->amount, 'minimum_quantity' => (int) $s->minimum_quantity, 'priority' => (int) $s->priority, 'starts_on' => $s->starts_on, 'ends_on' => $s->ends_on, 'starts_time' => $s->starts_time, 'ends_time' => $s->ends_time, 'remaining' => $s->stock_limit === null ? null : max(0, (int) $s->stock_limit - $used)];
        }
        $date = now()->timezone(app(GeneralSettings::class)->all()['timezone'])->format('Y-m-d H:i:s');
        $result = [];
        foreach ($products as $id => $p) {
            $result[$id] = app(ProductSaleRules::class)->choose($p['base'], $p['quantity'], $date, $offers[$id] ?? [], $scale);
        }

        return $result;
    }

    /** @param list<int|string> $ids
     * @return array<int|string, int>
     */
    private function usage(array $ids): array
    {
        return DB::table('product_sale_allocations')->whereIn('sale_id', $ids)->selectRaw('sale_id, sum(quantity) as used')->groupBy('sale_id')->get()->mapWithKeys(fn (object $row): array => [$row->sale_id => (int) $row->used])->all();
    }

    /** Locks product stock and sale quotas in the same order used by stock movement and product saves.
     * @param  list<string>  $products
     */
    public function lockProducts(array $products): void
    {
        DB::table('products')->whereIn('id', array_unique($products))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public function allocation(string $item): ?string
    {
        $id = DB::table('product_sale_allocations')->where('item_id', $item)->value('sale_id');

        return $id === null ? null : (string) $id;
    }

    /** Called after an order line is written; the product lock serializes availability checks and reservations. */
    public function reserve(string $item, ?string $sale, int $quantity): void
    {
        if ($sale === null) {
            DB::table('product_sale_allocations')->where('item_id', $item)->delete();

            return;
        }
        $offer = DB::table('product_sales')->where('id', $sale)->lockForUpdate()->first();
        if ($offer === null) {
            throw ValidationException::withMessages(['items' => __('sales.stale')]);
        }
        $used = (int) DB::table('product_sale_allocations')->where('sale_id', $sale)->where('item_id', '<>', $item)->sum('quantity');
        if ($offer->stock_limit !== null && $used + $quantity > (int) $offer->stock_limit) {
            throw ValidationException::withMessages(['items' => __('sales.stock_insufficient')]);
        }
        DB::table('product_sale_allocations')->updateOrInsert(['item_id' => $item], ['sale_id' => $sale, 'quantity' => $quantity]);
    }

    public function orderStatus(string $order, bool $release): void
    {
        $items = DB::table('order_items as i')->join('product_sale_allocations as a', 'a.item_id', '=', 'i.id')->where('i.order_id', $order)->get(['i.id', 'i.product_id', 'i.quantity', 'a.sale_id']);
        $this->lockProducts($items->pluck('product_id')->filter()->map(strval(...))->all());
        foreach ($items as $item) {
            $this->reserve((string) $item->id, (string) $item->sale_id, $release ? 0 : (int) $item->quantity);
        }
    }
}
