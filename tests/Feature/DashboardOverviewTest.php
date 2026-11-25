<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    private function order(string $number, string $status, string $total, string $createdAt, string $currency = 'EUR'): int
    {
        return DB::table('orders')->insertGetId([
            'order_number' => $number, 'status_id' => DB::table('order_statuses')->where('code', $status)->value('id'),
            'locale_id' => DB::table('locales')->value('id'), 'first_name' => 'Maria', 'last_name' => 'Test', 'email' => strtolower($number).'@example.test',
            'currency_code' => $currency, 'currency_decimals' => 2, 'subtotal' => $total, 'total' => $total, 'tax_method' => 'unit_price',
            'stock_held' => in_array($status, ['processing', 'completed'], true), 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    public function test_staff_without_data_capabilities_receive_no_business_data(): void
    {
        $this->order('T-1', 'open', '10.00', now()->subHour()->toDateTimeString());
        $this->actingAs($this->staff(['admin.access']))->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('overview.totals', ['categories' => null, 'brands' => null, 'pages' => null])->where('overview.orders', null)->where('overview.catalog', null)->where('overview.customers', null)->where('overview.activity', []));
    }

    public function test_extra_totals_only_include_authorized_entities_and_exclude_the_system_category(): void
    {
        DB::table('categories')->insert(['position' => 1, 'status' => 'active']);
        $count = DB::table('categories')->whereNull('system_key')->count();
        $this->actingAs($this->staff(['admin.access', 'categories.view']))->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('overview.totals.categories', $count)->where('overview.totals.brands', null)->where('overview.totals.pages', null));
    }

    public function test_orders_section_requires_the_orders_capability_only(): void
    {
        $this->order('T-1', 'open', '10.00', now()->subHour()->toDateTimeString());
        $this->actingAs($this->staff(['admin.access', 'orders.view']))->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('overview.orders.recent', 1)->where('overview.catalog', null)->where('overview.customers', null)->has('overview.activity', 0));
    }

    public function test_sales_count_only_stock_holding_primary_currency_orders_and_period_buckets_are_aligned(): void
    {
        $this->order('T-1', 'processing', '100.00', now()->subHours(2)->toDateTimeString());
        $this->order('T-2', 'completed', '50.50', now()->subDays(3)->toDateTimeString());
        $this->order('T-3', 'open', '999.00', now()->subHours(2)->toDateTimeString());
        $this->order('T-4', 'cancelled', '777.00', now()->subHours(2)->toDateTimeString());
        $this->order('T-5', 'processing', '555.00', now()->subHours(2)->toDateTimeString(), 'USD');
        $this->actingAs($this->staff(['admin.access', 'orders.view']))->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('overview.orders.ranges.day.current', 24)->has('overview.orders.ranges.week.current', 7)->has('overview.orders.ranges.month.previous', 30)
            ->where('overview.orders.ranges.month.count', 5)->where('overview.orders.ranges.day.count', 4)->where('overview.orders.open', 1)
            ->where('overview.orders.ranges.week.current', fn ($series) => round(collect($series)->sum(), 2) === 150.5)
            ->where('overview.orders.ranges.day.current', fn ($series) => round(collect($series)->sum(), 2) === 100.0)
            ->where('overview.orders.ranges.day.previous', fn ($series) => collect($series)->sum() === 0));
    }
}
