<?php

namespace App\Reporting\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;

/**
 * CSV rows of the report tables, produced from the very queries the screens paginate (same filters, same ordering),
 * streamed row by row through a database cursor so large histories never sit in memory. Amounts are plain decimals
 * in the stated currency; customer names, e-mails and other personal data appear only where the report itself is
 * about customers.
 */
final class ReportExport
{
    public function __construct(
        private SalesReport $sales, private OrdersReport $orders, private ProductReport $products, private CategoryReport $categories, private CustomerReport $customers,
        private InventoryReport $inventory, private PromotionReport $promotions, private ReportMoney $money, private ReportNames $names, private ReportCategories $tree,
        private BrandReport $brands,
    ) {}

    /**
     * @param  array{sort: string, direction: string, search: string, view: string, mode: string, threshold: ?int, status: ?string, category_id: ?int}  $o
     * @return array{filename: string, header: list<string>, rows: Generator<int, list<string>>}
     */
    public function build(string $report, ReportFilters $f, array $o): array
    {
        $currency = $f->currency;
        $m = fn (mixed $v): string => (string) $this->money->plain($v === null ? null : Decimal::trim((string) $v), $currency);
        $at = fn (mixed $v): string => $v === null ? '' : CarbonImmutable::parse($v)->setTimezone($f->period->start->getTimezone())->format('Y-m-d H:i:s');
        $name = 'report-'.$report.'-'.$f->period->start->format('Y-m-d').'_'.$f->period->end->modify('-1 day')->format('Y-m-d').($report === 'inventory' ? '' : '-'.$currency).'.csv';

        return match ($report) {
            'sales' => $f->lineScoped()
                ? ['filename' => $name, 'header' => ['order_number', 'paid_at', 'product', 'sku', 'quantity', 'gross_merchandise', 'discounts', 'net_merchandise', 'currency'],
                    'rows' => $this->each($this->sales->detail($f, $f->period, $o['sort'], $o['direction']), fn (object $r): array => [$r->order_number, $at($r->paid_at), $r->name, (string) $r->sku, (string) $r->quantity, $m($r->subtotal), $m($r->discount_total), $m(Decimal::subtract($r->subtotal, $r->discount_total)), $currency])]
                : ['filename' => $name, 'header' => ['order_number', 'paid_at', 'payment_method', 'shipping_method', 'coupon', 'gross_merchandise', 'discounts', 'net_merchandise', 'shipping_charged', 'payment_fees', 'tax_added', 'tax_included_in_prices', 'total_charged', 'currency'],
                    'rows' => $this->each($this->sales->detail($f, $f->period, $o['sort'], $o['direction']), fn (object $r): array => [$r->order_number, $at($r->paid_at), (string) $r->payment_method_name, (string) $r->shipping_method_name, (string) $r->coupon_code, $m($r->subtotal), $m($r->discount_total), $m(Decimal::subtract($r->subtotal, $r->discount_total)), $m($r->shipping_total), $m($r->payment_fee), $m(Decimal::subtract($r->tax_total, $r->tax_included)), $m($r->tax_included), $m($r->total), $currency])],
            'orders' => ['filename' => $name, 'header' => ['order_number', 'created_at', 'paid_at', 'status', 'payment_state', 'payment_method', 'shipping_method', 'order_value', 'collected', 'currency'],
                'rows' => $this->each($this->orders->listing($f, $f->period, $o['sort'], $o['direction']), function (object $r) use ($m, $at): array {
                    $captured = (string) $r->captured;
                    $state = Decimal::compare($captured, '0') <= 0 ? 'unpaid' : (Decimal::compare($captured, $r->total) >= 0 ? 'paid' : 'partially_paid');

                    return [$r->order_number, $at($r->created_at), $at($r->paid_at ?? null), $this->names->statuses()[(string) $r->status_id]['name'] ?? '', $state, (string) $r->payment_method_name, (string) $r->shipping_method_name, $m($r->total), $m($captured), $r->currency_code];
                })],
            'products' => ['filename' => $name, 'header' => ['product', 'sku', 'units_sold', 'orders', 'gross_merchandise', 'discounts', 'net_merchandise', 'currency'],
                'rows' => $this->each($this->products->ranking($f, $f->period, $o['search'], $o['sort'], $o['direction']), fn (object $r): array => [$r->name, (string) $r->sku, (string) $r->units, (string) $r->orders, $m($r->gross), $m($r->discounts), $m($r->net), $currency])],
            'categories' => ['filename' => $name, 'header' => ['category', 'units_sold', 'orders', 'gross_merchandise', 'discounts', 'net_merchandise', 'currency', 'aggregation'],
                'rows' => (function () use ($f, $o, $m, $currency): Generator {
                    $result = $this->categories->compute($f, $f->period, $o['mode']);
                    $rows = $result['rows'];
                    usort($rows, fn (array $a, array $b): int => Decimal::compare($b['net'], $a['net']) ?: strcmp($a['id'], $b['id']));
                    foreach ($rows as $r) {
                        yield [$r['path'], (string) $r['units'], (string) $r['orders'], $m($r['gross']), $m($r['discounts']), $m($r['net']), $currency, $o['mode']];
                    }
                    $u = $result['unclassified'];
                    if ($u['units'] > 0) {
                        yield ['(unclassified)', (string) $u['units'], (string) $u['orders'], $m($u['gross']), $m($u['discounts']), $m($u['net']), $currency, 'direct'];
                    }
                })()],
            'brands' => ['filename' => $name, 'header' => ['brand', 'units_sold', 'orders', 'products_sold', 'gross_merchandise', 'discounts', 'net_merchandise', 'share_percent', 'previous_net_merchandise', 'change_percent', 'currency'],
                'rows' => (function () use ($f, $m, $currency): Generator {
                    $result = $this->brands->compute($f, $f->period, $f->compare);
                    $rows = $result['rows'];
                    usort($rows, fn (array $a, array $b): int => Decimal::compare($b['net'], $a['net']) ?: strcmp($a['id'], $b['id']));
                    foreach ($rows as $r) {
                        yield [$r['name'], (string) $r['units'], (string) $r['orders'], (string) $r['products'], $m($r['gross']), $m($r['discounts']), $m($r['net']), $r['share'], $r['previous_net'] === null ? '' : $m($r['previous_net']), (string) ($r['change'] ?? ''), $currency];
                    }
                    $u = $result['unbranded'];
                    if ($u['units'] > 0) {
                        yield ['(no brand)', (string) $u['units'], (string) $u['orders'], (string) $u['products'], $m($u['gross']), $m($u['discounts']), $m($u['net']), '', '', '', $currency];
                    }
                })()],
            'customers' => ['filename' => $name, 'header' => ['customer', 'email', 'type', 'orders', 'net_merchandise', 'total_charged', 'average_order_value', 'last_order_at', 'currency'],
                'rows' => $this->each($this->customers->ranking($f, $f->period, $o['sort'], $o['direction']), fn (object $r): array => [trim($r->first_name.' '.$r->last_name), $r->email, $r->customer_id === null ? 'guest' : 'registered', (string) $r->orders, $m($r->net), $m($r->total), $m(Decimal::divide($r->total, (string) $r->orders, 6)), $at($r->last_order_at), $currency])],
            'inventory' => ['filename' => $name, 'header' => ['product', 'sku', 'status', 'quantity', 'category'],
                'rows' => (function () use ($o): Generator {
                    // Names are looked up per batch of rows, never per row.
                    $batch = [];
                    $rows = function (array $batch): Generator {
                        $names = $this->names->products(array_map(fn (object $r): int => (int) $r->id, $batch));
                        foreach ($batch as $r) {
                            yield [$names[(string) $r->id] ?: '#'.$r->id, (string) $r->sku, $r->status, (string) $r->quantity, $r->category_id === null ? '' : $this->tree->path((int) $r->category_id)];
                        }
                    };
                    foreach ($this->inventory->listing($o['view'], $o['threshold'], $o['status'], $o['category_id'], $o['search'])->cursor() as $r) {
                        $batch[] = $r;
                        if (count($batch) >= 500) {
                            yield from $rows($batch);
                            $batch = [];
                        }
                    }
                    yield from $rows($batch);
                })()],
            default => ['filename' => $name, 'header' => ['coupon', 'redemptions', 'merchandise_discount', 'shipping_discount', 'paid_orders', 'net_merchandise', 'total_charged', 'currency'],
                'rows' => $this->each($this->promotions->usage($f, $f->period), fn (object $r): array => [$r->code, (string) $r->redemptions, $m($r->discount), $m($r->shipping_discount), (string) $r->sales_orders, $m($r->net), $m($r->total), $currency])],
        };
    }

    /** @return Generator<int, list<string>> */
    private function each(Builder $query, callable $row): Generator
    {
        foreach ($query->cursor() as $r) {
            yield $row($r);
        }
    }
}
