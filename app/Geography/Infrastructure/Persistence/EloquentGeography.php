<?php

namespace App\Geography\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Geography\Application\Contract\GeographyAdministration;
use App\Geography\Application\Contract\ShippingGeography;
use App\Geography\Infrastructure\RegionInput;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Geography\Countries;
use Collator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class EloquentGeography implements GeographyAdministration, ShippingGeography
{
    public function countries(): array
    {
        return DB::table('shipping_countries')->orderBy('country_code')->pluck('country_code')->all();
    }

    public function ships(string $countryCode): bool
    {
        return DB::table('shipping_countries')->where('country_code', $countryCode)->exists();
    }

    public function regions(string $countryCode, ?string $locale = null): array
    {
        return $this->regionOptions($locale)[$countryCode] ?? [];
    }

    public function regionOptions(?string $locale = null): array
    {
        $rows = DB::table('regions as r')->join('shipping_countries as s', 's.country_code', '=', 'r.country_code')
            ->where('r.status', 'active')->select(['r.id', 'r.country_code', 'r.code']);
        $options = [];
        foreach ($this->named($rows, $locale) as $row) {
            $options[$row['country_code']][] = ['code' => $row['code'], 'name' => $row['name']];
        }
        $collator = new Collator($locale ?? app()->getLocale());
        foreach ($options as &$list) {
            usort($list, fn (array $a, array $b): int => $collator->compare($a['name'], $b['name']) ?: strcmp($a['code'], $b['code']));
        }

        return $options;
    }

    public function regionNames(array $pairs, ?string $locale = null): array
    {
        $pairs = array_values(array_filter($pairs, fn (array $p): bool => $p[0] !== '' && $p[1] !== ''));
        if ($pairs === []) {
            return [];
        }
        $rows = DB::table('regions as r')->select(['r.id', 'r.country_code', 'r.code'])->where(function ($q) use ($pairs): void {
            foreach ($pairs as [$country, $code]) {
                $q->orWhere(fn ($pair) => $pair->where('r.country_code', $country)->where('r.code', $code));
            }
        });
        $names = [];
        foreach ($this->named($rows, $locale) as $row) {
            $names[$row['country_code'].':'.$row['code']] = $row['name'];
        }

        return $names;
    }

    /**
     * Each region with its name in the requested language, else the default language, else any saved name.
     *
     * @return list<array{country_code: string, code: string, name: string}>
     */
    private function named(Builder $regions, ?string $locale): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $current = $locales->firstWhere('code', $locale ?? app()->getLocale())?->id;
        $default = $locales->firstWhere('is_default', true)?->id;
        $named = [];
        foreach ($regions->join('region_translations as t', 't.region_id', '=', 'r.id')->addSelect(['t.locale_id', 't.name'])->get() as $row) {
            $rank = $row->locale_id === $current ? 0 : ($row->locale_id === $default ? 1 : 2);
            if (! isset($named[$row->id]) || $rank < $named[$row->id]['rank']) {
                $named[$row->id] = ['rank' => $rank, 'country_code' => $row->country_code, 'code' => $row->code, 'name' => $row->name];
            }
        }

        return array_values(array_map(fn (array $r): array => ['country_code' => $r['country_code'], 'code' => $r['code'], 'name' => $r['name']], $named));
    }

    public function setShipping(string $actor, string $countryCode, bool $ships): void
    {
        app(AuditLogger::class)->operation('shipping_country', $actor, fn () => DB::transaction(function () use ($actor, $countryCode, $ships): void {
            $this->authorize($actor, 'countries.manage');
            $this->assertCountry($countryCode);
            if ($ships) {
                DB::table('shipping_countries')->insertOrIgnore(['country_code' => $countryCode, 'created_by' => $actor, 'created_at' => now()]);
            } else {
                DB::table('shipping_countries')->where('country_code', $countryCode)->delete();
            }
        }));
    }

    public function createRegion(string $actor, string $countryCode, string $code, array $names, string $status): string
    {
        return app(AuditLogger::class)->operation('region', $actor, fn (): string => $this->guarded(fn (): string => DB::transaction(function () use ($actor, $countryCode, $code, $names, $status): string {
            $this->authorize($actor, 'regions.manage');
            $this->assertCountry($countryCode);
            if (! $this->ships($countryCode)) {
                throw ValidationException::withMessages(['country' => __('geography.country_not_shipping')]);
            }
            [$code, $names] = app(RegionInput::class)->validate($code, $names, $status);
            $id = DB::table('regions')->insertGetId(['country_code' => $countryCode, 'code' => $code, 'status' => $status, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->writeNames($id, $names);

            return (string) $id;
        })));
    }

    public function updateRegion(string $actor, string $id, int $version, string $code, array $names, string $status): void
    {
        app(AuditLogger::class)->operation('region', $actor, fn () => $this->guarded(fn () => DB::transaction(function () use ($actor, $id, $version, $code, $names, $status): void {
            $this->authorize($actor, 'regions.manage');
            $region = $this->row($id, $version);
            [$code, $names] = app(RegionInput::class)->validate($code, $names, $status);
            $changed = $this->writeNames((int) $id, $names);
            if ($region->code !== $code || $region->status !== $status || $changed) {
                DB::table('regions')->where('id', $id)->update(['code' => $code, 'status' => $status, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => $version + 1]);
            }
        })));
    }

    public function deleteRegion(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('region', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor, 'regions.manage');
            $this->row($id, $version);
            DB::table('regions')->where('id', $id)->delete();
        }));
    }

    public function setRegionStatus(string $actor, array $ids, string $status): int
    {
        if (! in_array($status, ['active', 'disabled'], true)) {
            throw ValidationException::withMessages(['status' => __('geography.status_invalid')]);
        }

        return app(AuditLogger::class)->operation('region', $actor, fn (): int => DB::transaction(function () use ($actor, $ids, $status): int {
            $this->authorize($actor, 'regions.manage');

            return DB::table('regions')->whereIn('id', $ids)->where('status', '<>', $status)
                ->update(['status' => $status, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
        }));
    }

    private function assertCountry(string $code): void
    {
        if (! app(Countries::class)->valid($code)) {
            throw ValidationException::withMessages(['country' => __('geography.country_invalid')]);
        }
    }

    /** @param array<string, string> $names */
    private function writeNames(int $id, array $names): bool
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        $existing = DB::table('region_translations')->where('region_id', $id)->pluck('name', 'locale_id')->mapWithKeys(fn ($name, $locale): array => [(int) $locale => $name])->all();
        $changed = false;
        foreach ($locales as $code => $localeId) {
            $name = $names[$code] ?? null;
            if ($name === null) {
                if (isset($existing[$localeId])) {
                    DB::table('region_translations')->where('region_id', $id)->where('locale_id', $localeId)->delete();
                    $changed = true;
                }
            } elseif (($existing[$localeId] ?? null) !== $name) {
                DB::table('region_translations')->updateOrInsert(['region_id' => $id, 'locale_id' => $localeId], ['name' => $name]);
                $changed = true;
            }
        }

        return $changed;
    }

    private function row(string $id, int $version): object
    {
        $region = DB::table('regions')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('geography.stale')]);
        if ((int) $region->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('geography.stale')]);
        }

        return $region;
    }

    private function authorize(string $actor, string $ability): void
    {
        // Use the existing catalog/language configuration lock before any geography reads or writes.
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
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
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => __('geography.code_taken')]);
            }
            throw $e;
        }
    }
}
