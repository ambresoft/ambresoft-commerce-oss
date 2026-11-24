<?php

namespace Tests\Feature;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Reporting\Infrastructure\BrandReport;
use App\Reporting\Infrastructure\CategoryReport;
use App\Reporting\Infrastructure\CustomerReport;
use App\Reporting\Infrastructure\InsightsReport;
use App\Reporting\Infrastructure\InventoryReport;
use App\Reporting\Infrastructure\OrdersReport;
use App\Reporting\Infrastructure\ProductReport;
use App\Reporting\Infrastructure\PromotionReport;
use App\Reporting\Infrastructure\ReportViews;
use App\Reporting\Infrastructure\SalesReport;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsReportFixtures;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/**
 * Financial formulas and attribution against hand-calculated expectations (the store is described in
 * BuildsReportFixtures). October 2026 sales in euro: O1 289, O2 50, O6 80, O9 30, O10 50 (five sales).
 */
final class ReportsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->fixtureStore();
        $this->fixtureOrders();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $with */
    private function filters(string $preset = 'this_month', array $with = []): ReportFilters
    {
        $period = ReportPeriod::preset($preset, new DateTimeImmutable('2026-10-17 12:00:00', new DateTimeZone('Europe/Athens')));

        return new ReportFilters($period, (bool) ($with['compare'] ?? false), $with['currency'] ?? 'EUR', $with['date'] ?? 'paid', $with['status'] ?? null, $with['payment'] ?? null, $with['shipping'] ?? null, $with['product'] ?? null, $with['category'] ?? null, $with['customer'] ?? null);
    }

    public function test_the_sales_summary_counts_only_paid_non_cancelled_orders_and_reconciles(): void
    {
        $f = $this->filters();
        $s = app(SalesReport::class)->summary($f, $f->period);
        // Sales: O1, O2, O6, O9, O10. Not sales: O3 (unpaid COD), O4 (paid but cancelled), O5 (partly paid), O7 (USD), O8 (September).
        self::assertSame(['5', '8'], [$s['orders'], $s['units']]);
        self::assertSame(['510', '30', '480'], [$s['gross'], $s['discounts'], $s['net']]);
        self::assertSame(['5', '14', '10', '499'], [$s['shipping'], $s['tax_added'], $s['tax_included'], $s['total']]);
        self::assertNull($s['refunds'], 'Refunds are not recorded, so they are unavailable rather than zero');
        // total charged = net merchandise + tax added on top + shipping: no tax, shipping or discount counted twice.
        self::assertSame('499', bcadd(bcadd($s['net'], $s['tax_added'], 6), $s['shipping'], 6) === '499.000000' ? '499' : 'x');
        self::assertSame('99.8', $s['aov']);
        self::assertSame('0', $s['shipping_discount']);

        $context = app(SalesReport::class)->context($f, $f->period);
        // Placed in October (euro): O1–O6, O9, O10 = 8 orders; unpaid: O3 (35) and O5 (60); cancelled: O4 (200); paid then cancelled: O4.
        self::assertSame([8, 2, '95', 1, '200', 1, '200'], [$context['placed'], $context['unpaid_orders'], $context['unpaid_value'], $context['void_orders'], $context['void_value'], $context['collected_on_void_orders'], $context['collected_on_void']]);
    }

    public function test_the_payment_date_is_when_the_order_was_first_covered_and_boundaries_follow_the_store_timezone(): void
    {
        // O6 was covered on 14 October by its second instalment; the later duplicate payment (15 October) does not move it.
        $f = $this->filters();
        $period = ReportPeriod::custom('2026-10-14', '2026-10-14', new DateTimeZone('Europe/Athens'));
        self::assertSame('1', app(SalesReport::class)->summary($f->forPeriod($period), $period)['orders']);
        $after = ReportPeriod::custom('2026-10-15', '2026-10-15', new DateTimeZone('Europe/Athens'));
        self::assertSame('0', app(SalesReport::class)->summary($f->forPeriod($after), $after)['orders']);

        $yesterday = $this->filters('yesterday');
        self::assertSame(['1', '50'], [($y = app(SalesReport::class)->summary($yesterday, $yesterday->period))['orders'], $y['total']], 'O2 was paid at 23:30 local time on the 16th');
        $today = $this->filters('today');
        $t = app(SalesReport::class)->summary($today, $today->period);
        self::assertSame(['1', '50'], [$t['orders'], $t['total']], 'O10 was paid at 00:00 local time: the first instant of today (21:00 UTC the day before)');
    }

    public function test_comparison_uses_the_equivalent_previous_window_and_history_is_not_invented(): void
    {
        $f = $this->filters('this_month', ['compare' => true]);
        $previous = app(SalesReport::class)->summary($f, $f->period->previous());
        self::assertSame(['1', '4', '200'], [$previous['orders'], $previous['units'], $previous['total']], '1–17 September: O8');
        // Before the first sale there is nothing to compare with: the period has no data.
        $early = ReportPeriod::custom('2026-01-01', '2026-01-31', new DateTimeZone('Europe/Athens'));
        self::assertSame('0', app(SalesReport::class)->summary($f->forPeriod($early->previous()), $early->previous())['orders']);
    }

    public function test_the_sales_series_is_zero_filled_and_adds_up_to_the_summary(): void
    {
        $f = $this->filters('this_month');
        $series = app(SalesReport::class)->series($f, $f->period);
        self::assertCount(17, $series);
        self::assertSame(['5', '480'], [(string) array_sum(array_column($series, 'orders')), array_reduce($series, fn (string $c, array $p): string => bcadd($c, $p['net'], 6), '0') === '480.000000' ? '480' : 'x']);
        $byDay = array_column($series, 'net', 'at');
        self::assertSame('270', $byDay['2026-10-05T00:00:00+03:00'], 'O1: 300 − 30');
        self::assertSame('0', $byDay['2026-10-08T00:00:00+03:00']);
        self::assertSame('50', $byDay['2026-10-17T00:00:00+03:00']);
        $hourly = $this->filters('today');
        $hours = app(SalesReport::class)->series($hourly, $hourly->period);
        self::assertCount(24, $hours);
        self::assertSame(1, $hours[0]['orders']);
    }

    public function test_currencies_are_never_added_together(): void
    {
        $usd = $this->filters('this_month', ['currency' => 'USD']);
        $s = app(SalesReport::class)->summary($usd, $usd->period);
        self::assertSame(['1', '60', '60'], [$s['orders'], $s['gross'], $s['total']]);
        $eur = $this->filters();
        $others = app(ReportViews::class)->common('sales', $eur)['other_currencies'];
        self::assertSame([['currency' => 'USD', 'orders' => 1]], $others);
    }

    public function test_history_is_read_from_order_snapshots_not_current_products(): void
    {
        // Rename and re-price the product afterwards: past sales keep the line snapshots.
        DB::table('product_translations')->where('product_id', $this->ids['A'])->update(['name' => 'Renamed phone']);
        DB::table('products')->where('id', $this->ids['A'])->update(['price' => 999, 'sku' => 'NEW-SKU']);
        $f = $this->filters();
        $row = app(ProductReport::class)->ranking($f, $f->period)->get()->firstWhere('product_id', $this->ids['A']);
        self::assertSame(['Phone A', 'PH-1', '4', '200'], [$row->name, $row->sku, (string) $row->units, Decimal::trim((string) $row->gross)]);
        // A permanently deleted product still sells by its snapshot (grouped by name and SKU, without a product ID).
        DB::table('products')->where('id', $this->ids['C'])->delete();
        $names = app(ProductReport::class)->ranking($f, $f->period)->get()->pluck('name')->all();
        self::assertContains('Book C', $names);
    }

    public function test_product_rankings_quantities_and_unsold_lists(): void
    {
        $f = $this->filters();
        $report = app(ProductReport::class);
        $rows = $report->ranking($f, $f->period)->get();
        // Ranked by net merchandise: A 190 (4 units, 3 orders), B 180 (the cancelled O4 line is not counted), C 110.
        self::assertSame(['Phone A', 'Laptop B', 'Book C'], $rows->pluck('name')->all());
        self::assertSame([['4', '3', '200', '10', '190'], ['1', '1', '200', '20', '180'], ['3', '2', '110', '0', '110']], $rows->map(fn ($r) => [(string) (int) $r->units, (string) (int) $r->orders, rtrim(rtrim((string) $r->gross, '0'), '.'), rtrim(rtrim((string) $r->discounts, '0'), '.'), rtrim(rtrim((string) $r->net, '0'), '.')])->all());
        self::assertSame(['Phone A', 'Book C'], $report->ranking($f, $f->period, '', 'units', 'desc')->get()->pluck('name')->take(2)->sort()->values()->reverse()->values()->all() === ['Phone A', 'Book C'] ? ['Phone A', 'Book C'] : ['?']);
        self::assertSame(['Phone A'], $report->ranking($f, $f->period, 'ph-')->get()->pluck('name')->all(), 'Search matches the SKU snapshot');
        self::assertSame(['Book C'], $report->ranking($f, $f->period, 'book')->get()->pluck('name')->all());

        // Never sold: only the undeleted active product D (E is in the trash, F is digital but has no sales either).
        self::assertEqualsCanonicalizing([$this->ids['D'], $this->ids['F']], $report->unsold($f, null)->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        // Not sold in October: the same two (everything else sold).
        self::assertEqualsCanonicalizing([$this->ids['D'], $this->ids['F']], $report->unsold($f, $f->period)->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        // Declining against 1–17 September: A sold 4 then, 4 now ... no; A sold 4 in September and 4 in October is flat.
        self::assertSame([], $report->declining($f, $f->period)->get()->all());
        DB::table('order_items')->where('order_id', $this->ids['O8'])->update(['quantity' => 6, 'subtotal' => 300, 'total' => 300]);
        DB::table('orders')->where('id', $this->ids['O8'])->update(['subtotal' => 300, 'total' => 300]);
        DB::table('order_payments')->where('order_id', $this->ids['O8'])->update(['amount' => 300]);
        $declining = $report->declining($f, $f->period)->get();
        self::assertSame([[(string) $this->ids['A'], 4, 6]], $declining->map(fn ($r) => [(string) $r->product_id, (int) $r->units, (int) $r->previous_units])->all());
    }

    public function test_category_attribution_does_not_double_count(): void
    {
        $f = $this->filters();
        $direct = app(CategoryReport::class)->compute($f, $f->period, 'direct');
        $rows = collect($direct['rows'])->keyBy('name');
        // Each line's own value goes to its product's category; O1 has lines in Phones and Laptops and counts once in each.
        self::assertSame([4, 3, '200', '10', '190'], [$rows['Phones']['units'], $rows['Phones']['orders'], $rows['Phones']['gross'], $rows['Phones']['discounts'], $rows['Phones']['net']]);
        self::assertSame([1, 1, '180'], [$rows['Laptops']['units'], $rows['Laptops']['orders'], $rows['Laptops']['net']]);
        self::assertSame([3, 2, '110'], [$rows['Books']['units'], $rows['Books']['orders'], $rows['Books']['net']]);
        self::assertArrayNotHasKey('Electronics', $rows->all(), 'No products are assigned directly to the parent');
        // The grand total is every line once: 480 = 190 + 180 + 110, and 5 distinct orders although the rows sum to 6.
        self::assertSame([8, 5, '480'], [$direct['total']['units'], $direct['total']['orders'], $direct['total']['net']]);
        self::assertSame(6, array_sum(array_column($direct['rows'], 'orders')));

        $tree = collect(app(CategoryReport::class)->compute($f, $f->period, 'tree')['rows'])->keyBy('name');
        // With descendants the parent holds both children: 190 + 180 = 370 and O1 still counts once (orders O1, O2, O10).
        self::assertSame([5, 3, '400', '30', '370'], [$tree['Electronics']['units'], $tree['Electronics']['orders'], $tree['Electronics']['gross'], $tree['Electronics']['discounts'], $tree['Electronics']['net']]);
        self::assertSame('370', bcadd($tree['Phones']['net'], $tree['Laptops']['net'], 0));
        self::assertSame('Electronics › Phones', $rows['Phones']['path']);
    }

    public function test_brand_sales_use_the_brand_each_line_was_sold_under(): void
    {
        $this->fixtureBrands();
        $f = $this->filters('this_month', ['compare' => true]);
        $result = app(BrandReport::class)->compute($f, $f->period, true);
        $rows = collect($result['rows'])->keyBy('name');
        // Phones sold under Acme stay Acme although product A is Zeta's now: O1, O2 and O10.
        self::assertSame([4, 3, 1, '200', '10', '190'], [$rows['Acme']['units'], $rows['Acme']['orders'], $rows['Acme']['products'], $rows['Acme']['gross'], $rows['Acme']['discounts'], $rows['Acme']['net']]);
        self::assertSame([1, 1, '180'], [$rows['Zeta']['units'], $rows['Zeta']['orders'], $rows['Zeta']['net']]);
        self::assertSame([3, 2, '110'], [$result['unbranded']['units'], $result['unbranded']['orders'], $result['unbranded']['net']]);
        // Every line once: 190 + 180 + 110 = 480, five distinct orders; shares of the 480.
        self::assertSame([8, 5, '480'], [$result['total']['units'], $result['total']['orders'], $result['total']['net']]);
        self::assertSame(['39.58', '37.5'], [$rows['Acme']['share'], $rows['Zeta']['share']]);
        // September: O8's four phones (200) under Acme; Zeta sold nothing then, so it has no percentage.
        self::assertSame(['200', '-5'], [$rows['Acme']['previous_net'], $rows['Acme']['change']]);
        self::assertSame(['0', null], [$rows['Zeta']['previous_net'], $rows['Zeta']['change']]);

        // A deleted brand keeps ranking under the name it was sold under.
        DB::table('products')->where('brand_id', $this->ids['zeta'])->update(['brand_id' => null]);
        DB::table('brand_translations')->where('brand_id', $this->ids['zeta'])->delete();
        DB::table('brands')->where('id', $this->ids['zeta'])->delete();
        $zeta = collect(app(BrandReport::class)->compute($f, $f->period)['rows'])->firstWhere('name', 'Zeta');
        self::assertSame([false, '180'], [$zeta['exists'], $zeta['net']]);
    }

    public function test_shopping_patterns_by_weekday_hour_and_returning_buyers(): void
    {
        $f = $this->filters();
        $timing = app(InsightsReport::class)->timing($f, $f->period);
        self::assertSame(5, array_sum(array_column($timing['weekdays'], 'orders')));
        self::assertCount(24, $timing['hours']);
        // O1 was paid on Monday 5 October at 10:05 Athens time: net 300 − 30.
        self::assertSame(['orders' => 1, 'net' => '270'], array_intersect_key($timing['weekdays'][0], ['orders' => 0, 'net' => 0]));
        self::assertSame(1, $timing['hours'][10]['orders']);
        // O1 and O6 (c1 bought O8 in September) and O10 (c2 bought O2 first) are returning; O2 and the guest's O9 are not.
        self::assertSame(['orders' => 5, 'returning_orders' => 3, 'total' => '499', 'returning_total' => '419', 'rate' => '60'], app(InsightsReport::class)->returning($f, $f->period));
    }

    public function test_customers_new_repeat_returning_guest_and_no_orders(): void
    {
        $f = $this->filters();
        $report = app(CustomerReport::class);
        $rows = $report->ranking($f, $f->period)->get();
        // c1: O1 + O6 (net 350, total 369); c2: O2 + O10 (100); the guest is separate and never merged with a customer.
        self::assertSame([['C1', 2, '350', '369'], ['C2', 2, '100', '100'], ['Guest', 1, '30', '30']], $rows->map(fn ($r) => [$r->first_name, (int) $r->orders, rtrim(rtrim((string) $r->net, '0'), '.'), rtrim(rtrim((string) $r->total, '0'), '.')])->all());
        self::assertNull($rows->last()->customer_id);
        $summary = $report->summary($f, $f->period);
        self::assertSame(['new_customers' => 2, 'registered_buyers' => 2, 'guest_buyers' => 1, 'repeat_customers' => 2, 'returning_customers' => 1, 'without_orders' => 1], $summary);
        self::assertSame([$this->ids['c3']], $report->withoutOrders()->pluck('customer_id')->map(fn ($id) => (int) $id)->all());
        // Refund-free spending excludes the cancelled O4 and the unpaid orders: ordering by order count then.
        self::assertEqualsCanonicalizing(['C1', 'C2'], $report->ranking($f, $f->period, 'orders', 'desc')->get()->pluck('first_name')->take(2)->all());
    }

    public function test_inventory_distinguishes_stock_ranks_and_excludes_digital_and_trashed_products(): void
    {
        $report = app(InventoryReport::class);
        $summary = $report->summary();
        self::assertSame([4, 1, 1, 1, 17, 3], [$summary['physical'], $summary['digital'], $summary['out_of_stock'], $summary['low_stock'], $summary['units'], $summary['threshold']]);
        $ids = fn (string $view, ?int $threshold = null) => $report->listing($view, $threshold)->pluck('id')->map(fn ($id) => (int) $id)->all();
        self::assertSame([$this->ids['B']], $ids('out_of_stock'));
        self::assertSame([$this->ids['C']], $ids('low'));
        self::assertSame([$this->ids['C'], $this->ids['A'], $this->ids['D']], $ids('lowest'));
        self::assertSame([$this->ids['D'], $this->ids['A'], $this->ids['C'], $this->ids['B']], $ids('highest'));
        self::assertSame([$this->ids['D']], $ids('above', 5));
        self::assertSame([$this->ids['A']], $report->listing('highest', null, null, $this->ids['phones'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        self::assertSame([$this->ids['C']], $report->listing('highest', null, null, null, 'bk-1')->pluck('id')->map(fn ($id) => (int) $id)->all());
        // Quantities are the live values, untouched by the sales above.
        self::assertSame(5, (int) DB::table('products')->where('id', $this->ids['A'])->value('quantity'));
    }

    public function test_orders_report_counts_each_order_once_by_current_status_and_keeps_money_apart(): void
    {
        $f = $this->filters('this_month', ['date' => 'created']);
        $report = app(OrdersReport::class);
        $by = collect($report->statuses($f, $f->period))->keyBy('code');
        // Placed in October (euro): completed O1; processing O2, O3, O5, O6, O9, O10; cancelled O4.
        self::assertSame([1, 6, 1], [$by['completed']['orders'], $by['processing']['orders'], $by['cancelled']['orders']]);
        self::assertSame(['289', '289'], [$by['completed']['value'], $by['completed']['collected']]);
        // Processing: value 50+35+100+80+30+50 = 345, collected 50+0+40+160(30+50+80)+30+50 = 330 (the duplicate 80 is money received).
        self::assertSame(['345', '330'], [$by['processing']['value'], $by['processing']['collected']]);
        self::assertSame('0', $by['cancelled']['outstanding'], 'A cancelled order owes nothing');
        // Payment state is independent of the order status: O4 is paid (then cancelled), O5 partly paid, O3 unpaid.
        self::assertSame(['paid' => 6, 'partially_paid' => 1, 'pending' => 0, 'unpaid' => 1], $report->paymentStates($f, $f->period));
        // The paid basis counts orders by their payment date, including the one that was cancelled afterwards.
        $paid = $this->filters('this_month', ['date' => 'paid']);
        $byPaid = collect($report->statuses($paid, $paid->period))->keyBy('code');
        self::assertSame([1, 4, 1], [$byPaid['completed']['orders'], $byPaid['processing']['orders'], $byPaid['cancelled']['orders']]);
    }

    public function test_filters_by_status_payment_shipping_product_category_and_customer(): void
    {
        $sales = app(SalesReport::class);
        $count = function (array $with) use ($sales): array {
            $f = $this->filters('this_month', $with);
            $s = $sales->summary($f, $f->period);

            return [$s['orders'], $s['net']];
        };
        self::assertSame(['1', '270'], $count(['status' => $this->ids['completed']]));
        self::assertSame(['4', '210'], $count(['payment' => $this->ids['cod']]), 'COD sales: O2, O6, O9, O10');
        self::assertSame(['1', '270'], $count(['payment' => $this->ids['bank']]));
        self::assertSame(['1', '270'], $count(['shipping' => $this->ids['courier']]));
        self::assertSame(['2', '350'], $count(['customer' => $this->ids['c1']]));
        // A product or category narrows the report to those lines: B sold one unit in one order (the cancelled O4 is not a sale).
        $b = $this->filters('this_month', ['product' => $this->ids['B']]);
        $s = $sales->summary($b, $b->period);
        self::assertSame(['1', '1', '200', '20', '180'], [$s['orders'], $s['units'], $s['gross'], $s['discounts'], $s['net']]);
        self::assertNull($s['total'], 'Shipping, tax and the order total cannot be attributed to one product');
        $electronics = $this->filters('this_month', ['category' => $this->ids['electronics']]);
        self::assertSame(['5', '370'], [$sales->summary($electronics, $electronics->period)['units'], $sales->summary($electronics, $electronics->period)['net']]);
        $combined = $this->filters('this_month', ['product' => $this->ids['A'], 'payment' => $this->ids['cod']]);
        self::assertSame(['2', '100'], [$sales->summary($combined, $combined->period)['units'], $sales->summary($combined, $combined->period)['net']]);
    }

    public function test_payment_shipping_and_coupon_breakdowns_use_stored_snapshots(): void
    {
        $f = $this->filters();
        $sales = app(SalesReport::class);
        $payments = collect($sales->byPayment($f, $f->period))->keyBy('name');
        self::assertSame([4, '210', '210', 2, '95', 1], [$payments['Cash on delivery']['orders'], $payments['Cash on delivery']['net'], $payments['Cash on delivery']['total'], $payments['Cash on delivery']['unpaid_orders'], $payments['Cash on delivery']['unpaid_value'], $payments['Cash on delivery']['failed_attempts']]);
        self::assertSame([1, '270', '289'], [$payments['Bank deposit']['orders'], $payments['Bank deposit']['net'], $payments['Bank deposit']['total']]);
        $shipping = collect($sales->byShipping($f, $f->period));
        self::assertSame(['Courier', 1, '5', '289'], [$shipping->first()['name'], $shipping->first()['orders'], $shipping->first()['shipping'], $shipping->first()['total']]);
        self::assertSame(['none' => 4], ['none' => $shipping->firstWhere('name', null)['orders']], 'Orders without a shipping method are grouped, not dropped');
        self::assertSame([['SAVE10', 1, '30', '270', '289']], collect($sales->byCoupon($f, $f->period))->map(fn ($r) => [$r['code'], $r['orders'], $r['discounts'], $r['net'], $r['total']])->all());
    }

    public function test_promotion_usage_uses_persisted_redemptions(): void
    {
        $f = $this->filters();
        $report = app(PromotionReport::class);
        $rows = $report->usage($f, $f->period)->get();
        // One active redemption (O1: 30 discount, paid sale of 270 net / 289) and one released redemption reported separately.
        self::assertSame([['SAVE10', 1, '30', 1, '270', '289']], $rows->map(fn ($r) => [$r->code, (int) $r->redemptions, rtrim(rtrim((string) $r->discount, '0'), '.'), (int) $r->sales_orders, rtrim(rtrim((string) $r->net, '0'), '.'), rtrim(rtrim((string) $r->total, '0'), '.')])->all());
        self::assertSame(1, $report->released($f, $f->period));
        // Changing the coupon today changes nothing in the history.
        DB::table('coupons')->where('id', $this->ids['coupon'])->update(['amount' => 99, 'code' => 'RENAMED']);
        self::assertSame('SAVE10', $report->usage($f, $f->period)->first()->code);
    }

    public function test_an_empty_store_reports_zeros_without_errors(): void
    {
        DB::table('order_payments')->delete();
        DB::table('coupon_redemptions')->delete();
        DB::table('order_items')->delete();
        DB::table('orders')->delete();
        $f = $this->filters('this_month', ['compare' => true]);
        $s = app(SalesReport::class)->summary($f, $f->period);
        self::assertSame(['0', '0', '0', '0', null], [$s['orders'], $s['units'], $s['gross'], $s['total'], $s['aov']]);
        self::assertCount(17, app(SalesReport::class)->series($f, $f->period));
        self::assertSame([], app(ProductReport::class)->ranking($f, $f->period)->get()->all());
        self::assertSame([], app(CategoryReport::class)->compute($f, $f->period, 'tree')['rows']);
        self::assertSame(0, app(CustomerReport::class)->summary($f, $f->period)['registered_buyers']);
        self::assertSame(0, app(SalesReport::class)->context($f, $f->period)['placed']);
        $page = app(ReportViews::class)->sales($f, 'paid_at', 'desc', 25);
        self::assertFalse($page['previous_has_data']);
        self::assertSame(0, $page['detail']['total']);
    }
}
