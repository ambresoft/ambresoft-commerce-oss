<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Shopping-pattern figures over the shared sale definition (ReportOrders): when sales are paid (store time zone) and how
 * much comes from returning buyers. Order level, so they ignore product/category narrowing like the order-level totals.
 */
final class InsightsReport
{
    public function __construct(private ReportOrders $orders) {}

    /**
     * Paid orders and net merchandise per ISO weekday (1 = Monday) and per hour of the day, zero-filled.
     *
     * @return array{weekdays: list<array{key: int, orders: int, net: string}>, hours: list<array{key: int, orders: int, net: string}>}
     */
    public function timing(ReportFilters $f, ReportPeriod $period): array
    {
        $zone = $period->start->getTimezone()->getName();
        $result = [];
        foreach (['weekdays' => ['isodow', range(1, 7)], 'hours' => ['hour', range(0, 23)]] as $key => [$part, $keys]) {
            $found = $this->orders->paid($f, $period)->selectRaw("extract($part from pay.paid_at AT TIME ZONE ?)::int AS k, count(*) AS orders, coalesce(sum(o.subtotal - o.discount_total),0) AS net", [$zone])
                ->groupByRaw('1')->get()->keyBy('k');
            $result[$key] = array_map(fn (int $k): array => ['key' => $k, 'orders' => (int) ($found[$k]->orders ?? 0), 'net' => Decimal::trim((string) ($found[$k]->net ?? '0'))], $keys);
        }

        return $result;
    }

    /**
     * Paid orders of the period from buyers who had already paid for an earlier order, with their share of the total
     * charged. A buyer is the customer account, or the e-mail address for guests.
     *
     * @return array{orders: int, returning_orders: int, total: string, returning_total: string, rate: ?string}
     */
    public function returning(ReportFilters $f, ReportPeriod $period): array
    {
        $buyer = "coalesce('c' || o.customer_id::text, 'e' || lower(o.email))";
        $current = $this->orders->paid($f, $period)->selectRaw("$buyer AS buyer, pay.paid_at, o.total");
        // Only the earlier orders of this period's buyers are examined, in any currency and whatever the other filters.
        $mine = $this->orders->paid($f, $period);
        $only = DB::table('orders as o')->where(fn ($q) => $q->whereIn('o.customer_id', (clone $mine)->whereNotNull('o.customer_id')->select('o.customer_id'))
            ->orWhereIn(DB::raw('lower(o.email)'), (clone $mine)->whereNull('o.customer_id')->selectRaw('lower(o.email)')))->select('o.id');
        $history = $this->orders->paid(new ReportFilters($period, false, $f->currency), null, anyCurrency: true, only: $only)->selectRaw("$buyer AS buyer, pay.paid_at");
        $earlier = 'EXISTS (SELECT 1 FROM h WHERE h.buyer = c.buyer AND h.paid_at < c.paid_at)';
        $row = DB::selectOne('WITH c AS ('.$current->toSql().'), h AS ('.$history->toSql().")
            SELECT count(*) AS orders, coalesce(sum(c.total),0) AS total, count(*) FILTER (WHERE $earlier) AS returning_orders, coalesce(sum(c.total) FILTER (WHERE $earlier),0) AS returning_total
            FROM c", [...$current->getBindings(), ...$history->getBindings()]);
        $orders = (int) $row->orders;

        return ['orders' => $orders, 'returning_orders' => (int) $row->returning_orders, 'total' => Decimal::trim((string) $row->total), 'returning_total' => Decimal::trim((string) $row->returning_total),
            'rate' => $orders === 0 ? null : Decimal::trim(bcdiv(bcmul((string) $row->returning_orders, '100', 6), (string) $orders, 1))];
    }
}
