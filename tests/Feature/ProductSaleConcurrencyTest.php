<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Order\Infrastructure\OrderAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProductSaleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_reservations_wait_then_cannot_exceed_the_sale_stock(): void
    {
        config(['database.connections.sale_race' => config('database.connections.pgsql')]);
        $race = DB::connection('sale_race');
        $race->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', $race->selectOne('select current_database() as name')->name);
        $product = ProductRecord::create(['price' => '100', 'quantity' => 100]);
        $items = [];
        for ($n = 0; $n < 2; $n++) {
            $order = app(OrderAdministration::class)->create(null, ['locale' => 'en', 'first_name' => 'Sale', 'last_name' => 'Test', 'email' => 'sale@example.test', 'billing' => ['first_name' => 'Sale', 'last_name' => 'Test', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], 'shipping_same' => true, 'items' => [['product_id' => (string) $product->id, 'quantity' => 1]], 'status_id' => (string) DB::table('order_statuses')->where('code', 'open')->value('id')]);
            $items[] = DB::table('order_items')->where('order_id', $order)->value('id');
        }
        DB::table('customer_groups')->insert(['status' => 'active', 'is_default' => true]);
        $sale = DB::table('product_sales')->insertGetId(['product_id' => $product->id, 'customer_group_id' => DB::table('customer_groups')->where('is_default', true)->value('id'), 'kind' => 'fixed', 'amount' => '70', 'stock_limit' => 1]);
        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            $primary->table('product_sale_allocations')->insert(['item_id' => $items[0], 'sale_id' => $sale, 'quantity' => 1]);
            try {
                $race->table('product_sale_allocations')->insert(['item_id' => $items[1], 'sale_id' => $sale, 'quantity' => 1]);
                self::fail('A concurrent reservation must wait for the quota lock');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            }
            $primary->commit();
            try {
                $race->table('product_sale_allocations')->insert(['item_id' => $items[1], 'sale_id' => $sale, 'quantity' => 1]);
                self::fail('The committed reservation must prevent another discounted unit');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
            self::assertSame(1, (int) DB::table('product_sale_allocations')->where('sale_id', $sale)->sum('quantity'));
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('sale_race');
        }
    }
}
