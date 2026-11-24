<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer performance from sales (ReportOrders). A registered customer is identified only by the customer the order
 * is linked to; guest orders (no linked customer, including orders of a customer deleted afterwards) are grouped by
 * their e-mail and **never merged** with a registered customer, because the CMS has no identity link between them.
 * Spending is merchandise net of discounts (`net`) and the total charged; refunds are not recorded.
 *
 *   repeat customer    = at least two sales in the window
 *   returning customer = a registered customer with a sale in the window and an earlier sale before it
 *   new customer       = a customer profile created in the window (whether or not they bought)
 */
final class CustomerReport
{
    public const SORTS = ['spend', 'total', 'orders', 'last', 'name'];

    public function __construct(private ReportOrders $orders) {}

    /** Per-customer spending in the window (registered customers and guests as separate rows). */
    public function ranking(ReportFilters $f, ReportPeriod $period, string $sort = 'spend', string $direction = 'desc'): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $order = ['spend' => 'sum(o.subtotal - o.discount_total)', 'total' => 'sum(o.total)', 'orders' => 'count(*)', 'last' => 'max(pay.paid_at)', 'name' => 'lower(coalesce(max(c.last_name), (array_agg(o.last_name ORDER BY pay.paid_at DESC))[1]))'][$sort] ?? 'sum(o.subtotal - o.discount_total)';

        return $this->orders->paid($f, $period)->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->groupByRaw('o.customer_id, CASE WHEN o.customer_id IS NULL THEN o.email END')
            ->selectRaw('o.customer_id AS customer_id, coalesce(max(c.email), max(o.email)) AS email, coalesce(max(c.first_name), (array_agg(o.first_name ORDER BY pay.paid_at DESC))[1]) AS first_name, coalesce(max(c.last_name), (array_agg(o.last_name ORDER BY pay.paid_at DESC))[1]) AS last_name, count(*) AS orders, sum(o.subtotal - o.discount_total) AS net, sum(o.total) AS total, max(pay.paid_at) AS last_order_at, max(o.currency_code) AS currency')
            ->orderByRaw($order.' '.$direction)->orderByRaw('o.customer_id '.$direction.' NULLS LAST')->orderByRaw('max(o.email) asc');
    }

    /** @return array<string, int> */
    public function summary(ReportFilters $f, ReportPeriod $period): array
    {
        $grouped = fn () => $this->orders->paid($f, $period)->groupByRaw('o.customer_id, CASE WHEN o.customer_id IS NULL THEN o.email END')->selectRaw('o.customer_id, count(*) AS orders');
        $buyers = DB::query()->fromSub($grouped(), 'g');
        $inPeriod = $this->orders->paid($f, $period)->whereNotNull('o.customer_id')->select('o.customer_id');
        // Earlier sales are examined only for the customers who bought in the window.
        $earlier = $this->orders->paid($f, null, only: DB::table('orders')->whereIn('customer_id', (clone $inPeriod))->select('id'))->whereNotNull('o.customer_id')->where('pay.paid_at', '<', $period->startUtc())->select('o.customer_id');

        return [
            'new_customers' => DB::table('customers')->where('created_at', '>=', $period->startUtc())->where('created_at', '<', $period->endUtc())->count(),
            'registered_buyers' => (clone $buyers)->whereNotNull('customer_id')->count(),
            'guest_buyers' => (clone $buyers)->whereNull('customer_id')->count(),
            'repeat_customers' => (clone $buyers)->where('orders', '>=', 2)->count(),
            'returning_customers' => DB::table('customers')->whereIn('id', $inPeriod)->whereIn('id', $earlier)->count(),
            'without_orders' => $this->withoutOrders()->count(),
        ];
    }

    /** Registered customers who have never placed an order. */
    public function withoutOrders(): Builder
    {
        return DB::table('customers as c')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('orders as o')->whereColumn('o.customer_id', 'c.id'))->orderByDesc('c.created_at')->orderByDesc('c.id')
            ->select('c.id AS customer_id', 'c.email', 'c.first_name', 'c.last_name', 'c.created_at', 'c.status');
    }
}
