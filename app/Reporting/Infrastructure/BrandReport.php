<?php

namespace App\Reporting\Infrastructure;

use App\Catalog\Infrastructure\BrandQueries;
use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Brand performance from the brand each sales line was **sold under** (`order_items.brand_id`, kept with the order), so
 * moving a product to another brand never rewrites history. A line's own merchandise value goes to its brand; an order
 * with lines of several brands counts once in each. Lines without a brand (unbranded products, or orders older than
 * brand tracking whose product is gone) are reported apart; a deleted brand keeps the name it was sold under.
 */
final class BrandReport
{
    public const SORTS = ['net', 'gross', 'units', 'orders', 'products', 'name', 'share', 'change'];

    public function __construct(private ReportOrders $orders) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, string|int>, unbranded: array<string, string|int>}
     */
    public function compute(ReportFilters $f, ReportPeriod $period, bool $compare = false): array
    {
        [$byBrand, $unbranded, $total] = $this->figures($f, $period);
        $previous = $compare ? $this->figures($f->forPeriod($period->previous()), $period->previous())[0] : [];
        $ids = array_values(array_filter(array_keys($byBrand), fn (int|string $id): bool => is_int($id)));
        $current = $ids === [] ? collect() : app(BrandQueries::class)->named()->whereIn('b.id', $ids)->get()->keyBy('id');
        $rows = [];
        foreach ($byBrand as $id => $figure) {
            $brand = $current[$id] ?? null;
            $before = $previous[$id]['net'] ?? null;
            $rows[] = [
                'id' => (string) $id, 'name' => trim((string) ($brand->name ?? '')) !== '' ? $brand->name : ($figure['snapshot_name'] ?: '#'.$id), 'exists' => $brand !== null, 'code' => $brand->code ?? null, ...$figure,
                'share' => Decimal::compare($total['net'], '0') > 0 ? Decimal::trim(bcdiv(bcmul($figure['net'], '100', 6), $total['net'], 2)) : '0',
                'previous_net' => $compare ? ($before ?? '0') : null,
                // Growth against the previous period; a brand that sold nothing before has no meaningful percentage.
                'change' => $compare && $before !== null && Decimal::compare($before, '0') > 0 ? Decimal::trim(bcdiv(bcmul(Decimal::subtract($figure['net'], $before), '100', 6), $before, 1)) : null,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'unbranded' => $unbranded];
    }

    /** @return array{0: array<int|string, array<string, mixed>>, 1: array<string, string|int>, 2: array<string, string|int>} */
    private function figures(ReportFilters $f, ReportPeriod $period): array
    {
        $lines = $this->orders->lines($f, $period)->select('oi.brand_id', 'oi.brand_name', 'oi.product_id', 'oi.order_id', 'oi.quantity', 'oi.subtotal', 'oi.discount_total');
        $sql = 'WITH l AS ('.$lines->toSql().') SELECT %s, sum(quantity) AS units, count(distinct order_id) AS orders, count(distinct product_id) AS products, coalesce(sum(subtotal),0) AS gross, coalesce(sum(discount_total),0) AS discounts FROM l %s';
        $figure = fn (object $r): array => ['units' => (int) $r->units, 'orders' => (int) $r->orders, 'products' => (int) $r->products, 'gross' => Decimal::trim((string) $r->gross), 'discounts' => Decimal::trim((string) $r->discounts), 'net' => Decimal::trim(Decimal::subtract((string) $r->gross, (string) $r->discounts))];
        $byBrand = [];
        $unbranded = ['units' => 0, 'orders' => 0, 'products' => 0, 'gross' => '0', 'discounts' => '0', 'net' => '0'];
        // A deleted brand's lines lose the id but keep the name, so they still rank under that name.
        $groups = sprintf($sql, 'brand_id AS id, CASE WHEN brand_id IS NULL THEN brand_name END AS gone, max(brand_name) AS snapshot_name', 'GROUP BY brand_id, CASE WHEN brand_id IS NULL THEN brand_name END');
        foreach (DB::select($groups, $lines->getBindings()) as $r) {
            if ($r->id !== null) {
                $byBrand[(int) $r->id] = [...$figure($r), 'snapshot_name' => (string) ($r->snapshot_name ?? '')];
            } elseif ($r->gone !== null) {
                $byBrand['deleted:'.$r->gone] = [...$figure($r), 'snapshot_name' => (string) $r->gone];
            } else {
                $unbranded = $figure($r);
            }
        }
        $total = $figure(DB::selectOne(sprintf($sql, '1 AS one', ''), $lines->getBindings()));

        return [$byBrand, $unbranded, $total];
    }
}
