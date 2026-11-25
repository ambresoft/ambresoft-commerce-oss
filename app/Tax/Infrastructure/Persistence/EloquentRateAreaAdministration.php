<?php

namespace App\Tax\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Infrastructure\RateAreaInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class EloquentRateAreaAdministration implements RateAreaAdministration
{
    public function __construct(private RateAreaInput $input) {}

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
    }

    public function create(string $actor, SaveRateAreaData $data): string
    {
        return app(AuditLogger::class)->operation('rate_area', $actor, function () use ($actor, $data): string {
            return $this->guarded(fn (): string => DB::transaction(function () use ($actor, $data): string {
                $this->lock();
                $this->authorize($actor, 'rate-areas.manage');
                $this->input->assertValid($data);
                $this->assertDefaultFree($data, null);
                $id = DB::table('rate_areas')->insertGetId(['status' => $data->status, 'is_default' => $data->default, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                $this->writeNames($id, $data);
                $this->writeMembership($id, $data);

                return (string) $id;
            }));
        });
    }

    public function update(string $actor, string $id, int $version, SaveRateAreaData $data): void
    {
        app(AuditLogger::class)->operation('rate_area', $actor, function () use ($actor, $id, $version, $data): void {
            $this->guarded(fn () => DB::transaction(function () use ($actor, $id, $version, $data): void {
                $this->lock();
                $this->authorize($actor, 'rate-areas.manage');
                $area = $this->row($id, $version);
                $this->input->assertValid($data);
                $this->assertDefaultFree($data, (int) $id);
                $changed = $area->status !== $data->status || (bool) $area->is_default !== $data->default;
                $changed = $this->writeNames((int) $id, $data) || $changed;
                $changed = $this->writeMembership((int) $id, $data) || $changed;
                if ($changed) {
                    DB::table('rate_areas')->where('id', $id)->update(['status' => $data->status, 'is_default' => $data->default, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                }
            }));
        });
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('rate_area', $actor, function () use ($actor, $id, $version): void {
            DB::transaction(function () use ($actor, $id, $version): void {
                $this->lock();
                $this->authorize($actor, 'rate-areas.manage');
                $this->row($id, $version);
                $rates = DB::table('tax_rates')->where('rate_area_id', $id)->count();
                if ($rates > 0) {
                    throw ValidationException::withMessages(['rate_area' => __('taxes.area_in_use', ['count' => $rates])]);
                }
                // Shipping methods price or restrict delivery by area too.
                $methods = DB::table('shipping_method_rates')->where('rate_area_id', $id)->pluck('method_id')
                    ->merge(DB::table('shipping_method_areas')->where('rate_area_id', $id)->pluck('method_id'))->unique()->count();
                if ($methods > 0) {
                    throw ValidationException::withMessages(['rate_area' => __('shipping.area_in_use', ['count' => $methods])]);
                }
                DB::table('rate_areas')->where('id', $id)->delete();
            });
        });
    }

    private function row(string $id, int $version): object
    {
        $area = DB::table('rate_areas')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('taxes.stale')]);
        if ((int) $area->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('taxes.stale')]);
        }

        return $area;
    }

    /** At most one default area exists; refuse a second one with a message instead of a constraint error. */
    private function assertDefaultFree(SaveRateAreaData $data, ?int $self): void
    {
        if (! $data->default) {
            return;
        }
        $other = DB::table('rate_areas')->where('is_default', true)->when($self !== null, fn ($q) => $q->where('id', '<>', $self))->exists();
        if ($other) {
            throw ValidationException::withMessages(['is_default' => __('taxes.area_default_exists')]);
        }
    }

    private function writeNames(int $id, SaveRateAreaData $data): bool
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        $existing = DB::table('rate_area_translations')->where('rate_area_id', $id)->pluck('name', 'locale_id')->mapWithKeys(fn ($name, $locale): array => [(int) $locale => $name])->all();
        $changed = false;
        foreach ($locales as $code => $localeId) {
            $name = $data->names[$code] ?? null;
            if ($name === null) {
                if (isset($existing[$localeId])) {
                    DB::table('rate_area_translations')->where('rate_area_id', $id)->where('locale_id', $localeId)->delete();
                    $changed = true;
                }
            } elseif (($existing[$localeId] ?? null) !== $name) {
                DB::table('rate_area_translations')->updateOrInsert(['rate_area_id' => $id, 'locale_id' => $localeId], ['name' => $name]);
                $changed = true;
            }
        }

        return $changed;
    }

    /** Aligns country and region membership with the submission; returns whether anything changed. */
    private function writeMembership(int $id, SaveRateAreaData $data): bool
    {
        $changed = false;
        $countries = DB::table('rate_area_countries')->where('rate_area_id', $id)->pluck('country_code')->all();
        $regions = DB::table('rate_area_regions')->where('rate_area_id', $id)->get()->map(fn ($r): string => $r->country_code.':'.$r->region_code)->all();
        $wantedRegions = [];
        foreach ($data->regions as $country => $list) {
            foreach ($list as $region) {
                $wantedRegions[] = $country.':'.$region;
            }
        }
        // Region rows reference their country row, so removals go regions-first and additions countries-first.
        foreach (array_diff($regions, $wantedRegions) as $key) {
            [$country, $region] = explode(':', $key);
            DB::table('rate_area_regions')->where(['rate_area_id' => $id, 'country_code' => $country, 'region_code' => $region])->delete();
            $changed = true;
        }
        foreach (array_diff($countries, $data->countries) as $country) {
            DB::table('rate_area_countries')->where(['rate_area_id' => $id, 'country_code' => $country])->delete();
            $changed = true;
        }
        foreach (array_diff($data->countries, $countries) as $country) {
            DB::table('rate_area_countries')->insert(['rate_area_id' => $id, 'country_code' => $country]);
            $changed = true;
        }
        foreach (array_diff($wantedRegions, $regions) as $key) {
            [$country, $region] = explode(':', $key);
            DB::table('rate_area_regions')->insert(['rate_area_id' => $id, 'country_code' => $country, 'region_code' => $region]);
            $changed = true;
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
            // The unique area name per language (or the single default) raced the checks.
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['names' => __('taxes.area_name_taken')]);
            }
            throw $e;
        }
    }
}
