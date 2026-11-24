<?php

namespace App\Catalog\Infrastructure\Export;

use App\Catalog\Infrastructure\Import\CategoryImportFields;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Export\CsvCell;
use App\Shared\Infrastructure\Export\ExportSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CategoryCsvExport implements ExportSource
{
    public function ability(): string
    {
        return 'categories.view';
    }

    public function title(): string
    {
        return 'categories.title';
    }

    public function indexRoute(): string
    {
        return 'admin.categories.index';
    }

    public function columns(): array
    {
        return array_values(array_diff(app(CategoryImportFields::class)->columns(), ['image_url', 'remove_image']));
    }

    public function filterRules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])]];
    }

    public function rows(array $columns, array $filters, array $ids): iterable
    {
        $query = DB::table('categories as c')->whereNull('c.system_key')
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('c.status', $filters['status']))
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters): void {
                $like = '%'.addcslashes($filters['search'], '\\%_').'%';
                $q->whereExists(fn ($e) => $e->selectRaw('1')->from('category_translations as s')->whereColumn('s.category_id', 'c.id')->where('s.name', 'ilike', $like));
            })
            ->when($ids !== [], fn ($q) => $q->whereIn('c.id', $ids));
        $paths = in_array('category_path', $columns, true) ? $this->paths() : [];
        foreach ($query->orderBy('c.parent_id')->orderBy('c.position')->orderBy('c.id')->pluck('c.id')->chunk(500) as $chunk) {
            $categories = DB::table('categories')->whereIn('id', $chunk)->get()->keyBy('id');
            $texts = [];
            foreach (DB::table('category_translations as t')->join('locales as l', 'l.id', 't.locale_id')->whereIn('t.category_id', $chunk)->get(['t.*', 'l.code']) as $text) {
                $texts[$text->category_id][$text->code] = $text;
            }
            $groups = [];
            if (in_array('customer_groups', $columns, true)) {
                foreach (DB::table('category_customer_groups')->whereIn('category_id', $chunk)->orderBy('group_id')->get() as $group) {
                    $groups[$group->category_id][] = $group->group_id;
                }
            }
            foreach ($chunk as $id) {
                yield array_map(fn (string $column): string => CsvCell::text(match (true) {
                    $column === 'category_id' => $id,
                    $column === 'category_path' => $paths[$id] ?? '',
                    $column === 'customer_groups' => implode('|', $groups[$id] ?? []),
                    str_contains($column, '.') => $texts[$id][explode('.', $column, 2)[1]]->{explode('.', $column, 2)[0]} ?? null,
                    default => $categories[$id]->$column,
                }), $columns);
            }
        }
    }

    /**
     * Default-language ancestor names joined with the import path separator.
     *
     * @return array<int, string>
     */
    private function paths(): array
    {
        $default = app(LocaleCatalog::class)->defaultCode();
        $names = DB::table('category_translations as t')->join('locales as l', 'l.id', 't.locale_id')->orderByRaw('l.code = ? desc', [$default])->get(['t.category_id', 't.name'])->unique('category_id')->pluck('name', 'category_id');
        $parents = DB::table('categories')->pluck('parent_id', 'id');
        $paths = [];
        foreach ($parents as $id => $parent) {
            $parts = [$names[$id] ?? ''];
            for ($depth = 0; $parent !== null && $depth < 50; $depth++) {
                array_unshift($parts, $names[$parent] ?? '');
                $parent = $parents[$parent] ?? null;
            }
            $paths[$id] = implode((string) config('category-import.path_separator'), $parts);
        }

        return $paths;
    }
}
