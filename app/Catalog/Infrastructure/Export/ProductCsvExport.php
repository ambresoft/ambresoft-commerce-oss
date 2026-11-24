<?php

namespace App\Catalog\Infrastructure\Export;

use App\Catalog\Infrastructure\Import\ProductImportFields;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Export\CsvCell;
use App\Shared\Infrastructure\Export\ExportSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Exports the import columns, so a file can be edited and re-imported; upload-only columns are left out. */
final class ProductCsvExport implements ExportSource
{
    public function ability(): string
    {
        return 'products.view';
    }

    public function title(): string
    {
        return 'products.title';
    }

    public function indexRoute(): string
    {
        return 'admin.products.index';
    }

    public function columns(): array
    {
        return array_values(array_diff(app(ProductImportFields::class)->columns(), ['image_url', 'remove_image']));
    }

    public function filterRules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled', 'hidden'])], 'type' => ['nullable', Rule::in(['physical', 'digital'])], 'category_id' => ['nullable', 'integer', 'min:1'], 'brand_id' => ['nullable', 'integer', 'min:1'], 'incomplete' => ['nullable', Rule::in(['0', '1'])], 'variation' => ['nullable', Rule::in(['grouped', 'standalone'])], 'stock_action' => ['nullable', Rule::in(['general', 'custom'])]];
    }

    public function rows(array $columns, array $filters, array $ids): iterable
    {
        $queries = app(ProductQueries::class);
        $query = ProductRecord::query();
        $queries->applyFilters($query, $filters, $queries->defaultId());
        if ($ids !== []) {
            $query->whereIn('products.id', $ids);
        }
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code')->all();
        foreach ($query->orderBy('products.id')->pluck('products.id')->chunk(500) as $chunk) {
            $products = ProductRecord::with('translations')->whereIn('id', $chunk)->get()->keyBy('id');
            $taxes = in_array('tax_ids', $columns, true) ? $this->joined('product_taxes', 'tax_id', $chunk->all()) : [];
            $groups = in_array('customer_groups', $columns, true) ? $this->joined('product_customer_groups', 'group_id', $chunk->all()) : [];
            foreach ($chunk as $id) {
                $product = $products[$id];
                $row = [];
                foreach ($columns as $column) {
                    $row[] = CsvCell::text(match (true) {
                        $column === 'tax_ids' => $taxes[$id] ?? '',
                        $column === 'customer_groups' => $groups[$id] ?? '',
                        str_contains($column, '.') => $this->translated($product, $column, $locales),
                        default => $this->scalar($product, $column),
                    });
                }
                yield $row;
            }
        }
    }

    /** @param array<string, int> $locales */
    private function translated(ProductRecord $product, string $column, array $locales): ?string
    {
        [$field, $code] = explode('.', $column, 2);

        return $product->translations->firstWhere('locale_id', $locales[$code] ?? 0)?->getAttribute($field);
    }

    private function scalar(ProductRecord $product, string $column): mixed
    {
        $value = $product->getAttribute($column);

        return is_string($value) && str_starts_with($product->getCasts()[$column] ?? '', 'decimal') ? Decimal::trim($value) : $value;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, string>
     */
    private function joined(string $table, string $column, array $ids): array
    {
        $values = [];
        foreach (DB::table($table)->whereIn('product_id', $ids)->orderBy($column)->get(['product_id', $column]) as $row) {
            $values[$row->product_id][] = $row->$column;
        }

        return array_map(fn (array $list): string => implode('|', $list), $values);
    }
}
