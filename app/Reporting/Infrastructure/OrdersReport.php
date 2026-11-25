<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Order volume and operational state. Every order is counted once, by its **current** status (a status change is not a
 * new order). The date basis is `created` (when the order was placed, every order) or `paid` (when it became fully paid,
 * including orders that were cancelled afterwards, so they stay visible). Three different money figures are kept apart:
 * order value (`orders.total`), collected (captured payments, which for cash on delivery exist only once staff record
 * the money) and the outstanding difference.
 */
final class OrdersReport
{
    public function __construct(private ReportOrders $orders, private ReportNames $names) {}

    /** Orders in the window by the chosen date basis, joined as `o` and `s` (plus `pay` for the paid basis). */
    public function base(ReportFilters $f, ReportPeriod $period): Builder
    {
        $query = $f->dateBasis === 'created' ? $this->orders->placed($f, $period) : $this->orders->paid($f, $period, includeVoid: true);

        return $this->orders->containing($query, $f);
    }

    /** Captured money per order of the window, to join as `c`. */
    private function captured(Builder $base): Builder
    {
        return DB::table('order_payments')->where('state', 'captured')->whereIn('order_id', (clone $base)->select('o.id'))->groupBy('order_id')->selectRaw('order_id, sum(amount) AS captured');
    }

    /**
     * One row per configured status (zero rows included), with its meaning, order count, order value and collected money.
     *
     * @return list<array<string, mixed>>
     */
    public function statuses(ReportFilters $f, ReportPeriod $period): array
    {
        $base = $this->base($f, $period);
        $rows = (clone $base)->leftJoinSub($this->captured($base), 'c', 'c.order_id', '=', 'o.id')->groupBy('o.status_id')
            ->selectRaw('o.status_id AS id, count(*) AS orders, coalesce(sum(o.total),0) AS value, coalesce(sum(coalesce(c.captured, 0)),0) AS collected')->get()->keyBy(fn (object $r): string => (string) $r->id);
        $result = [];
        foreach ($this->names->statuses() as $id => $status) {
            $row = $rows[$id] ?? null;
            if ($row === null && ! $status['active']) {
                continue;
            }
            $value = Decimal::trim((string) ($row->value ?? '0'));
            $collected = Decimal::trim((string) ($row->collected ?? '0'));
            $result[] = [...$status, 'orders' => (int) ($row->orders ?? 0), 'value' => $value, 'collected' => $collected, 'outstanding' => $status['void'] ? '0' : (Decimal::compare($value, $collected) > 0 ? Decimal::trim(Decimal::subtract($value, $collected)) : '0')];
        }

        return $result;
    }

    /** @return array{paid: int, partially_paid: int, pending: int, unpaid: int} Orders by derived payment state. */
    public function paymentStates(ReportFilters $f, ReportPeriod $period): array
    {
        $base = $this->base($f, $period);
        $row = (clone $base)->leftJoinSub($this->captured($base), 'c', 'c.order_id', '=', 'o.id')->selectRaw(
            "count(*) FILTER (WHERE o.total > 0 AND coalesce(c.captured,0) >= o.total) AS paid,
             count(*) FILTER (WHERE coalesce(c.captured,0) > 0 AND coalesce(c.captured,0) < o.total) AS partially_paid,
             count(*) FILTER (WHERE coalesce(c.captured,0) = 0 AND EXISTS (SELECT 1 FROM order_payments pp WHERE pp.order_id = o.id AND pp.state = 'pending')) AS pending,
             count(*) FILTER (WHERE coalesce(c.captured,0) = 0 AND NOT EXISTS (SELECT 1 FROM order_payments pp WHERE pp.order_id = o.id AND pp.state = 'pending') AND NOT (o.total > 0 AND coalesce(c.captured,0) >= o.total)) AS unpaid"
        )->first();

        return ['paid' => (int) $row->paid, 'partially_paid' => (int) $row->partially_paid, 'pending' => (int) $row->pending, 'unpaid' => (int) $row->unpaid];
    }

    /** Listing of the window's orders with collected money; shared by the screen and the CSV export. */
    public function listing(ReportFilters $f, ReportPeriod $period, string $sort = 'date', string $direction = 'desc'): Builder
    {
        $base = $this->base($f, $period);
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $date = $f->dateBasis === 'created' ? 'o.created_at' : 'pay.paid_at';
        $column = ['date' => $date, 'total' => 'o.total', 'number' => 'o.order_number'][$sort] ?? $date;
        $query = (clone $base)->leftJoinSub($this->captured($base), 'c', 'c.order_id', '=', 'o.id')->orderBy($column, $direction)->orderBy('o.id', $direction)
            ->select('o.id', 'o.order_number', 'o.created_at', 'o.first_name', 'o.last_name', 'o.customer_id', 'o.status_id', 'o.payment_method_name', 'o.shipping_method_name', 'o.total', 'o.currency_code')
            ->selectRaw('coalesce(c.captured, 0) AS captured');

        return $f->dateBasis === 'created' ? $query : $query->addSelect('pay.paid_at');
    }
}
