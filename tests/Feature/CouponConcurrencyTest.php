<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Order\Infrastructure\OrderAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CouponConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_last_allowance_is_serialized_and_cannot_be_redeemed_twice(): void
    {
        config(['database.connections.coupon_race' => config('database.connections.pgsql')]);
        $race = DB::connection('coupon_race');
        $race->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', $race->selectOne('select current_database() as name')->name);
        $p = ProductRecord::create(['price' => '10', 'quantity' => 100]);
        $orders = [];
        for ($i = 0; $i < 2; $i++) {
            $orders[] = app(OrderAdministration::class)->create(null, ['locale' => 'en', 'first_name' => 'Coupon', 'last_name' => 'Test', 'email' => 'test@example.test', 'billing' => ['first_name' => 'Coupon', 'last_name' => 'Test', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], 'shipping_same' => true, 'items' => [['product_id' => (string) $p->id, 'quantity' => 1]], 'status_id' => (string) DB::table('order_statuses')->where('code', 'open')->value('id')]);
        }
        $coupon = DB::table('coupons')->insertGetId(['name' => 'Last slot', 'code' => 'LAST', 'type' => 'percentage', 'amount' => '10', 'total_limit' => 1]);
        $row = ['coupon_id' => $coupon, 'customer_key' => hash('sha256', 'test@example.test'), 'code' => 'LAST', 'discount' => '1', 'shipping_discount' => '0', 'currency_code' => 'EUR', 'currency_decimals' => 2, 'created_at' => now()];
        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            $primary->table('coupon_redemptions')->insert([...$row, 'order_id' => $orders[0]]);
            try {
                $race->table('coupon_redemptions')->insert([...$row, 'order_id' => $orders[1]]);
                self::fail('The second transaction must wait');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            }
            $primary->commit();
            try {
                $race->table('coupon_redemptions')->insert([...$row, 'order_id' => $orders[1]]);
                self::fail('The committed use exhausts the limit');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
            self::assertSame(1, DB::table('coupon_redemptions')->count());
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('coupon_race');
            // This scenario uses direct rows to exercise the constraint; remove its fixtures before rollback.
            DB::table('coupon_redemptions')->delete();
        }
    }
}
