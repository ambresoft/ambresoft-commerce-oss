<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Category performance from sales lines and the **current** product-to-category assignment (a product has one category;
 * orders keep no category snapshot, so moving a product moves its past sales: the best available classification).
 * Each line's own merchandise value is attributed to its product's category, never the whole order total; an order with
 * lines in several categories counts once in each. `direct` mode credits only products assigned to the category;
 * `tree` mode also includes all its descendants, so a parent includes its children. The grand total always comes from
 * the direct figures (every line belongs to exactly one category), so it never double counts. Lines of permanently
 * deleted products have no category and appear as unclassified.
 */
final class CategoryReport
{
    public const SORTS = ['net', 'gross', 'units', 'orders', 'name'];

    public function __construct(private ReportOrders $orders, private ReportCategories $categories) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, string|int>, unclassified: array<string, string|int>}
     */
    public function compute(ReportFilters $f, ReportPeriod $period, string $mode): array
    {
        $lines = $this->orders->lines($f, $period)->leftJoin('products as p', 'p.id', '=', 'oi.product_id')->select('p.category_id', 'oi.order_id', 'oi.quantity', 'oi.subtotal', 'oi.discount_total');
        $direct = DB::select('WITH l AS ('.$lines->toSql().') SELECT category_id AS id, sum(quantity) AS units, count(distinct order_id) AS orders, sum(subtotal) AS gross, sum(discount_total) AS discounts FROM l GROUP BY category_id', $lines->getBindings());
        $totals = DB::selectOne('WITH l AS ('.$lines->toSql().') SELECT coalesce(sum(quantity),0) AS units, count(distinct order_id) AS orders, coalesce(sum(subtotal),0) AS gross, coalesce(sum(discount_total),0) AS discounts FROM l', $lines->getBindings());
        $figures = fn (object $r): array => ['units' => (int) $r->units, 'orders' => (int) $r->orders, 'gross' => Decimal::trim($r->gross), 'discounts' => Decimal::trim($r->discounts), 'net' => Decimal::trim(Decimal::subtract($r->gross, $r->discounts))];
        $byCategory = [];
        $unclassified = ['units' => 0, 'orders' => 0, 'gross' => '0', 'discounts' => '0', 'net' => '0'];
        foreach ($direct as $r) {
            if ($r->id === null) {
                $unclassified = $figures($r);
            } else {
                $byCategory[(int) $r->id] = $figures($r);
            }
        }
        if ($mode === 'tree') {
            $tree = DB::select('WITH RECURSIVE anc(id, ancestor_id) AS (SELECT id, id FROM categories UNION ALL SELECT a.id, c.parent_id FROM anc a JOIN categories c ON c.id = a.ancestor_id WHERE c.parent_id IS NOT NULL), l AS ('.$lines->toSql().') SELECT a.ancestor_id AS id, sum(l.quantity) AS units, count(distinct l.order_id) AS orders, sum(l.subtotal) AS gross, sum(l.discount_total) AS discounts FROM l JOIN anc a ON a.id = l.category_id GROUP BY a.ancestor_id', $lines->getBindings());
            $byCategory = [];
            foreach ($tree as $r) {
                $byCategory[(int) $r->id] = $figures($r);
            }
        }
        $all = $this->categories->all();
        $rows = [];
        foreach ($byCategory as $id => $figure) {
            $rows[] = ['id' => (string) $id, 'name' => $all[$id]['name'] ?? '#'.$id, 'path' => $this->categories->path($id), 'exists' => isset($all[$id]), ...$figure];
        }

        return ['rows' => $rows, 'total' => $figures($totals), 'unclassified' => $unclassified];
    }
}
