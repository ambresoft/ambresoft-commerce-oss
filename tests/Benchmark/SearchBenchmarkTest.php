<?php

namespace Tests\Benchmark;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\ProductQueries;
use App\Search\Infrastructure\SearchIndex;
use App\Search\Infrastructure\SearchSettings;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Infrastructure\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Search benchmark, outside the test suites: BENCH_PRODUCTS (default 100k) products in 10 leaf categories, 40 brands,
 * a 12-option colour, a 6-option storage and a numeric screen size, built into a Typesense collection of its own; then
 * end-to-end storefront searches (validation, visibility, the engine, hydration of the cards) and the same searches
 * while another collection is being bulk-imported. Requires the Compose `typesense` service:
 * `docker compose up -d typesense && docker compose run --rm --no-deps app php artisan test tests/Benchmark/SearchBenchmarkTest.php`.
 */
final class SearchBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private string $alias;

    protected function tearDown(): void
    {
        try {
            $http = (new Factory)->withHeaders(['X-TYPESENSE-API-KEY' => 'local-only-typesense'])->baseUrl('http://typesense:8108');
            foreach ($http->get('/collections')->json() ?? [] as $c) {
                if (str_starts_with((string) $c['name'], $this->alias.'_') || $c['name'] === $this->alias.'-load') {
                    $http->delete('/collections/'.$c['name']);
                }
            }
            $http->delete('/aliases/'.$this->alias);
            $http->delete('/synonym_sets/'.$this->alias.'-synonyms');
        } finally {
            parent::tearDown();
        }
    }

    public function test_benchmark(): void
    {
        $this->withoutVite();
        Cache::flush();
        app()->setLocale('en');
        $count = (int) (getenv('BENCH_PRODUCTS') ?: 100000);
        $addons = app(AddonManager::class);
        $addons->installBundled(null, 'ambresoft/typesense');
        $addons->enable(null, 'ambresoft/typesense');
        $this->alias = 'bench_'.strtolower(Str::random(6));
        config(['typesense.alias' => $this->alias]);
        $locale = app(ProductQueries::class)->defaultId();

        $root = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
        $root->translations()->create(['locale_id' => $locale, 'name' => 'Electronics', 'slug' => 'electronics']);
        $leaves = [];
        foreach (range(1, 10) as $n) {
            $leaf = CategoryRecord::create(['parent_id' => $root->id, 'position' => $n, 'status' => 'active']);
            $leaf->translations()->create(['locale_id' => $locale, 'name' => 'Category '.$n, 'slug' => 'category-'.$n]);
            $leaves[] = (int) $leaf->id;
        }
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
                DB::table('feature_option_translations')->insert(['option_id' => $o, 'locale_id' => $locale, 'label' => strtolower($code).' '.$n]);
            }

            return [$id, $ids];
        };
        [$colour, $colours] = $feature('COLOUR', 'single', 12);
        [$storage, $storages] = $feature('STORAGE', 'single', 6);
        [$screen] = $feature('SCREEN', 'number', 0);
        $arr = fn (array $ids): string => 'ARRAY['.implode(',', $ids).']::bigint[]';
        // Catalog triggers stay on (they queue every product, as an import would); audit capture is not measured here.
        foreach (['products', 'product_translations', 'product_feature_assignments', 'product_feature_choices'] as $table) {
            DB::statement("ALTER TABLE $table DISABLE TRIGGER cms_audit_mutation");
        }
        DB::statement('INSERT INTO products (sku, price, quantity, category_id, status, brand_id, created_at, updated_at)
            SELECT \'BM-\' || g, ((g * 7919) % 200000) / 100.0, g % 4, ('.$arr($leaves).')[1 + g % 10], \'active\', ('.$arr($brands).')[1 + (g * 31) % 40], now() - (g || \' minutes\')::interval, now()
            FROM generate_series(1, '.$count.') g');
        DB::statement("INSERT INTO product_translations (product_id, locale_id, name, short_description, slug) SELECT id, $locale, 'Product ' || id || ' ' || (ARRAY['phone','tablet','laptop','camera','speaker'])[1 + id % 5], 'A dependable ' || (ARRAY['phone','tablet','laptop','camera','speaker'])[1 + id % 5] || ' for everyday use.', 'product-' || id FROM products");
        foreach ([[$colour, $colours], [$storage, $storages]] as [$f, $options]) {
            DB::statement("INSERT INTO product_feature_assignments (product_id, feature_id, type) SELECT id, $f, 'single' FROM products");
            $n = count($options);
            DB::statement("INSERT INTO product_feature_choices (assignment_id, feature_id, option_id, type) SELECT a.id, $f, (".$arr($options).")[1 + (a.product_id * 13) % $n], 'single' FROM product_feature_assignments a WHERE a.feature_id = $f");
        }
        DB::statement("INSERT INTO product_feature_assignments (product_id, feature_id, type, number_value) SELECT id, $screen, 'number', 4 + (id % 30) / 10.0 FROM products");
        $report = [sprintf('Catalog: %d products, %d queued by the catalog triggers', $count, DB::table('search_outbox')->count())];

        $t = microtime(true);
        $run = app(SearchIndex::class)->startRebuild(null);
        $build = microtime(true) - $t;
        $row = DB::table('search_index_runs')->where('id', $run)->first();
        self::assertSame('completed', $row->status, (string) $row->message);
        $report[] = sprintf('Full build: %d documents in %.1f s (%d documents/s, batches of %d, queued changes included)', $row->indexed, $build, (int) ($row->indexed / max($build, 0.001)), SearchIndex::BATCH);

        $search = app(ProductSearch::class);
        $context = ['locale' => 'en', 'customer' => null];
        $settings = ['default_sort' => 'recommended', 'products_per_page' => 24];
        $cases = [
            'exact SKU' => ['q' => 'BM-4242'],
            'exact name' => ['q' => 'Product 4242 tablet'],
            'typo' => ['q' => 'Prodcut 4242'],
            'multiword' => ['q' => 'brand 7 laptop'],
            'broad + facets' => ['q' => 'phone'],
            // Phones (ID multiple of 5) carry brands 1, 6, 11…; colour index and storage index agree modulo 6.
            'brand filter' => ['q' => 'phone', 'brand' => 'brand6'],
            'feature filters' => ['q' => 'laptop', 'f'.$colour => (string) $colours[1].','.(string) $colours[7], 'f'.$storage => (string) $storages[1]],
            'price range' => ['q' => 'camera', 'price_min' => '100', 'price_max' => '500'],
        ];
        $measure = function (callable $call, int $runs = 25): array {
            $call();
            $times = [];
            for ($i = 0; $i < $runs; $i++) {
                $t = hrtime(true);
                $call();
                $times[] = (hrtime(true) - $t) / 1e6;
            }
            sort($times);

            return [$times[(int) floor(0.5 * ($runs - 1))], $times[(int) floor(0.95 * ($runs - 1))]];
        };
        $line = fn (string $name, array $ms, string $extra = ''): string => sprintf('%-18s p50 %6.1f ms  p95 %6.1f ms %s', $name, $ms[0], $ms[1], $extra);
        $report[] = 'Full results page (validation, visibility, engine with disjunctive facets, card hydration):';
        foreach ($cases as $name => $query) {
            $listing = $search->listing($query, $context, ['delivery' => true, 'rating' => true, 'stock' => true], $settings);
            self::assertSame('results', $listing['page']['search']['state']);
            $report[] = $line($name, $measure(fn () => $search->listing($query, $context, ['delivery' => true, 'rating' => true, 'stock' => true], $settings)), '('.$listing['facets']['total'].' found)');
        }
        $report[] = 'Header suggestions (prefix, 6 cards):';
        foreach (['ph', 'prod', 'BM-42', 'lapt'] as $prefix) {
            $report[] = $line('"'.$prefix.'"', $measure(fn () => $search->suggest($prefix, $context, 6)), '('.$search->suggest($prefix, $context, 6)['total'].' found)');
        }

        // The same searches while 20,000 documents are bulk-imported into another collection of the same server.
        $http = (new Factory)->withHeaders(['X-TYPESENSE-API-KEY' => 'local-only-typesense'])->baseUrl('http://typesense:8108');
        $http->post('/collections', ['name' => $this->alias.'-load', 'fields' => [['name' => 'title', 'type' => 'string'], ['name' => 'n', 'type' => 'int64', 'facet' => true]]]);
        $file = tempnam(sys_get_temp_dir(), 'load');
        $handle = fopen((string) $file, 'w');
        for ($i = 1; $i <= 20000; $i++) {
            fwrite($handle, json_encode(['id' => (string) $i, 'title' => 'load document number '.$i.' '.str_repeat('lorem ipsum ', 20), 'n' => $i])."\n");
        }
        fclose($handle);
        $process = proc_open(['curl', '-s', '-o', '/dev/null', '-H', 'X-TYPESENSE-API-KEY: local-only-typesense', '--data-binary', '@'.$file, 'http://typesense:8108/collections/'.$this->alias.'-load/documents/import?action=create'], [], $pipes);
        $report[] = 'While another collection is bulk-imported (20,000 documents):';
        foreach (['exact SKU', 'broad + facets', 'feature filters'] as $name) {
            $report[] = $line($name, $measure(fn () => $search->listing($cases[$name], $context, ['delivery' => true, 'rating' => true, 'stock' => true], $settings), 15));
        }
        $report[] = $line('suggest "prod"', $measure(fn () => $search->suggest('prod', $context, 6), 15));
        $status = proc_get_status($process);
        $report[] = $status['running'] ? '(the import was still running at the end of these measurements)' : '(the import finished during these measurements)';
        proc_close($process);
        @unlink((string) $file);

        $provider = app(SearchSettings::class)->provider();
        $report[] = sprintf('Engine-side index size: %d documents in %s', $provider?->health([], ['en'])->documents ?? 0, (string) $provider?->health([], ['en'])->index);
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $report).PHP_EOL);
    }
}
