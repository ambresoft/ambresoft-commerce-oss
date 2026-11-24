<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Command\VariationGroupData;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Application\Contract\VariationGroupAdministration;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** Two real PostgreSQL connections: duplicate combinations cannot both commit. */
final class VariationGroupConcurrencyTest extends TestCase
{
    use DatabaseMigrations, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        config(['database.connections.variation_race' => config('database.connections.pgsql')]);
        DB::connection('variation_race')->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', DB::connection('variation_race')->selectOne('select current_database() as name')->name);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('pgsql');
        DB::purge('variation_race');
        parent::tearDown();
    }

    public function test_concurrent_additions_of_the_same_combination_cannot_both_succeed(): void
    {
        $actor = (string) $this->staff(super: true)->id;
        $features = app(FeatureAdministration::class);
        $products = app(ProductAdministration::class);
        $groups = app(VariationGroupAdministration::class);
        $leaf = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $color = $features->save($actor, null, new SaveFeatureData(['type' => 'single', 'purpose' => 'variations', 'required' => false, 'filterable' => false, 'comparison' => false, 'categories' => [], 'translations' => ['en' => ['name' => 'Color']]]));
        $option = fn (string $label) => $features->option($actor, $color, null, new SaveFeatureData(['feature_version' => DB::table('features')->where('id', $color)->value('lock_version'), 'action' => 'save', 'translations' => ['en' => ['label' => $label]]]));
        $red = $option('Red');
        $white = $option('White');
        $product = function (string $value) use ($actor, $products, $color, $leaf): string {
            $id = $products->draft($actor);
            $products->save($actor, $id, new SaveProductData(1, ['sku' => 'SKU-'.$id, 'price' => '1', 'type' => 'physical', 'status' => 'disabled', 'category_id' => (string) $leaf->id, 'translations' => ['en' => ['name' => 'Shoe '.$id]], 'features' => [$color => ['version' => 0, 'options' => [$value]]], 'feature_catalog_version' => app(FeatureQueries::class)->revision()]));

            return $id;
        };
        $group = $groups->create($actor, new VariationGroupData(['product_id' => $product($red), 'product_version' => 2, 'features' => [$color]]));
        $first = $product($white);
        $second = $product($white);
        $add = fn (string $id) => $groups->add($actor, $group, new VariationGroupData(['version' => DB::table('variation_groups')->where('id', $group)->value('lock_version'), 'products' => [['id' => $id, 'version' => DB::table('products')->where('id', $id)->value('lock_version')]]]));

        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            // Connection A adds White and holds the Catalog lock until it commits.
            $add($first);
            DB::setDefaultConnection('variation_race');
            try {
                $add($second);
                self::fail('The competing addition must wait for the first transaction');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            } finally {
                DB::setDefaultConnection('pgsql');
            }
            // The unique index also refuses a duplicate signature written directly by another connection.
            try {
                DB::connection('variation_race')->table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $second, 'signature' => $color.':'.$white]);
                self::fail('A duplicate signature must not be insertable');
            } catch (QueryException $e) {
                self::assertContains($e->getCode(), ['55P03', '23505']);
            }
            $primary->commit();
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
        }
        // After A commits, B's retry fails cleanly and names the conflicting product.
        try {
            $add($second);
            self::fail('The losing addition must be rejected');
        } catch (ValidationException $e) {
            self::assertStringContainsString('SKU-'.$first, implode(' ', array_merge(...array_values($e->errors()))));
        }
        self::assertSame([$first], DB::table('variation_group_members')->where('signature', $color.':'.$white)->pluck('product_id')->map(strval(...))->all());
        self::assertNull(DB::table('variation_group_members')->where('product_id', $second)->value('group_id'));
    }
}
