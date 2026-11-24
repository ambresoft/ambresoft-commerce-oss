<?php

namespace App\Tax\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\ProductTaxAssignment;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Local sample data for trying the tax screens with Greek addresses. The figures are illustrative samples, not
 * legal tax rates or advice; every name is marked "(sample)". Explicit opt-in only, never in production.
 *
 * Records are tracked by stable keys in the shared demo-record table, so a second run changes nothing and
 * administrator edits (renamed areas, changed rates) are preserved.
 */
final class TaxDemoSeeder
{
    /** @return array{areas: int, taxes: int, products: int} Records created by this run. */
    public function run(string $actor): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Tax demo seeding is restricted to local and testing environments.');
        }

        return DB::transaction(function () use ($actor): array {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $created = ['areas' => 0, 'taxes' => 0, 'products' => 0];
            $areas = app(RateAreaAdministration::class);
            $taxes = app(TaxAdministration::class);

            $greece = $this->record('area.greece', 'rate_areas', $created, 'areas', fn (): string => $areas->create($actor, new SaveRateAreaData(
                $this->names('Greece (sample)', 'Ελλάδα (δείγμα)'), 'active', false, ['GR'],
            )));
            // Region codes follow ISO 3166-2:GR (K North Aegean, L South Aegean); it wins over the whole-country area.
            $islands = $this->record('area.aegean', 'rate_areas', $created, 'areas', fn (): string => $areas->create($actor, new SaveRateAreaData(
                $this->names('Greek Aegean islands (sample)', 'Νησιά Αιγαίου (δείγμα)'), 'active', false, ['GR'], ['GR' => ['K', 'L']],
            )));
            // Only one default area can exist: an existing one (the sample's or the merchant's own) is reused as is.
            $existingDefault = DB::table('rate_areas')->where('is_default', true)->value('id');
            $world = $existingDefault !== null ? (string) $existingDefault : $this->record('area.world', 'rate_areas', $created, 'areas', fn (): string => $areas->create($actor, new SaveRateAreaData(
                $this->names('Rest of the world (sample)', 'Υπόλοιπος κόσμος (δείγμα)'), 'active', true,
            )));

            $standard = $this->record('tax.standard', 'taxes', $created, 'taxes', fn (): string => $taxes->create($actor, new SaveTaxData(
                $this->names('VAT – standard (sample)', 'ΦΠΑ – κανονικός (δείγμα)'), null, 1, 'active', true, 'shipping', [
                    new SaveTaxRateData($greece, 'percent', '24'), new SaveTaxRateData($islands, 'percent', '17'), new SaveTaxRateData($world, 'percent', '0'),
                ],
            )));
            $this->record('tax.reduced', 'taxes', $created, 'taxes', fn (): string => $taxes->create($actor, new SaveTaxData(
                $this->names('VAT – reduced (sample)', 'ΦΠΑ – μειωμένος (δείγμα)'), null, 1, 'active', true, 'shipping', [
                    new SaveTaxRateData($greece, 'percent', '13'), new SaveTaxRateData($islands, 'percent', '9'), new SaveTaxRateData($world, 'percent', '0'),
                ],
            )));
            // A fixed amount added on top (priority 0, before VAT) shows added-tax compounding and the zero rate.
            $this->record('tax.recycling', 'taxes', $created, 'taxes', fn (): string => $taxes->create($actor, new SaveTaxData(
                $this->names('Recycling fee (sample)', 'Τέλος ανακύκλωσης (δείγμα)'), null, 0, 'active', false, 'shipping', [
                    new SaveTaxRateData($greece, 'fixed', '0.50'), new SaveTaxRateData($islands, 'fixed', '0.50'), new SaveTaxRateData($world, 'percent', '0'),
                ],
            )));

            $created['products'] = $this->assignToFeatureDemoProducts($actor, $standard);

            return $created;
        });
    }

    /**
     * Gives the Phase 5 demo products the standard sample VAT, so the editor and calculation show something real.
     * Only products created by that demo are touched, and only with the sample tax.
     */
    private function assignToFeatureDemoProducts(string $actor, string $taxId): int
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('products.update');
        $assignments = app(ProductTaxAssignment::class);
        $changed = 0;
        foreach (DB::table('feature_demo_records')->where('entity', 'products')->pluck('entity_id') as $productId) {
            if (! DB::table('products')->where('id', $productId)->exists()) {
                continue;
            }
            $current = $assignments->assigned((string) $productId);
            if (in_array($taxId, $current, true)) {
                continue;
            }
            $assignments->sync((string) $productId, [...$current, $taxId]);
            // Editors opened before the change must not overwrite it.
            DB::table('products')->where('id', $productId)->increment('lock_version');
            $changed++;
        }

        return $changed;
    }

    /** @return array<string, string> English text for every active language except Greek. */
    private function names(string $en, string $el): array
    {
        $names = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            $names[$code] = $code === 'el' ? $el : $en;
        }

        return $names;
    }

    /**
     * @param  array<string, int>  $created
     * @param  callable(): string  $create
     */
    private function record(string $key, string $table, array &$created, string $counter, callable $create): string
    {
        $key = 'phase8.'.$key;
        $existing = DB::table('feature_demo_records')->where('key', $key)->first();
        if ($existing !== null) {
            if ($existing->entity !== $table || ! DB::table($table)->where('id', $existing->entity_id)->exists()) {
                throw new RuntimeException('Demo dependency was removed: '.$key.'. No data was changed.');
            }

            return (string) $existing->entity_id;
        }
        $id = $create();
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => $table, 'entity_id' => $id]);
        $created[$counter]++;

        return $id;
    }
}
