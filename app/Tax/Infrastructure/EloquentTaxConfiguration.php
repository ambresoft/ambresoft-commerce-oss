<?php

namespace App\Tax\Infrastructure;

use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Contract\TaxConfiguration;
use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\CalculationSettings;
use App\Tax\Domain\Model\RateArea;
use App\Tax\Domain\Model\TaxDefinition;
use App\Tax\Domain\Model\TaxRate;
use Illuminate\Support\Facades\DB;

/** Reads authoritative configuration directly from PostgreSQL; nothing is cached across calls. */
final readonly class EloquentTaxConfiguration implements TaxConfiguration
{
    public function __construct(private TaxNames $names, private CurrencyRepository $currencies) {}

    public function definitions(array $taxIds): array
    {
        if ($taxIds === []) {
            return [];
        }
        $rows = DB::table('taxes')->whereIn('id', $taxIds)->orderBy('id')->get();
        $names = $this->names->for('tax_translations', 'tax_id', $rows->pluck('id')->all());
        $rates = [];
        foreach (DB::table('tax_rates')->whereIn('tax_id', $rows->pluck('id'))->orderBy('rate_area_id')->get() as $rate) {
            $rates[(string) $rate->tax_id][] = $rate->rate_type === TaxRate::PERCENT
                ? TaxRate::percent((string) $rate->rate_area_id, Decimal::trim((string) $rate->percent_rate))
                : TaxRate::fixed((string) $rate->rate_area_id, Decimal::trim((string) $rate->fixed_amount));
        }
        $definitions = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $definitions[$id] = new TaxDefinition($id, $names[$id] ?? '#'.$id, (int) $row->priority, $row->status === 'active', (bool) $row->price_includes_tax, $row->address_basis, $rates[$id] ?? []);
        }

        return $definitions;
    }

    public function areas(): array
    {
        $rows = DB::table('rate_areas')->orderBy('id')->get();
        $names = $this->names->for('rate_area_translations', 'rate_area_id', $rows->pluck('id')->all());
        $countries = [];
        foreach (DB::table('rate_area_countries')->orderBy('country_code')->get() as $row) {
            $countries[(string) $row->rate_area_id][] = $row->country_code;
        }
        $regions = [];
        foreach (DB::table('rate_area_regions')->orderBy('country_code')->orderBy('region_code')->get() as $row) {
            $regions[(string) $row->rate_area_id][$row->country_code][] = $row->region_code;
        }
        $areas = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $areas[$id] = new RateArea($id, $names[$id] ?? '#'.$id, $row->status === 'active', (bool) $row->is_default, $countries[$id] ?? [], $regions[$id] ?? []);
        }

        return $areas;
    }

    public function assignments(array $productIds): array
    {
        $assignments = array_fill_keys($productIds, []);
        if ($productIds === []) {
            return $assignments;
        }
        foreach (DB::table('product_taxes')->whereIn('product_id', $productIds)->orderBy('tax_id')->get() as $row) {
            $assignments[(string) $row->product_id][] = (string) $row->tax_id;
        }

        return $assignments;
    }

    public function settings(): CalculationSettings
    {
        // A fresh read each call: settings may change between calculations in one process.
        $s = (new GeneralSettings)->all();

        return new CalculationSettings(
            $s['tax_calculation_method'], $s['tax_estimate_without_address'],
            $s['tax_default_country'] === null ? null : new Address($s['tax_default_country'], $s['tax_default_region']),
            $s['display_catalog_prices_with_tax'], $s['display_cart_prices_with_tax'],
        );
    }

    public function scale(): int
    {
        return $this->currencies->currentPrimary()->decimals;
    }
}
