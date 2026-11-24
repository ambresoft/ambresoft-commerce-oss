<?php

namespace App\Reporting\Infrastructure;

use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Current stock of physical products (the simple single-stock model: `products.quantity`). Digital products carry no
 * stock and are only counted. Quantities are the live values, never inferred from sales; there are no stock movement
 * records and no product cost, so neither movement history nor inventory value is reported.
 *
 *   out of stock = quantity ≤ 0          low stock = 0 < quantity ≤ the general low-stock threshold
 */
final class InventoryReport
{
    public const VIEWS = ['out_of_stock', 'low', 'lowest', 'highest', 'above'];

    public function threshold(): int
    {
        return (int) app(GeneralSettings::class)->all()['low_stock_threshold'];
    }

    /** @return array{physical: int, digital: int, out_of_stock: int, low_stock: int, units: int, threshold: int, tracking: bool} */
    public function summary(): array
    {
        $threshold = $this->threshold();
        $physical = DB::table('products')->whereNull('deleted_at')->where('type', 'physical')
            ->selectRaw('count(*) AS products, count(*) FILTER (WHERE quantity <= 0) AS out_of_stock, count(*) FILTER (WHERE quantity > 0 AND quantity <= ?) AS low, coalesce(sum(quantity), 0) AS units', [$threshold])->first();

        return [
            'physical' => (int) $physical->products, 'digital' => DB::table('products')->whereNull('deleted_at')->where('type', 'digital')->count(), 'out_of_stock' => (int) $physical->out_of_stock,
            'low_stock' => (int) $physical->low, 'units' => (int) $physical->units, 'threshold' => $threshold, 'tracking' => (bool) app(GeneralSettings::class)->all()['track_inventory'],
        ];
    }

    /** One of the stock rankings of undeleted physical products; shared by the screen and the CSV export. */
    public function listing(string $view, ?int $threshold = null, ?string $status = null, ?int $categoryId = null, string $search = ''): Builder
    {
        $threshold ??= $this->threshold();
        $query = DB::table('products as p')->whereNull('p.deleted_at')->where('p.type', 'physical')->select('p.id', 'p.sku', 'p.status', 'p.quantity', 'p.category_id');
        match ($view) {
            'out_of_stock' => $query->where('p.quantity', '<=', 0)->orderBy('p.id'),
            'low' => $query->where('p.quantity', '>', 0)->where('p.quantity', '<=', $threshold)->orderBy('p.quantity')->orderBy('p.id'),
            'lowest' => $query->where('p.quantity', '>', 0)->orderBy('p.quantity')->orderBy('p.id'),
            'above' => $query->where('p.quantity', '>', $threshold)->orderByDesc('p.quantity')->orderBy('p.id'),
            default => $query->orderByDesc('p.quantity')->orderBy('p.id'),
        };
        if ($status !== null) {
            $query->where('p.status', $status);
        }
        if ($categoryId !== null) {
            $query->whereIn('p.category_id', app(ReportCategories::class)->descendants($categoryId));
        }
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('p.sku', 'ilike', $like)->orWhereExists(fn ($t) => $t->select(DB::raw(1))->from('product_translations as t')->whereColumn('t.product_id', 'p.id')->where('t.name', 'ilike', $like)));
        }

        return $query;
    }
}
