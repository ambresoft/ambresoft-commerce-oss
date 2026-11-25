<?php

namespace App\Tax\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Application\Service\PriceDisplay;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Geography\Countries;
use App\Tax\Domain\Model\TaxRate;
use Illuminate\Support\Facades\DB;

/** Admin read models for taxes, rate areas and the product editor's tax control. */
final class TaxQueries
{
    public function __construct(private TaxNames $names, private Countries $countries) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed> A paginator array.
     */
    public function listing(array $filters): array
    {
        $query = DB::table('taxes as t')->select('t.*')->selectRaw("COALESCE((SELECT name FROM tax_translations WHERE tax_id = t.id AND locale_id = ?), (SELECT name FROM tax_translations WHERE tax_id = t.id AND locale_id = ?), '') AS sort_name", [$this->currentLocale(), $this->defaultLocale()]);
        if (($filters['search'] ?? '') !== '') {
            $like = '%'.addcslashes((string) $filters['search'], '\\%_').'%';
            $query->where(fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('tax_translations as s')->whereColumn('s.tax_id', 't.id')->where('s.name', 'ilike', $like))
                ->orWhere('t.registration_number', 'ilike', $like));
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('t.status', $filters['status']);
        }
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($filters['sort'] ?? 'priority') {
            'name' => $query->orderBy('sort_name', $direction),
            'status' => $query->orderBy('t.status', $direction),
            default => $query->orderBy('t.priority', $direction)->orderBy('sort_name'),
        };
        $page = $query->orderBy('t.id')->paginate(25)->withQueryString();
        $ids = $page->getCollection()->pluck('id')->all();
        $summaries = $this->rateSummaries($ids);
        $products = DB::table('product_taxes')->whereIn('tax_id', $ids)->selectRaw('tax_id, count(*) as total')->groupBy('tax_id')->pluck('total', 'tax_id');
        $page->setCollection($page->getCollection()->map(fn ($row): array => [
            'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'name' => $row->sort_name !== '' ? $row->sort_name : '#'.$row->id,
            'registration_number' => $row->registration_number, 'priority' => (int) $row->priority, 'status' => $row->status,
            'price_includes_tax' => (bool) $row->price_includes_tax, 'address_basis' => $row->address_basis,
            'rates' => $summaries[(string) $row->id] ?? [], 'products' => (int) ($products[$row->id] ?? 0),
        ]));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $tax = DB::table('taxes')->where('id', $id)->first();
        if ($tax === null) {
            return null;
        }
        $names = DB::table('tax_translations')->join('locales', 'locales.id', '=', 'tax_translations.locale_id')->where('tax_id', $id)->pluck('tax_translations.name', 'locales.code')->all();
        $rates = DB::table('tax_rates')->where('tax_id', $id)->orderBy('id')->get()->map(fn ($rate): array => [
            'area_id' => (string) $rate->rate_area_id, 'type' => $rate->rate_type,
            // The editor works in percent figures; storage is an exact fraction.
            'value' => $rate->rate_type === TaxRate::PERCENT ? Decimal::trim(Decimal::multiply((string) $rate->percent_rate, '100')) : Decimal::trim((string) $rate->fixed_amount),
        ])->all();

