<?php

namespace App\Tax\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Contract\TaxAdministration;
use App\Tax\Domain\Model\TaxRate;
use App\Tax\Infrastructure\TaxInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class EloquentTaxAdministration implements TaxAdministration
{
    public function __construct(private TaxInput $input) {}

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    private function lock(): void
    {
        // The shared Catalog lock: fixed amounts follow primary-currency precision and conversion, so they serialize with it.
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
    }

    public function create(string $actor, SaveTaxData $data): string
    {
        return app(AuditLogger::class)->operation('tax', $actor, function () use ($actor, $data): string {
            return $this->guarded(fn (): string => DB::transaction(function () use ($actor, $data): string {
                $this->lock();
                $this->authorize($actor, 'taxes.create');
                $this->input->assertValid($data);
                $id = DB::table('taxes')->insertGetId([
                    'registration_number' => $data->registrationNumber, 'priority' => $data->priority, 'status' => $data->status,
                    'price_includes_tax' => $data->priceIncludesTax, 'address_basis' => $data->addressBasis,
                    'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->writeNames($id, $data);
                $this->writeRates($id, $data);

                return (string) $id;
            }));
        });
    }

    public function update(string $actor, string $id, int $version, SaveTaxData $data): void
    {
        app(AuditLogger::class)->operation('tax', $actor, function () use ($actor, $id, $version, $data): void {
            $this->guarded(fn () => DB::transaction(function () use ($actor, $id, $version, $data): void {
                $this->lock();
                $this->authorize($actor, 'taxes.update');
                $tax = $this->row($id, $version);
                $this->input->assertValid($data);
                $values = [
                    'registration_number' => $data->registrationNumber, 'priority' => $data->priority, 'status' => $data->status,
                    'price_includes_tax' => $data->priceIncludesTax, 'address_basis' => $data->addressBasis,
                ];
                $changed = false;
                foreach ($values as $field => $value) {
                    $changed = $changed || $tax->{$field} !== $value;
                }
                $changed = $this->writeNames((int) $id, $data) || $changed;
                $changed = $this->writeRates((int) $id, $data) || $changed;
                if ($changed) {
                    // Rates and names belong to the tax's version: any change advances it so stale editors are refused.
                    DB::table('taxes')->where('id', $id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                }
            }));
        });
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('tax', $actor, function () use ($actor, $id, $version): void {
            DB::transaction(function () use ($actor, $id, $version): void {
                $this->lock();
                $this->authorize($actor, 'taxes.delete');
                $this->row($id, $version);
                $assigned = DB::table('product_taxes')->where('tax_id', $id)->count();
                if ($assigned > 0) {
                    throw ValidationException::withMessages(['tax' => __('taxes.delete_assigned', ['count' => $assigned])]);
                }
                DB::table('taxes')->where('id', $id)->delete();
            });
        });
    }

    public function assignToAllProducts(string $actor, array $taxIds): array
    {
        return app(AuditLogger::class)->operation('tax', $actor, function () use ($actor, $taxIds): array {
            return DB::transaction(function () use ($actor, $taxIds): array {
                $this->lock();
                $this->authorize($actor, 'taxes.assign');
                $this->authorize($actor, 'products.update');
                $ids = array_values(array_unique(array_map(strval(...), $taxIds)));
                if ($ids === []) {
                    throw ValidationException::withMessages(['tax_ids' => __('taxes.assign_none')]);
                }
                $active = DB::table('taxes')->whereIn('id', $ids)->where('status', 'active')->pluck('id')->map(strval(...))->all();
                if (count($active) !== count($ids)) {
                    throw ValidationException::withMessages(['tax_ids' => __('taxes.assign_ineligible')]);
                }
                $marks = implode(',', array_fill(0, count($ids), '?'));
                // One set-based statement: only missing pairs are inserted, and only the products that actually gained
                // an assignment advance their version, so an editor opened before the operation cannot save stale taxes.
                $result = DB::selectOne("WITH inserted AS (
                        INSERT INTO product_taxes (product_id, tax_id) SELECT p.id, t.id FROM products p CROSS JOIN taxes t WHERE t.id IN ($marks)
                        ON CONFLICT DO NOTHING RETURNING product_id
                    ), touched AS (
                        UPDATE products SET lock_version = lock_version + 1 FROM (SELECT DISTINCT product_id FROM inserted) i WHERE products.id = i.product_id RETURNING products.id
                    ) SELECT (SELECT count(*) FROM inserted) AS assignments, (SELECT count(*) FROM touched) AS products", $ids);

                return ['products' => (int) $result->products, 'assignments' => (int) $result->assignments];
            });
        });
    }

    private function row(string $id, int $version): object
    {
        $tax = DB::table('taxes')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('taxes.stale')]);
        if ((int) $tax->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('taxes.stale')]);
        }

        return $tax;
    }

    /** Replaces name rows for the active languages; returns whether anything changed. */
    private function writeNames(int $id, SaveTaxData $data): bool
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        $existing = DB::table('tax_translations')->where('tax_id', $id)->pluck('name', 'locale_id')->mapWithKeys(fn ($name, $locale): array => [(int) $locale => $name])->all();
        $changed = false;
        foreach ($locales as $code => $localeId) {
            $name = $data->names[$code] ?? null;
            if ($name === null) {
                if (isset($existing[$localeId])) {
                    DB::table('tax_translations')->where('tax_id', $id)->where('locale_id', $localeId)->delete();
                    $changed = true;
                }
            } elseif (($existing[$localeId] ?? null) !== $name) {
                DB::table('tax_translations')->updateOrInsert(['tax_id' => $id, 'locale_id' => $localeId], ['name' => $name]);
                $changed = true;
            }
        }

        return $changed;
    }

    /** Makes the stored rate list equal to the submitted one; returns whether anything changed. */
    private function writeRates(int $id, SaveTaxData $data): bool
    {
        $existing = DB::table('tax_rates')->where('tax_id', $id)->get()->keyBy(fn ($rate): string => (string) $rate->rate_area_id);
        $submitted = [];
        $changed = false;
        foreach ($data->rates as $rate) {
            $submitted[$rate->areaId] = true;
            $percent = $rate->type === TaxRate::PERCENT;
            $values = [
                'rate_type' => $rate->type,
                // Percentages are persisted as exact fractions: 24 (%) is 0.24.
                'percent_rate' => $percent ? Decimal::multiply($rate->value, '0.01') : null,
                'fixed_amount' => $percent ? null : $rate->value,
            ];
            $current = $existing->get($rate->areaId);
            if ($current === null) {
                DB::table('tax_rates')->insert(['tax_id' => $id, 'rate_area_id' => $rate->areaId, ...$values, 'created_at' => now(), 'updated_at' => now()]);
                $changed = true;
            } elseif ($current->rate_type !== $values['rate_type']
                || ($percent ? Decimal::compare((string) $current->percent_rate, $values['percent_rate']) !== 0 : Decimal::compare((string) ($current->fixed_amount ?? '-1'), $values['fixed_amount']) !== 0)) {
                DB::table('tax_rates')->where('id', $current->id)->update([...$values, 'updated_at' => now()]);
                $changed = true;
            }
        }
        foreach ($existing as $areaId => $current) {
            if (! isset($submitted[$areaId])) {
                DB::table('tax_rates')->where('id', $current->id)->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            // A rate area removed between validation and write, or a duplicate rate that raced the checks.
            if (in_array($e->getCode(), ['23503', '23505'], true)) {
                throw ValidationException::withMessages(['rates' => __('taxes.rate_area_invalid')]);
            }
            throw $e;
        }
    }
}
