<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of a sale, shared by every report (see docs/architecture/reports.md).
 *
 * An order is **paid** when the money captured for it (`order_payments` in state `captured`, online, recorded by staff or a gift card)
 * covers its total; it became paid at the `completed_at` of the payment that first covered it. An order is **void** when
 * its current status is final and restores stock (cancelled, failed or any custom status configured the same way).
 * A **sale** is a paid, non-void order of a positive total. Sales are dated by their payment date and carry the
 * currency, amounts and line snapshots the order stored when it was placed: nothing is recalculated from current prices,
 * tax rules, discounts or exchange rates. Orders and lines are never read through current product data.
 */
final class ReportOrders
{
    /** SQL predicate on `order_statuses` aliased as `s`. */
    public const VOID = "(s.is_final AND s.stock_action = 'restore')";

    public function __construct(private ReportCategories $categories) {}

    /** Paid, non-void units; optional inclusive paid-date cutoff for storefront merchandising. */
    public function productUnitTotals(?\DateTimeInterface $since = null): Builder
    {
        $filters = new ReportFilters(ReportPeriod::preset('today', new \DateTimeImmutable), false, 'EUR');
        $sales = $this->paid($filters, null, anyCurrency: true)->select('o.id');
        if ($since !== null) {
            $sales->where('pay.paid_at', '>=', $since);
        }

        return DB::table('order_items as i')->joinSub($sales, 'paid', 'paid.id', '=', 'i.order_id')->whereNotNull('i.product_id')->groupBy('i.product_id')->selectRaw('i.product_id, sum(i.quantity) as units');
    }

    /** Live bounded product-unit aggregate using the same paid/non-void definition as every report.
     * @param  list<int|string>  $ids
     * @return array<int|string, int>
     */
    public function completedProductUnits(array $ids): array
    {
        $filters = new ReportFilters(ReportPeriod::preset('today', new \DateTimeImmutable), false, 'EUR');
        $only = DB::table('order_items')->whereIn('product_id', $ids)->select('order_id');
        $sales = $this->paid($filters, null, anyCurrency: true, only: $only)->select('o.id');

        return DB::table('order_items as i')->joinSub($sales, 'paid', 'paid.id', '=', 'i.order_id')->whereIn('i.product_id', $ids)->groupBy('i.product_id')->selectRaw('i.product_id, sum(i.quantity) as units')->get()->mapWithKeys(fn (object $r): array => [$r->product_id => (int) $r->units])->all();
    }

    /**
     * Sales (or, with `$includeVoid`, every fully paid order) of one currency, selecting nothing yet. Joined as
     * `o` (orders), `pay` (`paid_at`, `captured`) and `s` (statuses). Without a period the whole history qualifies;
     * `$only` (a query of order IDs) limits which orders are examined, so history checks for a few customers or coupons
     * do not scan every payment.
     */
    public function paid(ReportFilters $filters, ?ReportPeriod $period, bool $includeVoid = false, bool $anyCurrency = false, ?Builder $only = null): Builder
    {
        $query = DB::table('orders as o')
            ->joinSub($this->payments($period, $only), 'pay', 'pay.order_id', '=', 'o.id')
            ->join('order_statuses as s', 's.id', '=', 'o.status_id')
            ->where('o.total', '>', 0);
        if (! $anyCurrency) {
            $query->where('o.currency_code', $filters->currency);
        }
        if (! $includeVoid) {
            $query->whereRaw('NOT '.self::VOID);
        }
        if ($period !== null) {
            // The payment that covered the order may be older than the window's latest payment (a later duplicate).
            $query->where('pay.paid_at', '>=', $period->startUtc())->where('pay.paid_at', '<', $period->endUtc());
        }

        return $this->orderFilters($query, $filters);
    }

