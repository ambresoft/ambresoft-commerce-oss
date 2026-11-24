<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Coupon performance from persisted redemptions and order snapshots (never today's coupon settings). Usage is dated by
 * the redemption (when the order used the coupon) and counts only active redemptions: one released when its order was
 * cancelled or edited no longer counts as used (it is reported separately). An order carries at most one coupon, so
 * attributing the sales of that order to it is unambiguous: `sales` are the net merchandise and the total of the
 * redeeming orders that are paid sales **today**, which makes the figure independent of the period of payment.
 */
final class PromotionReport
{
    public function __construct(private ReportOrders $orders) {}

    /** Per-coupon usage and attributed sales for redemptions within the window and currency. */
    public function usage(ReportFilters $f, ReportPeriod $period): Builder
    {
        $redeemed = DB::table('coupon_redemptions as cr')->whereNull('cr.released_at')->where('cr.currency_code', $f->currency)->where('cr.created_at', '>=', $period->startUtc())->where('cr.created_at', '<', $period->endUtc());
        $sales = $this->orders->paid($f, null, only: DB::table('coupon_redemptions')->whereNull('released_at')->where('currency_code', $f->currency)->where('created_at', '>=', $period->startUtc())->where('created_at', '<', $period->endUtc())->select('order_id'))
            ->select('o.id', 'o.subtotal', 'o.discount_total', 'o.total');

        return $redeemed->leftJoinSub($sales, 'q', 'q.id', '=', 'cr.order_id')->groupBy('cr.coupon_id', 'cr.code')->orderByRaw('count(*) DESC')->orderBy('cr.code')
            ->selectRaw('cr.coupon_id, cr.code, count(*) AS redemptions, coalesce(sum(cr.discount),0) AS discount, coalesce(sum(cr.shipping_discount),0) AS shipping_discount, count(q.id) AS sales_orders, coalesce(sum(q.subtotal - q.discount_total),0) AS net, coalesce(sum(q.total),0) AS total');
    }

    /** Redemptions released (order cancelled, edited or removed) within the window. */
    public function released(ReportFilters $f, ReportPeriod $period): int
    {
        return DB::table('coupon_redemptions')->whereNotNull('released_at')->where('currency_code', $f->currency)->where('created_at', '>=', $period->startUtc())->where('created_at', '<', $period->endUtc())->count();
    }
}
