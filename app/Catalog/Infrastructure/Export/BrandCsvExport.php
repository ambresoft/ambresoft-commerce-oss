<?php

namespace App\Catalog\Infrastructure\Export;

use App\Catalog\Infrastructure\Import\BrandImportFields;
use App\Shared\Infrastructure\Export\CsvCell;
use App\Shared\Infrastructure\Export\ExportSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BrandCsvExport implements ExportSource
{
    /** Image/attachment columns only carry download URLs or removal flags on import. */
    private const IMPORT_ONLY = ['banner_image_url', 'image_url', 'attachment_url', 'attachment_name', 'remove_image', 'remove_banner_image', 'remove_attachments'];

    public function ability(): string
    {
        return 'brands.view';
    }

    public function title(): string
    {
        return 'brands.title';
    }

    public function indexRoute(): string
    {
        return 'admin.brands.index';
    }

    public function columns(): array
    {
        return array_values(array_diff(app(BrandImportFields::class)->columns(), self::IMPORT_ONLY));
    }

    public function filterRules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])]];
    }

    public function rows(array $columns, array $filters, array $ids): iterable
    {
        $query = DB::table('brands as b')->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('b.status', $filters['status']))
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters): void {
                $like = '%'.addcslashes($filters['search'], '\\%_').'%';
                $q->where(fn ($q) => $q->where('b.code', 'ilike', $like)->orWhereExists(fn ($e) => $e->selectRaw('1')->from('brand_translations as s')->whereColumn('s.brand_id', 'b.id')->where('s.name', 'ilike', $like)));
            })
            ->when($ids !== [], fn ($q) => $q->whereIn('b.id', $ids));
        foreach ($query->orderBy('b.position')->orderBy('b.id')->pluck('b.id')->chunk(500) as $chunk) {
            $brands = DB::table('brands')->whereIn('id', $chunk)->get()->keyBy('id');
            $texts = [];
            foreach (DB::table('brand_translations as t')->join('locales as l', 'l.id', 't.locale_id')->whereIn('t.brand_id', $chunk)->get(['t.*', 'l.code']) as $text) {
                $texts[$text->brand_id][$text->code] = $text;
            }
            foreach ($chunk as $id) {
                yield array_map(function (string $column) use ($brands, $texts, $id): string {
                    if (str_contains($column, '.')) {
                        [$field, $code] = explode('.', $column, 2);

                        return CsvCell::text($texts[$id][$code]->$field ?? null);
                    }

                    return CsvCell::text($brands[$id]->$column ?? null);
                }, $columns);
            }
        }
    }
}
