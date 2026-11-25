<?php

namespace Tests\Benchmark;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\ProductQueries;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\CategoryLanding;
use App\Storefront\Infrastructure\CategoryListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Category listing benchmark, outside the test suites: 100k products in 5 leaf categories of 20k, 40 brands, 5
 * filterable features (900k values) and reviews, in the test database's rolled-back transaction. Run it with
 * `docker compose run --rm --no-deps app php artisan test tests/Benchmark/CategoryListingBenchmarkTest.php`.
 */
final class CategoryListingBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_benchmark(): void
    {
        $this->withoutVite();
        Cache::flush();
        app()->setLocale('en');
        $locale = app(ProductQueries::class)->defaultId();
        $t0 = microtime(true);
        $cat = function (string $slug, ?int $parent) use ($locale): int {
            $c = CategoryRecord::create(['parent_id' => $parent, 'position' => 1, 'status' => 'active']);
            $c->translations()->create(['locale_id' => $locale, 'name' => ucfirst($slug), 'slug' => $slug]);

            return (int) $c->id;
        };
        $root = $cat('phones', null);
        $leaves = array_map(fn (int $n) => $cat('leaf'.$n, $root), range(1, 5));
        $brands = [];
        foreach (range(1, 40) as $n) {
            $brands[] = $id = DB::table('brands')->insertGetId(['code' => 'brand'.$n, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => $locale, 'name' => 'Brand '.$n]);
        }
        $group = DB::table('feature_groups')->where('system_key', 'general')->value('id');
        $feature = function (string $code, string $type, int $options) use ($group, $locale): array {
            $id = DB::table('features')->insertGetId(['position' => (int) DB::table('features')->max('position') + 1, 'code' => $code, 'type' => $type, 'filterable' => true, 'group_id' => $group]);
            DB::table('feature_translations')->insert(['feature_id' => $id, 'locale_id' => $locale, 'name' => $code]);
            $ids = [];
            foreach ($options > 0 ? range(1, $options) : [] as $n) {
                $ids[] = $o = DB::table('feature_options')->insertGetId(['feature_id' => $id, 'position' => $n]);
                DB::table('feature_option_translations')->insert(['option_id' => $o, 'locale_id' => $locale, 'label' => $code.' '.$n]);
            }

            return [$id, $ids];
        };
        [$color, $colors] = $feature('COLOR', 'single', 12);
        [$storage, $storages] = $feature('STORAGE', 'single', 6);
        [$bands, $bandOptions] = $feature('BANDS', 'multiple', 6);
        [$screen] = $feature('SCREEN', 'number', 0);
        [$wireless] = $feature('WIRELESS', 'boolean', 0);
        foreach (['products', 'product_translations', 'product_feature_assignments', 'product_feature_choices', 'product_reviews'] as $table) {
            DB::statement("ALTER TABLE $table DISABLE TRIGGER cms_audit_mutation");
        }
        $arr = fn (array $ids): string => 'ARRAY['.implode(',', $ids).']::bigint[]';
        DB::statement('INSERT INTO products (sku, price, quantity, category_id, status, brand_id, created_at, updated_at)
            SELECT \'BM-\' || g, ((g * 7919) % 200000) / 100.0, g % 4, ('.$arr($leaves).')[1 + g % 5], \'active\', ('.$arr($brands).')[1 + (g * 31) % 40], now() - (g || \' minutes\')::interval, now()
            FROM generate_series(1, 100000) g');
        DB::statement("INSERT INTO product_translations (product_id, locale_id, name, slug) SELECT id, $locale, 'Product ' || id, 'product-' || id FROM products");
        foreach ([[$color, 'single', $colors], [$storage, 'single', $storages], [$bands, 'multiple', $bandOptions]] as [$f, $type, $options]) {
            DB::statement("INSERT INTO product_feature_assignments (product_id, feature_id, type) SELECT id, $f, '$type' FROM products");
            $n = count($options);
            DB::statement("INSERT INTO product_feature_choices (assignment_id, feature_id, option_id, type) SELECT a.id, $f, (".$arr($options).")[1 + (a.product_id * 13) % $n], '$type' FROM product_feature_assignments a WHERE a.feature_id = $f");
            if ($type === 'multiple') {
                DB::statement("INSERT INTO product_feature_choices (assignment_id, feature_id, option_id, type) SELECT a.id, $f, (".$arr($options).")[1 + (a.product_id * 13 + 1) % $n], '$type' FROM product_feature_assignments a WHERE a.feature_id = $f");
            }
        }
        DB::statement("INSERT INTO product_feature_assignments (product_id, feature_id, type, number_value) SELECT id, $screen, 'number', 4 + (id % 30) / 10.0 FROM products");
        DB::statement("INSERT INTO product_feature_assignments (product_id, feature_id, type, boolean_value) SELECT id, $wireless, 'boolean', id % 2 = 0 FROM products");
        $customers = array_map(fn (int $n) => DB::table('customers')->insertGetId(['email' => "bm$n@example.test", 'first_name' => 'B', 'last_name' => 'M', 'created_at' => now(), 'updated_at' => now()]), range(1, 3));
        foreach ($customers as $i => $c) {
            DB::statement("INSERT INTO product_reviews (product_id, customer_id, rating, body, status, moderated_at, created_at, updated_at) SELECT id, $c, 1 + (id + $i) % 5, 'Text', 'approved', now(), now(), now() FROM products WHERE id % 5 = 0");
        }
        DB::statement('ANALYZE');
        fwrite(STDERR, sprintf("\nseeded in %.1fs: %d products, %d feature values\n", microtime(true) - $t0, DB::table('products')->count(), DB::table('product_feature_assignments')->count() + DB::table('product_feature_choices')->count()));

        $leafPath = '/phones/leaf1';
        $scenarios = [
            'leaf 20k, no filters' => [$leaves[0], $leafPath, ''],
            'leaf 20k, 2 brands + color' => [$leaves[0], $leafPath, 'brand=brand1,brand2&f'.$color.'='.$colors[0]],
            'leaf 20k, price + screen + in stock, sort price' => [$leaves[0], $leafPath, 'price_min=100&price_max=900&f'.$screen.'_min=5&in_stock=1&sort=price_asc'],
            'leaf 20k, newest' => [$leaves[0], $leafPath, 'sort=newest'],
            'leaf 20k, page 30 by rating' => [$leaves[0], $leafPath, 'sort=rating&page=30'],
            'root 100k, no filters' => [$root, '/phones', ''],
            'root 100k, brand + 2 colors + 5G + rating' => [$root, '/phones', 'brand=brand3&f'.$color.'='.$colors[1].','.$colors[2].'&f'.$bands.'='.$bandOptions[0].'&rating=3'],
        ];
        $listing = app(CategoryListing::class);
        $context = ['locale' => 'en', 'customer' => null];
        // The main content block's own defaults (24 per page, recommended order).
        $settings = app(StorefrontRegistry::class)->type('main_content')->validate([]);
        $p = fn (array $xs, float $q): float => $xs[(int) floor(($q / 100) * (count($xs) - 1))];
        foreach ($scenarios as $name => [$category, $path, $query]) {
            parse_str($query, $params);
            $data = app(CategoryLanding::class)->data((string) $category, ['locale' => 'en', 'customer' => null]);
            $facetTimes = [];
            $pageTimes = [];
            $httpTimes = [];
            $queries = 0;
            foreach (range(1, 12) as $i) {
                $a = microtime(true);
                $prepared = $listing->prepare(['category' => $data], ['category' => (string) $category], $params, $context, ['shopping' => true, 'delivery' => true]);
                $b = microtime(true);
                $result = $listing->results($prepared, $settings, $context);
                $c = microtime(true);
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->get($path.($query === '' ? '' : '?'.$query))->assertOk();
                DB::disableQueryLog();
                $queries = count(DB::getQueryLog());
                $d = microtime(true);
                // The first runs warm the caches.
                if ($i > 2) {
                    $facetTimes[] = ($b - $a) * 1000;
                    $pageTimes[] = ($c - $b) * 1000;
                    $httpTimes[] = ($d - $c) * 1000;
                }
            }
            sort($facetTimes);
            sort($pageTimes);
            sort($httpTimes);
            fwrite(STDERR, sprintf("%-48s total %6d  facets p50 %6.1f p95 %6.1f | page p50 %6.1f p95 %6.1f | http p50 %6.1f p95 %6.1f ms | %d queries\n", $name, $result['total'], $p($facetTimes, 50), $p($facetTimes, 95), $p($pageTimes, 50), $p($pageTimes, 95), $p($httpTimes, 50), $p($httpTimes, 95), $queries));
        }
        self::assertTrue(true);
    }
}
