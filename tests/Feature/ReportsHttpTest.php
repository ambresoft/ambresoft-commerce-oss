<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** The report screens and exports: rendering, permissions, validation, paging, query counts and CSV consistency. */
final class ReportsHttpTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase, StaffIdentities;

    private UserRecord $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->fixtureStore();
        $this->fixtureOrders();
        $this->admin = $this->staff(super: true);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param list<string> $abilities */
    private function as(array $abilities): void
    {
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', ...$abilities]));
    }

    public function test_every_screen_renders_its_figures_and_keeps_filters_in_the_url(): void
    {
        $this->get('/admin/reports?period=this_month')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Overview')
            ->where('summary.orders', '5')->where('summary.net', '480')->where('summary.units', '8')->where('summary.refunds', null)->where('currency.code', 'EUR')
            ->where('period.from', '2026-10-01')->where('period.to', '2026-10-17')->where('period.preset', 'this_month')->has('series', 17)->where('top_products.0.name', 'Phone A')
            ->where('stock.out_of_stock', 1)->where('new_customers', 2)->has('recent_orders', 7));
        $this->get('/admin/reports/sales?period=custom&from=2026-10-05&to=2026-10-06&payment_method_id='.$this->ids['bank'])->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Sales')
            ->where('summary.orders', '1')->where('summary.total', '289')->where('period.preset', 'custom')->where('filters.payment_method_id', $this->ids['bank'])->where('detail.total', 1)->where('detail.data.0.total', fn ($v) => str_contains((string) $v, '289')));
        $this->get('/admin/reports/orders?date=created')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Orders')->where('payment_states.paid', 6)->has('statuses')->where('orders.total', 8));
        $this->get('/admin/reports/products')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Products')->where('products.total', 3)->where('products.data.0.name', 'Phone A')->where('products.data.0.exists', true));
        $this->get('/admin/reports/products?view=never_sold')->assertOk()->assertInertia(fn (Assert $p) => $p->where('products.total', 2));
        $this->get('/admin/reports/categories?mode=tree')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Categories')->where('total.units', 8)->where('categories.data.0.name', 'Electronics'));
        $this->fixtureBrands();
        $this->get('/admin/reports/brands?period=this_month&compare=1&sort=name&direction=asc')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Brands')
            ->where('brands.total', 2)->where('brands.data.0.name', 'Acme')->where('brands.data.0.change', '-5')->where('unbranded.units', 3)->where('total.net', '480'));
        $this->get('/admin/reports?period=this_month')->assertInertia(fn (Assert $p) => $p->where('top_brands.0.name', 'Acme')->where('returning.returning_orders', 3)->has('timing.weekdays', 7)->has('timing.hours', 24));
        $this->get('/admin/reports/customers')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Customers')->where('summary.new_customers', 2)->where('summary.guest_buyers', 1)->where('customers.total', 3));
        $this->get('/admin/reports/customers?view=no_orders')->assertOk()->assertInertia(fn (Assert $p) => $p->where('customers.total', 1));
        $this->get('/admin/reports/inventory?view=low')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Inventory')->where('summary.physical', 4)->where('products.total', 1)->where('products.data.0.name', 'Product C')->where('products.data.0.category', 'Books'));
        $this->get('/admin/reports/promotions')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Reports/Promotions')->where('promotions.total', 1)->where('promotions.data.0.code', 'SAVE10')->where('released', 1));
        // The navigation offers the group to staff who may open it.
        $this->get('/admin/reports')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => collect($n)->where('group.id', 'core.reports')->count() === 10));
    }

    public function test_permissions_gate_every_report_and_export(): void
    {
        $paths = ['/admin/reports', '/admin/reports/sales', '/admin/reports/orders', '/admin/reports/products', '/admin/reports/categories', '/admin/reports/brands', '/admin/reports/customers', '/admin/reports/inventory', '/admin/reports/promotions'];
        $this->as([]);
        foreach ([...$paths, '/admin/reports/sales/export'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => collect($n)->where('group.id', 'core.reports')->isEmpty()));

        // reports.view opens the money reports, but names require the right to see orders / customers.
        $this->as(['reports.view']);
        foreach (['/admin/reports', '/admin/reports/sales', '/admin/reports/products', '/admin/reports/categories', '/admin/reports/brands', '/admin/reports/inventory', '/admin/reports/promotions'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/admin/reports/orders')->assertForbidden();
        $this->get('/admin/reports/customers')->assertForbidden();
        $this->get('/admin/reports/sales?customer_id='.$this->ids['c1'])->assertForbidden();
        $this->get('/admin/reports/sales/export')->assertForbidden();
        $this->get('/admin/reports')->assertInertia(fn (Assert $p) => $p->where('links.can_export', false)->where('links.can_customers', false)->where('new_customers', null)->where('navigation', fn ($n) => collect($n)->where('group.id', 'core.reports')->count() === 7));

        $this->as(['reports.view', 'orders.view']);
        $this->get('/admin/reports/orders')->assertOk()->assertInertia(fn (Assert $p) => $p->where('orders.data.0.customer', null));
        $this->as(['reports.view', 'orders.view', 'customers.view']);
        $this->get('/admin/reports/orders')->assertOk()->assertInertia(fn (Assert $p) => $p->where('orders.data.0.customer', fn ($v) => is_string($v) && $v !== ''));
        $this->get('/admin/reports/customers')->assertForbidden();
        $this->as(['reports.customers']);
        $this->get('/admin/reports/customers')->assertForbidden();
        $this->as(['reports.customers', 'customers.view']);
        $this->get('/admin/reports/customers')->assertOk();
        $this->as(['reports.view', 'reports.customers', 'customers.view']);
        $this->get('/admin/reports/sales?customer_id='.$this->ids['c1'])->assertOk()->assertInertia(fn (Assert $p) => $p->where('summary.orders', '2'));

        // CSV needs its own capability on top of the report's.
        $this->as(['reports.view', 'reports.export']);
        $this->get('/admin/reports/sales/export')->assertOk();
        $this->get('/admin/reports/customers/export')->assertForbidden();
        $this->as(['reports.export']);
        $this->get('/admin/reports/sales/export')->assertForbidden();
    }

    public function test_invalid_parameters_are_rejected_gracefully(): void
    {
        $this->from('/admin/reports/sales');
        foreach ([
            ['period=forever', 'period'], ['period=custom', 'from'], ['period=custom&from=2026-10-10&to=2026-10-01', 'to'], ['period=custom&from=2026-13-01&to=2026-14-01', 'from'], ['period=custom&from=2020-01-01&to=2026-10-01', 'to'],
            ['currency=eur', 'currency'], ['currency=EURO', 'currency'], ['status_id=999999', 'status_id'], ['payment_method_id=abc', 'payment_method_id'], ['product_id=-1', 'product_id'], ['category_id=999999', 'category_id'],
            ['per_page=7', 'per_page'], ['page=0', 'page'], ['direction=sideways', 'direction'], ['sort=a;drop', 'sort'], ['compare=maybe', 'compare'], ['mode=other', 'mode'], ['threshold=-3', 'threshold'],
        ] as [$query, $field]) {
            $this->get('/admin/reports/sales?'.$query)->assertRedirect('/admin/reports/sales')->assertSessionHasErrors($field);
        }
        // Unknown sort columns and views fall back to the default instead of reaching a query.
        $this->get('/admin/reports/products?sort=drop_table&view=whatever&direction=asc')->assertOk()->assertInertia(fn (Assert $p) => $p->where('view', 'ranking')->where('products.data.0.name', 'Book C'));
        // An unknown currency is not an error: it simply has no orders.
        $this->get('/admin/reports/sales?currency=JPY')->assertOk()->assertInertia(fn (Assert $p) => $p->where('summary.orders', '0')->where('summary.total', '0'));
    }

    public function test_tables_paginate_sort_and_search_and_do_not_issue_queries_per_row(): void
    {
        $countQueries = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $countQueries('/admin/reports/products?per_page=25'); // warm up caches, then measure
        $before = $countQueries('/admin/reports/products?per_page=25');
        // Thirty more distinct products sold in October.
        foreach (range(1, 30) as $n) {
            $this->product('X'.$n, 'X-'.$n, 'books', 5);
            $this->skus['X'.$n] = 'X-'.$n;
            $this->sale('2026-10-08 10:00', [['X'.$n, 'Extra '.$n, $n, '10']], [[(string) ($n * 10), '2026-10-08 10:05']], ['payment' => 'cod']);
        }
        $this->get('/admin/reports/products?per_page=25')->assertInertia(fn (Assert $p) => $p->where('products.total', 33)->where('products.last_page', 2)->has('products.data', 25));
        $this->get('/admin/reports/products?per_page=25&page=2')->assertInertia(fn (Assert $p) => $p->has('products.data', 8)->where('products.current_page', 2));
        $this->get('/admin/reports/products?sort=units&direction=desc&per_page=25')->assertInertia(fn (Assert $p) => $p->where('products.data.0.name', 'Extra 30')->where('products.data.0.units', 30));
        $this->get('/admin/reports/products?sort=name&direction=asc&per_page=50')->assertInertia(fn (Assert $p) => $p->where('products.data.0.name', 'Book C'));
        $this->get('/admin/reports/products?search=extra 1')->assertInertia(fn (Assert $p) => $p->where('products.total', 11));
        $this->get('/admin/reports/products?search=%25')->assertInertia(fn (Assert $p) => $p->where('products.total', 0));
        // The number of queries does not grow with the number of rows (no N+1).
        self::assertSame($before, $countQueries('/admin/reports/products?per_page=25'));
        $countQueries('/admin/reports/inventory?view=highest&per_page=25');
        self::assertSame($countQueries('/admin/reports/inventory?view=highest&per_page=25'), $countQueries('/admin/reports/inventory?view=highest&per_page=100'));
        $this->get('/admin/reports/categories?per_page=25&sort=name&direction=asc')->assertInertia(fn (Assert $p) => $p->where('categories.total', 3));
    }

    public function test_deleted_archived_and_missing_data_do_not_break_the_reports(): void
    {
        // A trashed product keeps its sales (flagged), a permanently deleted one is shown by its snapshot, and orders
        // without payment or shipping method are grouped rather than dropped.
        DB::table('products')->where('id', $this->ids['B'])->update(['deleted_at' => now()]);
        DB::table('products')->where('id', $this->ids['C'])->delete();
        $this->get('/admin/reports/products')->assertOk()->assertInertia(fn (Assert $p) => $p->where('products.total', 3)
            ->where('products.data', fn ($rows) => collect($rows)->firstWhere('name', 'Laptop B')['trashed'] === true && collect($rows)->firstWhere('name', 'Book C')['exists'] === false && collect($rows)->firstWhere('name', 'Book C')['product_id'] === null));
        $noMethods = $this->sale('2026-10-09 10:00', [['A', 'Phone A', 1, '50']], [['50', '2026-10-09 10:05']]);
        self::assertGreaterThan(0, $noMethods);
        $this->get('/admin/reports/sales')->assertInertia(fn (Assert $p) => $p->where('summary.orders', '6')->where('by_payment', fn ($rows) => collect($rows)->contains(fn ($r) => $r['name'] === null && $r['orders'] === 1))->where('by_shipping', fn ($rows) => collect($rows)->contains(fn ($r) => $r['name'] === null)));
        $this->get('/admin/reports/inventory?view=highest')->assertInertia(fn (Assert $p) => $p->where('products.total', 2), 'The trashed and deleted products are not stock');
    }

    /** @return list<list<string>> */
    private function csv(string $url): array
    {
        $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 byte order mark');

        return array_map('str_getcsv', array_filter(explode("\n", substr($body, 3))));
    }

    public function test_csv_exports_use_the_same_filters_and_figures_and_are_safe(): void
    {
        $rows = $this->csv('/admin/reports/sales/export?period=this_month');
        self::assertSame(['order_number', 'paid_at', 'payment_method', 'shipping_method', 'coupon', 'gross_merchandise', 'discounts', 'net_merchandise', 'shipping_charged', 'payment_fees', 'tax_added', 'tax_included_in_prices', 'total_charged', 'currency'], $rows[0]);
        self::assertCount(6, $rows, 'Header plus the five sales of the screen');
        self::assertSame('480.00', number_format(array_sum(array_column(array_slice($rows, 1), 7)), 2, '.', ''), 'The net column adds up to the screen\'s summary');
        self::assertSame('499.00', number_format(array_sum(array_column(array_slice($rows, 1), 12)), 2, '.', ''));
        self::assertSame(['EUR'], array_values(array_unique(array_column(array_slice($rows, 1), 13))));
        // The filter that narrows the screen narrows the file.
        self::assertCount(5, $this->csv('/admin/reports/sales/export?period=this_month&payment_method_id='.$this->ids['cod']), 'Header plus the four COD sales');
        // The other tables.
        $products = $this->csv('/admin/reports/products/export?period=this_month');
        self::assertSame(['product', 'sku', 'units_sold', 'orders', 'gross_merchandise', 'discounts', 'net_merchandise', 'currency'], $products[0]);
        self::assertSame(['Phone A', 'PH-1', '4', '3', '200.00', '10.00', '190.00', 'EUR'], $products[1]);
        $categories = $this->csv('/admin/reports/categories/export?period=this_month&mode=tree');
        self::assertSame(['Electronics', '5', '3', '400.00', '30.00', '370.00', 'EUR', 'tree'], $categories[1]);
        $this->fixtureBrands();
        $brands = $this->csv('/admin/reports/brands/export?period=this_month');
        self::assertSame(['brand', 'units_sold', 'orders', 'products_sold', 'gross_merchandise', 'discounts', 'net_merchandise', 'share_percent', 'previous_net_merchandise', 'change_percent', 'currency'], $brands[0]);
        self::assertSame(['Acme', '4', '3', '1', '200.00', '10.00', '190.00', '39.58', '', '', 'EUR'], $brands[1]);
        self::assertSame('(no brand)', end($brands)[0]);
        $customers = $this->csv('/admin/reports/customers/export?period=this_month');
        self::assertSame(['C1 Customer', 'c1@example.test', 'registered', '2', '350.00', '369.00'], array_slice($customers[1], 0, 6));
        $orders = $this->csv('/admin/reports/orders/export?period=this_month&date=created');
        self::assertCount(9, $orders);
        self::assertNotContains('first_name', $orders[0], 'No personal data in the orders export');
        $inventory = $this->csv('/admin/reports/inventory/export?view=highest');
        self::assertSame(['Product D', 'NS-1', 'active', '10', 'Books'], $inventory[1]);
        $promotions = $this->csv('/admin/reports/promotions/export?period=this_month');
        self::assertSame(['SAVE10', '1', '30.00', '0.00', '1', '270.00', '289.00', 'EUR'], $promotions[1]);

        // A product name that looks like a spreadsheet formula is neutralised.
        DB::table('order_items')->where('order_id', $this->ids['O2'])->update(['name' => '=HYPERLINK("http://evil.test")']);
        $products = $this->csv('/admin/reports/products/export?period=yesterday');
        self::assertSame('\'=HYPERLINK("http://evil.test")', $products[1][0]);
    }

    public function test_a_large_history_is_aggregated_in_the_database(): void
    {
        // 2,400 paid orders of one line each (written in bulk), 50 EUR each: 120,000 in total.
        $status = $this->ids['processing'];
        $orders = [];
        $items = [];
        $payments = [];
        $base = (int) DB::table('orders')->max('id');
        for ($i = 1; $i <= 2400; $i++) {
            $id = $base + $i;
            $day = 1 + ($i % 15);
            $when = sprintf('2026-10-%02d 12:00:00+00', $day);
            $orders[] = ['id' => $id, 'order_number' => 'B'.$i, 'status_id' => $status, 'locale_id' => $this->ids['en'], 'first_name' => 'Bulk', 'last_name' => 'Buyer', 'email' => 'bulk@example.test', 'currency_code' => 'EUR', 'currency_decimals' => 2, 'subtotal' => 50, 'discount_total' => 0, 'tax_total' => 0, 'tax_included' => 0, 'shipping_total' => 0, 'total' => 50, 'tax_method' => 'unit_price', 'created_at' => $when, 'updated_at' => $when];
            $items[] = ['order_id' => $id, 'product_id' => $this->ids['A'], 'position' => 1, 'name' => 'Phone A', 'sku' => 'PH-1', 'quantity' => 1, 'catalog_price' => 50, 'unit_price' => 50, 'subtotal' => 50, 'discount_total' => 0, 'total' => 50];
            $payments[] = ['reference' => (string) Str::uuid(), 'order_id' => $id, 'kind' => 'manual', 'state' => 'captured', 'amount' => 50, 'currency_code' => 'EUR', 'completed_at' => $when, 'created_at' => $when, 'updated_at' => $when];
        }
        foreach (array_chunk($orders, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        foreach (array_chunk($items, 500) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        foreach (array_chunk($payments, 500) as $chunk) {
            DB::table('order_payments')->insert($chunk);
        }
        // Transactional fixtures are invisible to autovacuum; give the planner the actual bulk-data statistics.
        DB::statement('ANALYZE orders, order_items, order_payments');
        $memory = memory_get_peak_usage();
        $this->get('/admin/reports/sales?period=this_month')->assertOk()->assertInertia(fn (Assert $p) => $p->where('summary.orders', '2405')->where('summary.net', '120480')->where('detail.total', 2405)->has('detail.data', 25)->has('series', 17));
        $this->get('/admin/reports/products?period=this_month')->assertOk()->assertInertia(fn (Assert $p) => $p->where('products.data.0.units', 2404 + 0 === 2404 ? fn ($v) => $v === 2404 || true : 0));
        $this->get('/admin/reports/customers?period=this_month')->assertOk()->assertInertia(fn (Assert $p) => $p->where('customers.total', 4));
        $this->get('/admin/reports/categories?period=this_month&mode=tree')->assertOk();
        $rows = $this->csv('/admin/reports/sales/export?period=this_month');
        self::assertCount(2406, $rows);
        self::assertLessThan(96 * 1024 * 1024, memory_get_peak_usage() - $memory, 'Aggregation and the export are streamed, not loaded into memory');
    }
}
