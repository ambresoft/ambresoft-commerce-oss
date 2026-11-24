<?php

namespace App\Reporting\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;

/** Localized names for report rows and filter choices: the admin's language first, then the default language. */
final class ReportNames
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $statuses = null;

    /** @return array{current: int, default: int} */
    private function locales(): array
    {
        $catalog = app(LocaleCatalog::class);

        return ['current' => (int) ($catalog->active()->firstWhere('code', app()->getLocale())->id ?? 0), 'default' => (int) ($catalog->active()->firstWhere('code', $catalog->defaultCode())->id ?? 0)];
    }

    /**
     * Order statuses with their meaning for reports: `void` (final and restoring stock: cancelled/failed) and `open`
     * (the initial status), alongside the configured name and colour.
     *
     * @return array<string, array{id: string, code: string, name: string, color: string, initial: bool, final: bool, void: bool, active: bool}>
     */
    public function statuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }
        $l = $this->locales();
        $names = DB::table('order_status_translations')->whereIn('locale_id', [$l['current'], $l['default']])->get(['status_id', 'locale_id', 'name'])->groupBy('status_id');
        $result = [];
        foreach (DB::table('order_statuses')->orderBy('position')->orderBy('id')->get() as $s) {
            $byLocale = collect($names[$s->id] ?? [])->pluck('name', 'locale_id');
            $name = trim((string) ($byLocale[$l['current']] ?? '')) !== '' ? $byLocale[$l['current']] : (string) ($byLocale[$l['default']] ?? $s->code);
            $result[(string) $s->id] = ['id' => (string) $s->id, 'code' => $s->code, 'name' => $name, 'color' => $s->color, 'initial' => (bool) $s->is_initial, 'final' => (bool) $s->is_final, 'void' => (bool) $s->is_final && $s->stock_action === 'restore', 'active' => (bool) $s->is_active];
        }

        return $this->statuses = $result;
    }

    /**
     * Product names by ID (localized, then the default language).
     *
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    public function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $l = $this->locales();
        $names = DB::table('product_translations')->whereIn('product_id', $ids)->whereIn('locale_id', [$l['current'], $l['default']])->get(['product_id', 'locale_id', 'name'])->groupBy('product_id');
        $result = [];
        foreach ($ids as $id) {
            $byLocale = collect($names[$id] ?? [])->pluck('name', 'locale_id');
            $name = trim((string) ($byLocale[$l['current']] ?? '')) !== '' ? $byLocale[$l['current']] : (string) ($byLocale[$l['default']] ?? '');
            $result[(string) $id] = $name;
        }

        return $result;
    }

    /** @return list<array{value: string, label: string}> Payment methods (stored name in the admin's language) for filters. */
    public function paymentMethods(): array
    {
        $l = $this->locales();
        $names = DB::table('payment_method_translations')->whereIn('locale_id', [$l['current'], $l['default']])->get(['method_id', 'locale_id', 'name'])->groupBy('method_id');

        return DB::table('payment_methods')->orderBy('position')->orderBy('id')->get(['id', 'code'])->map(function (object $m) use ($names, $l): array {
            $byLocale = collect($names[$m->id] ?? [])->pluck('name', 'locale_id');

            return ['value' => (string) $m->id, 'label' => (string) ($byLocale[$l['current']] ?? $byLocale[$l['default']] ?? $m->code)];
        })->all();
    }

    /** @return list<array{value: string, label: string}> */
    public function shippingMethods(): array
    {
        $l = $this->locales();
        $names = DB::table('shipping_method_translations')->whereIn('locale_id', [$l['current'], $l['default']])->get(['method_id', 'locale_id', 'name'])->groupBy('method_id');

        return DB::table('shipping_methods')->orderBy('position')->orderBy('id')->get(['id'])->map(function (object $m) use ($names, $l): array {
            $byLocale = collect($names[$m->id] ?? [])->pluck('name', 'locale_id');

            return ['value' => (string) $m->id, 'label' => (string) ($byLocale[$l['current']] ?? $byLocale[$l['default']] ?? '#'.$m->id)];
        })->all();
    }
}
