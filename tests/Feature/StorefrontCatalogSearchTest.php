<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Search\Infrastructure\SearchIndex;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Application\Command\CatalogProduct;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Contract\CatalogUnavailable;
use App\Storefront\Application\Contract\StorefrontCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Text search through the real engine, like SearchTest. Skipped when Typesense is not running. */
final class StorefrontCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $alias;

    private int $en;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = @fsockopen((string) env('TYPESENSE_HOST', 'typesense'), 8108, $code, $message, 1);
        if ($socket === false) {
            $this->markTestSkipped('The Typesense service is not running (docker compose up -d typesense).');
        }
        fclose($socket);
        Cache::flush();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        DB::table('general_settings')->update(['storefront_open' => true]);
        app()->forgetInstance(GeneralSettings::class);
        app()->setLocale('en');
        $this->en = (int) DB::table('locales')->where('code', 'en')->value('id');
        $this->alias = 'test_'.strtolower(Str::random(10));
        config(['typesense.alias' => $this->alias, 'typesense.retries' => 0]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->alias)) {
                $http = (new Factory)->withHeaders(['X-TYPESENSE-API-KEY' => 'local-only-typesense'])->baseUrl('http://'.env('TYPESENSE_HOST', 'typesense').':8108');
                foreach ($http->get('/collections')->json() ?? [] as $collection) {
                    if (str_starts_with((string) $collection['name'], $this->alias.'_')) {
                        $http->delete('/collections/'.$collection['name']);
                    }
                }
                $http->delete('/aliases/'.$this->alias);
                $http->delete('/synonym_sets/'.$this->alias.'-synonyms');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function enableSearch(): void
    {
        $addons = app(AddonManager::class);
        $addons->installBundled(null, 'ambresoft/typesense');
        $addons->enable(null, 'ambresoft/typesense');
    }

    /** @param array<string, mixed> $attributes */
    private function category(string $name, ?CategoryRecord $parent = null, array $attributes = []): CategoryRecord
    {
        $category = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => (int) DB::table('categories')->where('parent_id', $parent?->id)->max('position') + 1, 'status' => 'active']);
        $category->forceFill($attributes)->save();
        $category->translations()->create(['locale_id' => $this->en, 'name' => $name, 'slug' => Str::slug($name).'-'.$category->id]);

        return $category;
    }

    /** @param array<string, mixed> $attributes */
    private function product(string $name, CategoryRecord $category, array $attributes = []): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'SKU-'.Str::upper(Str::random(6)), 'price' => '100', 'quantity' => 10, 'category_id' => $category->id, 'status' => 'active', ...$attributes]);
        $p->translations()->create(['locale_id' => $this->en, 'name' => $name, 'slug' => Str::slug($name).'-'.$p->id]);

        return $p;
    }

    private function build(): void
    {
        $run = app(SearchIndex::class)->startRebuild(null);
        self::assertSame('completed', DB::table('search_index_runs')->where('id', $run)->value('status'));
    }

    /** @return list<string> */
    private function names(CatalogQuery $query): array
    {
        return array_map(fn (CatalogProduct $p): string => $p->name, app(StorefrontCatalog::class)->searchProducts($query)->products);
    }

    public function test_text_search_is_ranked_by_the_engine_and_limited_to_public_products(): void
    {
        $this->enableSearch();
        $root = $this->category('Electronics');
        $phones = $this->category('Phones', $root);
        $mice = $this->category('Mice', $root);
        $quiet = $this->category('Quiet', null, ['exclude_from_discovery' => true]);
        $adult = $this->category('Adult', null, ['age_verification' => true, 'age_limit' => 18]);
        $this->product('Galaxy phone', $phones);
        $this->product('Galaxy mouse', $mice);
        $this->product('Galaxy empty', $mice, ['quantity' => 0]);
        $this->product('Galaxy hidden', $phones, ['status' => 'hidden']);
        $this->product('Galaxy disabled', $phones, ['status' => 'disabled']);
        $this->product('Galaxy quiet', $quiet);
        $this->product('Galaxy adult', $adult);
        $this->build();

        $found = $this->names(new CatalogQuery(text: 'galaxy'));
        sort($found);
        self::assertSame(['Galaxy empty', 'Galaxy mouse', 'Galaxy phone'], $found);
        self::assertSame(['Galaxy phone'], $this->names(new CatalogQuery(text: 'galaxy', categoryId: (string) $phones->id)));
        $withChildren = $this->names(new CatalogQuery(text: 'galaxy', categoryId: (string) $root->id, includeDescendants: true, inStockOnly: true));
        sort($withChildren);
        self::assertSame(['Galaxy mouse', 'Galaxy phone'], $withChildren);
        self::assertSame([], $this->names(new CatalogQuery(text: 'galaxy', categoryId: (string) $quiet->id)), 'asking for an excluded category finds nothing');
        self::assertSame([], $this->names(new CatalogQuery(text: 'nothing-like-this')));
    }

    public function test_a_stale_hit_never_ends_the_results_early(): void
    {
        $this->enableSearch();
        $phones = $this->category('Phones');
        $this->product('Galaxy older', $phones, ['created_at' => now()->subDays(3)]);
        $this->product('Galaxy old', $phones, ['created_at' => now()->subDays(2)]);
        $stale = $this->product('Galaxy stale', $phones, ['created_at' => now()->subDay()]);
        $this->build();
        // The index has not heard yet: the product stopped being public and no change is queued.
        DB::table('products')->where('id', $stale->id)->update(['status' => 'hidden']);
        DB::table('search_outbox')->delete();
        $catalog = app(StorefrontCatalog::class);

        $first = $catalog->searchProducts(new CatalogQuery(text: 'galaxy', sort: 'newest', page: 1, perPage: 1));

        self::assertSame([], $first->products, 'the stale hit is dropped, never shown');
        self::assertTrue($first->hasMore, 'an empty page is not the end');
        self::assertFalse($first->totalExact);
        self::assertSame(3, $first->total, 'the engine still counts it');
        $second = $catalog->searchProducts(new CatalogQuery(text: 'galaxy', sort: 'newest', page: 2, perPage: 1));
        self::assertSame(['Galaxy old'], array_map(fn (CatalogProduct $p): string => $p->name, $second->products));
        self::assertTrue($second->hasMore);
        $last = $catalog->searchProducts(new CatalogQuery(text: 'galaxy', sort: 'newest', page: 3, perPage: 1));
        self::assertSame(['Galaxy older'], array_map(fn (CatalogProduct $p): string => $p->name, $last->products));
        self::assertFalse($last->hasMore);
    }

    public function test_a_store_without_a_working_engine_says_so_instead_of_finding_nothing(): void
    {
        $this->product('Galaxy phone', $this->category('Phones'));
        $catalog = app(StorefrontCatalog::class);

        self::assertFalse($catalog->status()->searchAvailable);
        try {
            $catalog->searchProducts(new CatalogQuery(text: 'galaxy'));
            self::fail('Expected search to be unavailable');
        } catch (CatalogUnavailable $e) {
            self::assertSame('search', $e->reason);
        }
        self::assertCount(1, $catalog->searchProducts(new CatalogQuery)->products, 'browsing needs no engine');
    }
}