        return [
            'id' => (string) $tax->id, 'version' => (int) $tax->lock_version, 'names' => (object) $names, 'registration_number' => $tax->registration_number ?? '',
            'priority' => (int) $tax->priority, 'status' => $tax->status, 'price_includes_tax' => (bool) $tax->price_includes_tax, 'address_basis' => $tax->address_basis,
            'rates' => $rates, 'products' => DB::table('product_taxes')->where('tax_id', $id)->count(),
        ];
    }

    /** @return list<array{id: string, name: string, default: bool, active: bool}> */
    public function areaOptions(): array
    {
        $rows = DB::table('rate_areas')->orderBy('id')->get();
        $names = $this->names->for('rate_area_translations', 'rate_area_id', $rows->pluck('id')->all());

        return $rows->map(fn ($row): array => ['id' => (string) $row->id, 'name' => $names[(string) $row->id] ?? '#'.$row->id, 'default' => (bool) $row->is_default, 'active' => $row->status === 'active'])->all();
    }

    /** @return list<array<string, mixed>> */
    public function areaListing(): array
    {
        $rows = DB::table('rate_areas')->orderByDesc('is_default')->orderBy('id')->get();
        $ids = $rows->pluck('id')->all();
        $names = $this->names->for('rate_area_translations', 'rate_area_id', $ids);
        $members = DB::table('rate_area_countries')->whereIn('rate_area_id', $ids)->orderBy('country_code')->get()->groupBy('rate_area_id');
        $regions = DB::table('rate_area_regions')->whereIn('rate_area_id', $ids)->selectRaw('rate_area_id, count(*) as total')->groupBy('rate_area_id')->pluck('total', 'rate_area_id');
        $used = DB::table('tax_rates')->whereIn('rate_area_id', $ids)->selectRaw('rate_area_id, count(*) as total')->groupBy('rate_area_id')->pluck('total', 'rate_area_id');

        return $rows->map(function ($row) use ($names, $members, $regions, $used): array {
            $codes = ($members[$row->id] ?? collect())->pluck('country_code')->all();

            return [
                'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'name' => $names[(string) $row->id] ?? '#'.$row->id, 'status' => $row->status, 'default' => (bool) $row->is_default,
                'countries' => array_map(fn (string $code): string => $this->countries->name($code), array_slice($codes, 0, 4)), 'country_count' => count($codes),
                'regions' => (int) ($regions[$row->id] ?? 0), 'taxes' => (int) ($used[$row->id] ?? 0),
            ];
        })->all();
    }

    /** @return array<string, mixed>|null */
    public function areaEditor(string $id): ?array
    {
        $area = DB::table('rate_areas')->where('id', $id)->first();
        if ($area === null) {
            return null;
        }
        $names = DB::table('rate_area_translations')->join('locales', 'locales.id', '=', 'rate_area_translations.locale_id')->where('rate_area_id', $id)->pluck('rate_area_translations.name', 'locales.code')->all();
        $regions = [];
        foreach (DB::table('rate_area_regions')->where('rate_area_id', $id)->orderBy('country_code')->orderBy('region_code')->get() as $row) {
            $regions[$row->country_code][] = $row->region_code;
        }

        return [
            'id' => (string) $area->id, 'version' => (int) $area->lock_version, 'names' => (object) $names, 'status' => $area->status, 'is_default' => (bool) $area->is_default,
            'countries' => DB::table('rate_area_countries')->where('rate_area_id', $id)->orderBy('country_code')->pluck('country_code')->all(),
            'regions' => (object) $regions, 'taxes' => DB::table('tax_rates')->where('rate_area_id', $id)->count(),
        ];
    }

    /**
     * Taxes offered by the product editor: every active tax, plus any tax the product keeps although it is now disabled.
     *
     * @param  list<string>  $assigned
     * @return list<array{id: string, name: string, active: bool, price_includes_tax: bool, priority: int}>
     */
    public function productOptions(array $assigned): array
    {
        $rows = DB::table('taxes')->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $assigned))->orderBy('priority')->orderBy('id')->get();
        $names = $this->names->for('tax_translations', 'tax_id', $rows->pluck('id')->all());

        return $rows->map(fn ($row): array => ['id' => (string) $row->id, 'name' => $names[(string) $row->id] ?? '#'.$row->id, 'active' => $row->status === 'active', 'price_includes_tax' => (bool) $row->price_includes_tax, 'priority' => (int) $row->priority])->all();
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, list<array<string, string>>>
     */
    private function rateSummaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rates = DB::table('tax_rates')->whereIn('tax_id', $ids)->orderBy('rate_area_id')->get();
        $areas = $this->names->for('rate_area_translations', 'rate_area_id', $rates->pluck('rate_area_id')->unique()->values()->all());
        $prices = app(PriceDisplay::class);
        $converter = $prices->converter();
        $summary = [];
        foreach ($rates as $rate) {
            $summary[(string) $rate->tax_id][] = [
                'area' => $areas[(string) $rate->rate_area_id] ?? '#'.$rate->rate_area_id, 'type' => $rate->rate_type,
                'label' => $rate->rate_type === TaxRate::PERCENT ? Decimal::trim(Decimal::multiply((string) $rate->percent_rate, '100')).'%' : $prices->format(Decimal::trim((string) $rate->fixed_amount), null, $converter),
            ];
        }

        return $summary;
    }

    private function currentLocale(): ?int
    {
        return app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())?->id;
    }

    private function defaultLocale(): ?int
    {
        return app(LocaleCatalog::class)->active()->firstWhere('is_default', true)?->id;
    }
}
