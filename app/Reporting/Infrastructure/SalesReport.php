<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures over the shared sale definition (ReportOrders). All amounts are decimal strings in the filtered
 * currency. Merchandise lines are order-item snapshots; shipping, tax and the charged total are order snapshots.
 *
 *   gross merchandise = Σ lines (quantity × the unit price charged)        discounts = Σ discounts allocated to lines
 *   net merchandise   = gross − discounts (− refunds: not recorded, so unavailable)
 *   total charged     = net merchandise + tax added on top + shipping charged   (the order's stored total)
 *   average order value = total charged ÷ paid orders
 *
 * Tax already inside tax-inclusive prices is part of the merchandise figures and is reported as `tax_included`.
 * When a product or category is filtered the report switches to line level and the order-level figures (shipping,
 * tax, total charged, average order value) are `null`: they cannot be attributed to a single product.
 */
final class SalesReport
{
    public function __construct(private ReportOrders $orders) {}

    /** @return array<string, ?string> */
    public function summary(ReportFilters $f, ReportPeriod $period): array
    {
        if ($f->lineScoped()) {
            $row = $this->orders->lines($f, $period)->selectRaw('count(distinct oi.order_id) AS orders, coalesce(sum(oi.quantity),0) AS units, coalesce(sum(oi.subtotal),0) AS gross, coalesce(sum(oi.discount_total),0) AS discounts')->first();

            return [
                'orders' => (string) $row->orders, 'units' => (string) $row->units, 'gross' => Decimal::trim($row->gross), 'discounts' => Decimal::trim($row->discounts), 'refunds' => null,
                'net' => Decimal::trim(Decimal::subtract($row->gross, $row->discounts)), 'shipping' => null, 'shipping_discount' => null, 'payment_fees' => null, 'tax_added' => null, 'tax_included' => null, 'total' => null, 'aov' => null,
            ];
        }
        $row = $this->orders->paid($f, $period)->selectRaw('count(*) AS orders, coalesce(sum(o.subtotal),0) AS gross, coalesce(sum(o.discount_total),0) AS discounts, coalesce(sum(o.shipping_total),0) AS shipping, coalesce(sum(o.shipping_discount),0) AS shipping_discount, coalesce(sum(o.payment_fee),0) AS payment_fees, coalesce(sum(o.tax_total - o.tax_included),0) AS tax_added, coalesce(sum(o.tax_included),0) AS tax_included, coalesce(sum(o.total),0) AS total')->first();
        $units = (int) DB::table('order_items as oi')->whereIn('oi.order_id', $this->orders->paid($f, $period)->select('o.id'))->sum('oi.quantity');
        $orders = (int) $row->orders;

        return [
            'orders' => (string) $orders, 'units' => (string) $units, 'gross' => Decimal::trim($row->gross), 'discounts' => Decimal::trim($row->discounts), 'refunds' => null,
            'net' => Decimal::trim(Decimal::subtract($row->gross, $row->discounts)), 'shipping' => Decimal::trim($row->shipping), 'shipping_discount' => Decimal::trim($row->shipping_discount), 'payment_fees' => Decimal::trim($row->payment_fees),
            'tax_added' => Decimal::trim($row->tax_added), 'tax_included' => Decimal::trim($row->tax_included), 'total' => Decimal::trim($row->total),
            'aov' => $orders === 0 ? null : Decimal::trim(Decimal::divide($row->total, (string) $orders, 6)),
        ];
    }

    /**
     * Orders around the sales, for context: placed in the period, not yet paid (outstanding amount), cancelled/failed,
     * and money collected on orders that were later cancelled or failed (a refund is not recorded, so it needs staff).
     *
     * @return array{placed: int, unpaid_orders: int, unpaid_value: string, void_orders: int, void_value: string, collected_on_void_orders: int, collected_on_void: string}
     */
    public function context(ReportFilters $f, ReportPeriod $period): array
    {
        $placed = $this->orders->placed($f, $period);
        $captured = DB::table('order_payments')->where('state', 'captured')->whereIn('order_id', (clone $placed)->select('o.id'))->groupBy('order_id')->selectRaw('order_id, sum(amount) AS captured');
        $open = $this->orders->placed($f, $period)->leftJoinSub($captured, 'c', 'c.order_id', '=', 'o.id')->whereRaw('NOT '.ReportOrders::VOID)->whereRaw('coalesce(c.captured, 0) < o.total')
            ->selectRaw('count(*) AS orders, coalesce(sum(o.total - coalesce(c.captured, 0)),0) AS value')->first();
        $void = $this->orders->placed($f, $period)->whereRaw(ReportOrders::VOID)->selectRaw('count(*) AS orders, coalesce(sum(o.total),0) AS value')->first();
        $paidVoid = $this->orders->paid($f, $period, includeVoid: true)->whereRaw(ReportOrders::VOID)->selectRaw('count(*) AS orders, coalesce(sum(pay.captured),0) AS value')->first();

        return [
            'placed' => (int) (clone $placed)->count(), 'unpaid_orders' => (int) $open->orders, 'unpaid_value' => Decimal::trim($open->value), 'void_orders' => (int) $void->orders, 'void_value' => Decimal::trim($void->value),
            'collected_on_void_orders' => (int) $paidVoid->orders, 'collected_on_void' => Decimal::trim($paidVoid->value),
        ];
    }

    /**
     * Net merchandise sales and paid orders per time bucket (hour, day, week or month by window length), zero-filled.
     *
     * @return list<array{at: string, orders: int, net: string}>
     */
    public function series(ReportFilters $f, ReportPeriod $period): array
    {
        $unit = $period->granularity();
        $zone = $period->start->getTimezone()->getName();
        $bucket = 'date_trunc(?, %s AT TIME ZONE ?)';
        if ($f->lineScoped()) {
            $rows = $this->orders->lines($f, $period)->selectRaw(sprintf($bucket, 'q.paid_at').' AS bucket, count(distinct oi.order_id) AS orders, coalesce(sum(oi.subtotal - oi.discount_total),0) AS net', [$unit, $zone]);
        } else {
            $rows = $this->orders->paid($f, $period)->selectRaw(sprintf($bucket, 'pay.paid_at').' AS bucket, count(*) AS orders, coalesce(sum(o.subtotal - o.discount_total),0) AS net', [$unit, $zone]);
        }
        $found = $rows->groupByRaw('1')->get()->keyBy(fn (object $r): string => date('Y-m-d H:i:s', strtotime((string) $r->bucket)));
        $series = [];
        foreach ($period->buckets() as $at) {
            $row = $found[$at->format('Y-m-d H:i:s')] ?? null;
            $series[] = ['at' => $at->format('Y-m-d\TH:i:sP'), 'orders' => (int) ($row->orders ?? 0), 'net' => Decimal::trim((string) ($row->net ?? '0'))];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> Sales per payment method snapshot, plus unpaid orders and failed attempts. */
    public function byPayment(ReportFilters $f, ReportPeriod $period): array
    {
        $rows = [];
        $key = fn (?string $id): string => $id ?? 'none';
        foreach ($this->orders->paid($f, $period)->groupBy('o.payment_method_id')->selectRaw('o.payment_method_id AS id, max(o.payment_method_name) AS name, count(*) AS orders, coalesce(sum(o.subtotal - o.discount_total),0) AS net, coalesce(sum(o.total),0) AS total')->get() as $r) {
            $rows[$key($r->id === null ? null : (string) $r->id)] = ['id' => $r->id === null ? null : (string) $r->id, 'name' => $r->name, 'orders' => (int) $r->orders, 'net' => Decimal::trim($r->net), 'total' => Decimal::trim($r->total), 'unpaid_orders' => 0, 'unpaid_value' => '0', 'failed_attempts' => 0];
        }
        $placed = $this->orders->placed($f, $period);
        $captured = DB::table('order_payments')->where('state', 'captured')->whereIn('order_id', (clone $placed)->select('o.id'))->groupBy('order_id')->selectRaw('order_id, sum(amount) AS captured');
        foreach ($this->orders->placed($f, $period)->leftJoinSub($captured, 'c', 'c.order_id', '=', 'o.id')->whereRaw('NOT '.ReportOrders::VOID)->whereRaw('coalesce(c.captured, 0) < o.total')
            ->groupBy('o.payment_method_id')->selectRaw('o.payment_method_id AS id, max(o.payment_method_name) AS name, count(*) AS orders, coalesce(sum(o.total - coalesce(c.captured, 0)),0) AS value')->get() as $r) {
            $k = $key($r->id === null ? null : (string) $r->id);
            $rows[$k] ??= ['id' => $r->id === null ? null : (string) $r->id, 'name' => $r->name, 'orders' => 0, 'net' => '0', 'total' => '0', 'unpaid_orders' => 0, 'unpaid_value' => '0', 'failed_attempts' => 0];
            $rows[$k]['unpaid_orders'] = (int) $r->orders;
            $rows[$k]['unpaid_value'] = Decimal::trim($r->value);
        }
        foreach (DB::table('order_payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')->where('p.state', 'failed')->where('o.currency_code', $f->currency)
            ->where('p.completed_at', '>=', $period->startUtc())->where('p.completed_at', '<', $period->endUtc())->groupBy('p.payment_method_id')->selectRaw('p.payment_method_id AS id, count(*) AS attempts')->get() as $r) {
            $k = $key($r->id === null ? null : (string) $r->id);
            $name = $r->id === null ? null : DB::table('payment_methods')->where('id', $r->id)->value('code');
            $rows[$k] ??= ['id' => $r->id === null ? null : (string) $r->id, 'name' => $name, 'orders' => 0, 'net' => '0', 'total' => '0', 'unpaid_orders' => 0, 'unpaid_value' => '0', 'failed_attempts' => 0];
            $rows[$k]['failed_attempts'] = (int) $r->attempts;
        }
        usort($rows, fn (array $a, array $b): int => Decimal::compare($b['total'], $a['total']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /** @return list<array<string, mixed>> Sales per shipping method snapshot with shipping charged and discounted. */
    public function byShipping(ReportFilters $f, ReportPeriod $period): array
    {
        return $this->orders->paid($f, $period)->groupBy('o.shipping_method_id')->orderByRaw('sum(o.total) DESC')
            ->selectRaw('o.shipping_method_id AS id, max(o.shipping_method_name) AS name, count(*) AS orders, coalesce(sum(o.shipping_total),0) AS shipping, coalesce(sum(o.shipping_discount),0) AS shipping_discount, coalesce(sum(o.total),0) AS total')->get()
            ->map(fn (object $r): array => ['id' => $r->id === null ? null : (string) $r->id, 'name' => $r->name, 'orders' => (int) $r->orders, 'shipping' => Decimal::trim($r->shipping), 'shipping_discount' => Decimal::trim($r->shipping_discount), 'total' => Decimal::trim($r->total)])->all();
    }

    /** @return list<array<string, mixed>> Sales of orders that used a coupon (one coupon per order), by the code used. */
    public function byCoupon(ReportFilters $f, ReportPeriod $period): array
    {
        return $this->orders->paid($f, $period)->whereNotNull('o.coupon_code')->groupBy('o.coupon_id', 'o.coupon_code')->orderByRaw('count(*) DESC')
            ->selectRaw('o.coupon_id AS id, o.coupon_code AS code, count(*) AS orders, coalesce(sum(o.discount_total),0) AS discounts, coalesce(sum(o.shipping_discount),0) AS shipping_discount, coalesce(sum(o.subtotal - o.discount_total),0) AS net, coalesce(sum(o.total),0) AS total')->get()
            ->map(fn (object $r): array => ['id' => (string) $r->id, 'code' => $r->code, 'orders' => (int) $r->orders, 'discounts' => Decimal::trim($r->discounts), 'shipping_discount' => Decimal::trim($r->shipping_discount), 'net' => Decimal::trim($r->net), 'total' => Decimal::trim($r->total)])->all();
    }

    /**
     * The detail listing of the sales in the window (orders; with a product/category filter the matching lines),
     * newest payment first. Shared by the screen and the CSV export.
     */
    public function detail(ReportFilters $f, ReportPeriod $period, string $sort = 'paid_at', string $direction = 'desc'): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        if ($f->lineScoped()) {
            $column = ['paid_at' => 'q.paid_at', 'net' => DB::raw('(oi.subtotal - oi.discount_total)'), 'quantity' => 'oi.quantity'][$sort] ?? 'q.paid_at';

            return $this->orders->lines($f, $period)->join('orders as o', 'o.id', '=', 'oi.order_id')->orderBy($column, $direction)->orderBy('oi.id', $direction)
                ->select('oi.id', 'o.id as order_id', 'o.order_number', 'q.paid_at', 'oi.name', 'oi.sku', 'oi.quantity', 'oi.subtotal', 'oi.discount_total', 'o.currency_code', 'oi.product_id');
        }
        $column = ['paid_at' => 'pay.paid_at', 'total' => 'o.total', 'number' => 'o.order_number'][$sort] ?? 'pay.paid_at';

        return $this->orders->paid($f, $period)->orderBy($column, $direction)->orderBy('o.id', $direction)
            ->select('o.id', 'o.order_number', 'pay.paid_at', 'o.payment_method_name', 'o.shipping_method_name', 'o.coupon_code', 'o.subtotal', 'o.discount_total', 'o.shipping_total', 'o.payment_fee', 'o.tax_total', 'o.tax_included', 'o.total', 'o.currency_code');
    }
}
