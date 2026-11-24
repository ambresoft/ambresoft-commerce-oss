<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product performance from order-line snapshots of sales (see ReportOrders): names, SKUs, quantities and amounts are
 * those the order stored, never the product's current data. Products are identified by their ID while it exists; a
 * permanently deleted product is grouped by its snapshot name and SKU. Units, discounts and amounts are merchandise
 * only (no shipping or tax); `net = gross − discounts`. Refunded quantities are not recorded and cannot be excluded.
 */
final class ProductReport
{
    public const SORTS = ['net', 'gross', 'units', 'orders', 'name'];

    public function __construct(private ReportOrders $orders) {}

    /** Per-product sales of the window; the screen paginates it and the CSV export streams it. */
    public function ranking(ReportFilters $f, ReportPeriod $period, string $search = '', string $sort = 'net', string $direction = 'desc'): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $order = ['net' => 'sum(oi.subtotal - oi.discount_total)', 'gross' => 'sum(oi.subtotal)', 'units' => 'sum(oi.quantity)', 'orders' => 'count(distinct oi.order_id)', 'name' => '(array_agg(oi.name ORDER BY q.paid_at DESC, oi.id DESC))[1]'][$sort] ?? 'sum(oi.subtotal - oi.discount_total)';
        $query = $this->orders->lines($f, $period)
            ->groupByRaw("oi.product_id, CASE WHEN oi.product_id IS NULL THEN oi.name END, CASE WHEN oi.product_id IS NULL THEN coalesce(oi.sku, '') END")
            ->selectRaw('oi.product_id AS product_id, (array_agg(oi.name ORDER BY q.paid_at DESC, oi.id DESC))[1] AS name, (array_agg(oi.sku ORDER BY q.paid_at DESC, oi.id DESC))[1] AS sku, sum(oi.quantity) AS units, count(distinct oi.order_id) AS orders, sum(oi.subtotal) AS gross, sum(oi.discount_total) AS discounts, sum(oi.subtotal - oi.discount_total) AS net')
            ->orderByRaw($order.' '.$direction)->orderByRaw('oi.product_id '.$direction.' NULLS LAST');
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('oi.name', 'ilike', $like)->orWhere('oi.sku', 'ilike', $like));
        }

        return $query;
    }

    /**
     * Products whose units fell against the previous equivalent window (they sold there). Rows carry both quantities.
     */
    public function declining(ReportFilters $f, ReportPeriod $period): Builder
    {
        $current = $this->units($f, $period);
        $previous = $this->units($f, $period->previous());

        return DB::table('products as p')->joinSub($previous, 'prev', 'prev.product_id', '=', 'p.id')->leftJoinSub($current, 'cur', 'cur.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')->whereRaw('prev.units > coalesce(cur.units, 0)')
            ->orderByRaw('(prev.units - coalesce(cur.units, 0)) DESC')->orderBy('p.id')
            ->selectRaw('p.id AS product_id, p.sku, p.status, coalesce(cur.units, 0) AS units, prev.units AS previous_units');
    }

    /** @return Builder Units sold per existing product in a window, ignoring any product/category filter. */
    private function units(ReportFilters $f, ReportPeriod $period): Builder
    {
        return $this->orders->lines($this->scoped($f), $period)->whereNotNull('oi.product_id')->groupBy('oi.product_id')->selectRaw('oi.product_id, sum(oi.quantity) AS units');
    }

    /** Active, undeleted products that never sold at all (`$period` null) or did not sell in the window. */
    public function unsold(ReportFilters $f, ?ReportPeriod $period): Builder
    {
        $sold = $this->orders->lines($this->scoped($f), $period)->whereNotNull('oi.product_id')->select('oi.product_id');
        $query = DB::table('products as p')->whereNull('p.deleted_at')->where('p.status', 'active')->whereNotIn('p.id', $sold)->orderBy('p.id', 'desc')->select('p.id AS product_id', 'p.sku', 'p.status', 'p.type', 'p.created_at');
        if ($f->productId !== null) {
            $query->where('p.id', $f->productId);
        }
        if ($f->categoryId !== null) {
            $query->whereIn('p.category_id', app(ReportCategories::class)->descendants($f->categoryId));
        }

        return $query;
    }

    /** Filters that decide which orders count, without the product/category narrowing of the listing itself. */
    private function scoped(ReportFilters $f): ReportFilters
    {
        return new ReportFilters($f->period, $f->compare, $f->currency, $f->dateBasis, $f->statusId, $f->paymentMethodId, $f->shippingMethodId, null, null, $f->customerId);
    }
}
