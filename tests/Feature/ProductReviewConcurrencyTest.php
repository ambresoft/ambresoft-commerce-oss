<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\ProductReviews;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProductReviewConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /** @return array{0: string, 1: string} */
    private function fixture(): array
    {
        $product = (string) DB::table('products')->insertGetId(['type' => 'physical', 'sku' => 'RACE-1', 'status' => 'active', 'price' => 10, 'quantity' => 1, 'out_of_stock_actions' => 'N', 'created_at' => now(), 'updated_at' => now()]);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'race@example.test', 'first_name' => 'Race', 'last_name' => 'Customer', 'created_at' => now(), 'updated_at' => now()]);

        return [$product, $customer];
    }

    public function test_a_competing_insert_waits_for_the_first_submission_and_then_fails(): void
    {
        [$product, $customer] = $this->fixture();
        config(['database.connections.review_race' => config('database.connections.pgsql')]);
        $race = DB::connection('review_race');
        $race->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', $race->selectOne('select current_database() as name')->name);
        $row = ['product_id' => $product, 'customer_id' => $customer, 'rating' => 4, 'body' => 'Second tab', 'created_at' => now(), 'updated_at' => now()];
        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            $primary->table('product_reviews')->insert([...$row, 'body' => 'First tab']);
            try {
                $race->table('product_reviews')->insert($row);
                self::fail('A competing review must wait for the first transaction');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            }
            $primary->commit();
            try {
                $race->table('product_reviews')->insert($row);
                self::fail('A second review for the same customer and product was stored');
            } catch (QueryException $e) {
                self::assertSame('23505', $e->getCode());
            }
            self::assertSame(['First tab'], DB::table('product_reviews')->pluck('body')->all());
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('review_race');
        }
    }

    public function test_submission_that_loses_the_race_reports_the_existing_review(): void
    {
        [$product, $customer] = $this->fixture();
        config(['database.connections.review_race' => config('database.connections.pgsql')]);
        $race = DB::connection('review_race');
        $committed = false;
        // The competing request commits after this submission's duplicate check but before its insert.
        DB::connection('pgsql')->beforeExecuting(function (string $query) use ($race, $product, $customer, &$committed): void {
            if (! $committed && str_starts_with($query, 'insert into "product_reviews"')) {
                $committed = true;
                $race->table('product_reviews')->insert(['product_id' => $product, 'customer_id' => $customer, 'rating' => 5, 'body' => 'Other tab', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        try {
            app(ProductReviews::class)->submit($customer, $product, ['rating' => 1, 'body' => 'This tab']);
            self::fail('The losing submission created a duplicate');
        } catch (ValidationException $e) {
            self::assertSame([__('reviews.duplicate')], $e->errors()['product']);
        } finally {
            DB::purge('review_race');
        }
        self::assertTrue($committed);
        self::assertSame(['Other tab'], DB::table('product_reviews')->pluck('body')->all());
    }
}