    /**
     * Orders placed in the period (any payment state or status), as `o` and `s`, for the orders report.
     */
    public function placed(ReportFilters $filters, ?ReportPeriod $period, bool $anyCurrency = false): Builder
    {
        $query = DB::table('orders as o')->join('order_statuses as s', 's.id', '=', 'o.status_id');
        if (! $anyCurrency) {
            $query->where('o.currency_code', $filters->currency);
        }
        if ($period !== null) {
            $query->where('o.created_at', '>=', $period->startUtc())->where('o.created_at', '<', $period->endUtc());
        }

        return $this->orderFilters($query, $filters);
    }

    /** Order lines of the sales in the window, as `oi` plus `q.paid_at`, narrowed by product/category when filtered. */
    public function lines(ReportFilters $filters, ?ReportPeriod $period): Builder
    {
        $sales = $this->paid($filters, $period)->select('o.id', 'pay.paid_at');
        $query = DB::table('order_items as oi')->joinSub($sales, 'q', 'q.id', '=', 'oi.order_id');
        if ($filters->productId !== null) {
            $query->where('oi.product_id', $filters->productId);
        }
        if ($filters->categoryId !== null) {
            $query->whereIn('oi.product_id', fn ($q) => $q->select('id')->from('products')->whereIn('category_id', $this->categories->descendants($filters->categoryId)));
        }

        return $query;
    }

    /** Restricts an order query to orders containing the filtered product or category (order-level reports). */
    public function containing(Builder $query, ReportFilters $filters): Builder
    {
        if ($filters->productId !== null) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('order_items as ci')->whereColumn('ci.order_id', 'o.id')->where('ci.product_id', $filters->productId));
        }
        if ($filters->categoryId !== null) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('order_items as ci')->whereColumn('ci.order_id', 'o.id')
                ->whereIn('ci.product_id', fn ($p) => $p->select('id')->from('products')->whereIn('category_id', $this->categories->descendants($filters->categoryId))));
        }

        return $query;
    }

    /**
     * For each fully paid order: when it became paid and how much was captured. The window functions find the first
     * payment whose running total covers the order, so a later duplicate payment never moves the payment date. With a
     * period only orders having a captured payment inside it are examined (a necessary condition for being paid there).
     */
    private function payments(?ReportPeriod $period, ?Builder $only = null): Builder
    {
        $bindings = [];
        $where = '';
        if ($period !== null) {
            $where .= " AND p.order_id IN (SELECT order_id FROM order_payments WHERE state = 'captured' AND completed_at >= ? AND completed_at < ?)";
            $bindings = [$period->startUtc(), $period->endUtc()];
        }
        if ($only !== null) {
            $where .= ' AND p.order_id IN ('.$only->toSql().')';
            $bindings = [...$bindings, ...$only->getBindings()];
        }
        $sql = "SELECT x.order_id, min(x.completed_at) AS paid_at, max(x.captured) AS captured FROM (
            SELECT p.order_id, p.completed_at, o2.total,
                sum(p.amount) OVER (PARTITION BY p.order_id ORDER BY p.completed_at, p.id) AS running,
                sum(p.amount) OVER (PARTITION BY p.order_id) AS captured
            FROM order_payments p JOIN orders o2 ON o2.id = p.order_id
            WHERE p.state = 'captured'$where
        ) x WHERE x.running >= x.total GROUP BY x.order_id";

        return DB::query()->fromRaw('('.$sql.') AS inner_pay', $bindings)->select('inner_pay.order_id', 'inner_pay.paid_at', 'inner_pay.captured');
    }

    private function orderFilters(Builder $query, ReportFilters $filters): Builder
    {
        if ($filters->statusId !== null) {
            $query->where('o.status_id', $filters->statusId);
        }
        if ($filters->paymentMethodId !== null) {
            $query->where('o.payment_method_id', $filters->paymentMethodId);
        }
        if ($filters->shippingMethodId !== null) {
            $query->where('o.shipping_method_id', $filters->shippingMethodId);
        }
        if ($filters->customerId !== null) {
            $query->where('o.customer_id', $filters->customerId);
        }

        return $query;
    }
}
