<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Order\Infrastructure\OrderAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** Two real PostgreSQL connections: concurrent status changes cannot oversell, and order numbers never collide. */
final class OrderConcurrencyTest extends TestCase
{
    use DatabaseMigrations, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        config(['database.connections.order_race' => config('database.connections.pgsql')]);
        DB::connection('order_race')->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', DB::connection('order_race')->selectOne('select current_database() as name')->name);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('pgsql');
        DB::purge('order_race');
        parent::tearDown();
    }

    public function test_concurrent_stock_taking_transitions_serialize_on_the_product_row_and_never_oversell(): void
    {
        $actor = (string) $this->staff(super: true)->id;
        $orders = app(OrderAdministration::class);
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $product = ProductRecord::create(['sku' => 'RACE', 'price' => '10', 'quantity' => 3, 'category_id' => $category->id, 'status' => 'active']);
        $product->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Race', 'slug' => 'race']);
        $payload = fn (): array => ['locale' => 'en', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test', 'shipping_same' => true,
            'billing' => ['first_name' => 'A', 'last_name' => 'B', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'],
            'items' => [['product_id' => (string) $product->id, 'quantity' => 2]], 'status_id' => (string) DB::table('order_statuses')->where('code', 'open')->value('id')];
        $first = $orders->create($actor, $payload());
        $second = $orders->create($actor, $payload());
        self::assertNotSame(DB::table('orders')->where('id', $first)->value('order_number'), DB::table('orders')->where('id', $second)->value('order_number'));
        $processing = (string) DB::table('order_statuses')->where('code', 'processing')->value('id');

        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            // Connection A takes 2 units and holds the product row lock until it commits.
            $orders->changeStatus($actor, $first, null, $processing);
            DB::setDefaultConnection('order_race');
            try {
                $orders->changeStatus($actor, $second, null, $processing);
                self::fail('The competing transition must wait for the first transaction');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            } finally {
                DB::setDefaultConnection('pgsql');
            }
            $primary->commit();
        } catch (\Throwable $e) {
            $primary->rollBack();
            throw $e;
        }
        self::assertSame(1, $product->fresh()->quantity);
        // After the first commit, the second sees the real stock and is refused instead of overselling.
        try {
            $orders->changeStatus($actor, $second, null, $processing);
            self::fail('Only one unit is left for a two-unit order');
        } catch (ValidationException) {
        }
        self::assertSame(1, $product->fresh()->quantity);
        self::assertSame('open', DB::table('orders as o')->join('order_statuses as s', 's.id', '=', 'o.status_id')->where('o.id', $second)->value('s.code'));
        // Sequence values handed to different connections never collide.
        self::assertNotEquals(DB::selectOne("SELECT nextval('order_number_seq') AS n")->n, DB::connection('order_race')->selectOne("SELECT nextval('order_number_seq') AS n")->n);
    }
}
