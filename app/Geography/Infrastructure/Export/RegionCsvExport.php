<?php

namespace App\Geography\Infrastructure\Export;

use App\Geography\Infrastructure\Import\RegionImportFields;
use App\Shared\Infrastructure\Export\CsvCell;
use App\Shared\Infrastructure\Export\ExportSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class RegionCsvExport implements ExportSource
{
    public function ability(): string
    {
        return 'regions.view';
    }

    public function title(): string
    {
        return 'geography.regions_title';
    }

    public function indexRoute(): string
    {
        return 'admin.regions.index';
    }

    public function columns(): array
    {
        return app(RegionImportFields::class)->columns();
    }

    public function filterRules(): array
    {
        return ['country' => ['nullable', 'string', 'size:2'], 'search' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', Rule::in(['active', 'disabled'])]];
    }

    public function rows(array $columns, array $filters, array $ids): iterable
    {
        $query = DB::table('regions as r')->when(($filters['country'] ?? '') !== '', fn ($q) => $q->where('r.country_code', strtoupper($filters['country'])))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('r.status', $filters['status']))
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters): void {
                $like = '%'.addcslashes($filters['search'], '\\%_').'%';
                $q->where(fn ($q) => $q->where('r.code', 'ilike', $like)->orWhereExists(fn ($e) => $e->selectRaw('1')->from('region_translations as s')->whereColumn('s.region_id', 'r.id')->where('s.name', 'ilike', $like)));
            })
            ->when($ids !== [], fn ($q) => $q->whereIn('r.id', $ids));
        foreach ($query->orderBy('r.country_code')->orderBy('r.code')->pluck('r.id')->chunk(500) as $chunk) {
            $regions = DB::table('regions')->whereIn('id', $chunk)->get()->keyBy('id');
            $names = [];
            foreach (DB::table('region_translations as t')->join('locales as l', 'l.id', 't.locale_id')->whereIn('t.region_id', $chunk)->get(['t.region_id', 't.name', 'l.code']) as $name) {
                $names[$name->region_id][$name->code] = $name->name;
            }
            foreach ($chunk as $id) {
                yield array_map(fn (string $column): string => CsvCell::text(match (true) {
                    $column === 'region_id' => $id,
                    str_starts_with($column, 'name.') => $names[$id][substr($column, 5)] ?? null,
                    default => $regions[$id]->$column,
                }), $columns);
            }
        }
    }
}
