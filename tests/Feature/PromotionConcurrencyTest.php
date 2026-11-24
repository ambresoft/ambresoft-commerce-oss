<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Order\Infrastructure\OrderAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PromotionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_checkout_submission_uniqueness_serializes_concurrent_inserts(): void
    {
        config(['database.connections.submission_race' => config('database.connections.pgsql')]);
        $race = DB::connection('submission_race');
        $race->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', $race->selectOne('select current_database() as name')->name);
        $product = ProductRecord::create(['price' => '100', 'quantity' => 100]);
        $id = app(OrderAdministration::class)->create(null, ['locale' => 'en', 'first_name' => 'Guest', 'last_name' => 'Test', 'email' => 'guest@example.test', 'billing' => ['first_name' => 'Guest', 'last_name' => 'Test', 'line1' => 'Street 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], 'shipping_same' => true, 'items' => [['product_id' => (string) $product->id, 'quantity' => 1]], 'status_id' => (string) DB::table('order_statuses')->where('code', 'open')->value('id')]);
        $copy = (array) DB::table('orders')->where('id', $id)->sole();
        unset($copy['id']);
        $copy['order_number'] = '99999999';
        $copy['submission_key'] = hash('sha256', 'one checkout submission');
        $copy['submission_fingerprint'] = hash('sha256', 'same original inputs');
        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            $primary->table('orders')->where('id', $id)->update(['submission_key' => $copy['submission_key'], 'submission_fingerprint' => $copy['submission_fingerprint']]);
            try {
                $race->table('orders')->insert($copy);
                self::fail('A competing submission must wait for the original transaction');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            }
            $primary->commit();
            try {
                $race->table('orders')->insert($copy);
                self::fail('A committed submission cannot be inserted again');
            } catch (QueryException $e) {
                self::assertSame('23505', $e->getCode());
            }
            self::assertSame(1, DB::table('orders')->count());
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('submission_race');
        }
    }
}
