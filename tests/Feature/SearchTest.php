<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Search\Application\Contract\SearchProvider;
use App\Search\Infrastructure\SearchIndex;
use App\Search\Infrastructure\SearchRecovery;
use App\Search\Infrastructure\SearchSettings;
use App\Search\Infrastructure\SearchTerms;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/**
 * Product search through the bundled Typesense add-on against a real Typesense server (the Compose `typesense`
 * service), each test in collections of its own. Skipped when the server is not running.
 */
final class SearchTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private string $alias;

    private CategoryRecord $phones;

    private CategoryRecord $mice;

    private int $en;

    private int $el;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = @fsockopen((string) env('TYPESENSE_HOST', 'typesense'), 8108, $code, $message, 1);
        if ($socket === false) {
            $this->markTestSkipped('The Typesense service is not running (docker compose up -d typesense).');
        }
        fclose($socket);
        $this->withoutVite();
        Cache::flush();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        DB::table('locales')->where('code', 'el')->update(['is_active' => true]);
        app()->setLocale('en');
        $this->en = (int) DB::table('locales')->where('code', 'en')->value('id');
        $this->el = (int) DB::table('locales')->where('code', 'el')->value('id');
        $addons = app(AddonManager::class);
        $addons->installBundled(null, 'ambresoft/typesense');
        $addons->enable(null, 'ambresoft/typesense');
        $this->alias = 'test_'.strtolower(Str::random(10));
        config(['typesense.alias' => $this->alias, 'typesense.retries' => 0]);
        $root = $this->category('Electronics');
        $this->phones = $this->category('Phones', $root);
        $this->mice = $this->category('Mice', $root);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->alias)) {
                // A client of its own: a test may have faked the HTTP facade.
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

    private function category(string $name, ?CategoryRecord $parent = null): CategoryRecord
    {
        $category = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => (int) DB::table('categories')->where('parent_id', $parent?->id)->max('position') + 1, 'status' => 'active']);
        $category->translations()->create(['locale_id' => $this->en, 'name' => $name, 'slug' => Str::slug($name).'-'.$category->id]);

        return $category;
    }

    /** @param array<string, mixed> $attributes
     * @param array<int, int> $choices Feature ID => option ID */
    private function product(string $en, ?string $el, CategoryRecord $category, array $attributes = [], array $choices = []): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'SKU-'.Str::upper(Str::random(6)), 'price' => '100', 'quantity' => 10, 'category_id' => $category->id, 'status' => 'active', ...$attributes]);
        $p->translations()->create(['locale_id' => $this->en, 'name' => $en, 'slug' => Str::slug($en).'-'.$p->id]);
        if ($el !== null) {
            $p->translations()->create(['locale_id' => $this->el, 'name' => $el, 'slug' => 'el-'.$p->id]);
        }
        foreach ($choices as $feature => $option) {
            $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $p->id, 'feature_id' => $feature, 'type' => 'single', 'is_applicable' => true]);
            DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $feature, 'option_id' => $option, 'type' => 'single']);
        }

        return $p;
    }

    private function build(): void
    {
        $run = app(SearchIndex::class)->startRebuild(null);
        self::assertSame('completed', DB::table('search_index_runs')->where('id', $run)->value('status'), (string) DB::table('search_index_runs')->where('id', $run)->value('message'));
    }

    /** @return list<string> Names the suggestions show, in order */
    private function suggest(string $q, string $locale = 'en'): array
    {
        app()->setLocale($locale);
        $result = app(ProductSearch::class)->suggest($q, ['locale' => $locale, 'customer' => null], 10);
        self::assertSame('results', $result['state']);

        return array_column($result['items'], 'name');
    }

    /** @param array<string, string> $query
     * @return array<string, mixed> */
    private function listing(array $query, ?string $customer = null): array
    {
        return app(ProductSearch::class)->listing($query, ['locale' => 'en', 'customer' => $customer], ['delivery' => true, 'rating' => true, 'stock' => true], ['default_sort' => 'recommended', 'products_per_page' => 24]);
    }

    private function provider(): SearchProvider
    {
        return app(SearchSettings::class)->provider() ?? self::fail('No search provider');
    }

    public function test_products_are_found_by_name_code_prefix_typo_and_greek_without_accents(): void
    {
        $phone = $this->product('Galaxy A55 smartphone', 'Κινητό τηλέφωνο Galaxy A55', $this->phones, ['sku' => 'SM-A556B', 'ean' => '4006381333931']);
        $mouse = $this->product('Wireless mouse Nimbus', 'Ασύρματο ποντίκι Nimbus', $this->mice, ['sku' => 'MX-2000']);
        $draft = $this->product('Galaxy draft', null, $this->phones, ['status' => 'disabled']);
        $this->build();

        self::assertSame(['Galaxy A55 smartphone'], $this->suggest('SM-A556B'), 'exact SKU');
        self::assertSame(['Galaxy A55 smartphone'], $this->suggest('4006381333931'), 'exact EAN');
        self::assertSame([], $this->suggest('SM-A557B'), 'codes never match with a typo');
        self::assertSame(['Galaxy A55 smartphone'], $this->suggest('gala'), 'prefix');
        self::assertSame(['Wireless mouse Nimbus'], $this->suggest('wireles mouse'), 'typo and several words');
        self::assertSame(['Κινητό τηλέφωνο Galaxy A55'], $this->suggest('κινητο', 'el'), 'Greek without tonos');
        self::assertSame(['Ασύρματο ποντίκι Nimbus'], $this->suggest('ΑΣΥΡΜΑΤΟ', 'el'), 'Greek capitals');
        // The other language's name finds the product too; the card shows the shopper's language.
        self::assertSame(['Galaxy A55 smartphone'], $this->suggest('τηλεφωνο', 'en'));
        self::assertNotContains('Galaxy draft', $this->suggest('galaxy'), 'unpublished products are not indexed');
        self::assertSame(2, $this->provider()->count((string) DB::table('search_index_runs')->value('target'), []));

        // Through HTTP: JSON with card fields only, never index internals.
        $this->getJson('/search/suggest?q=nimbus')->assertOk()->assertJsonPath('items.0.id', (string) $mouse->id)
            ->assertJsonMissingPath('items.0.product_id')->assertJsonMissingPath('items.0.codes');
        $this->get('/search?q=galaxy')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        self::assertNotNull($phone->id);
        self::assertNotNull($draft->id);
    }

    public function test_catalog_changes_reach_the_index_through_the_outbox_and_critical_ones_hide_at_once(): void
    {
        $mouse = $this->product('Wireless mouse', null, $this->mice);
        $this->build();
        self::assertSame(0, DB::table('search_outbox')->count());

        // A rename is queued by the database trigger in the same transaction, then indexed.
        DB::table('product_translations')->where('product_id', $mouse->id)->update(['name' => 'Silent trackball']);
        self::assertSame([false], DB::table('search_outbox')->pluck('critical')->all());
        self::assertSame([], $this->suggest('trackball'));
        $result = app(SearchIndex::class)->sync(5);
        self::assertSame(1, $result['indexed']);
        self::assertSame(['Silent trackball'], $this->suggest('trackball'));
        self::assertSame(0, DB::table('search_outbox')->count());

        // Disabling is critical: hidden from results and counts before the index has caught up.
        DB::table('products')->where('id', $mouse->id)->update(['status' => 'disabled']);
        self::assertTrue((bool) DB::table('search_outbox')->where('product_id', $mouse->id)->value('critical'));
        self::assertSame([], $this->suggest('trackball'));
        self::assertSame(0, $this->listing(['q' => 'trackball'])['facets']['total']);
        app(SearchIndex::class)->sync(5);
        self::assertSame([], iterator_to_array($this->provider()->indexedIds(app(SearchSettings::class)->values($this->provider())), false));

        // A change made after a worker read the product is not lost: only the indexed version leaves the outbox.
        DB::table('products')->where('id', $mouse->id)->update(['status' => 'active']);
        $version = (int) DB::table('search_outbox')->value('version');
        DB::table('product_translations')->where('product_id', $mouse->id)->update(['name' => 'Silent mouse']);
        self::assertGreaterThan($version, (int) DB::table('search_outbox')->value('version'));
        app(SearchIndex::class)->sync(5);
        self::assertSame(['Silent mouse'], $this->suggest('silent'));
    }

    public function test_category_exclusion_applies_to_results_counts_facets_and_suggestions_at_once(): void
    {
        $accessories = $this->category('Accessories');
        $cables = $this->category('Cables', $accessories);
        $this->product('Nimbus phone', null, $this->phones, ['brand_id' => $this->brand('nimbus')]);
        $this->product('Nimbus cable', null, $cables, ['brand_id' => $this->brand('aster')]);
        $this->build();
        self::assertSame(2, $this->listing(['q' => 'nimbus'])['facets']['total']);

        // Excluding the parent excludes its subtree, immediately, although the index still holds the documents.
        DB::table('categories')->where('id', $accessories->id)->update(['exclude_from_discovery' => true]);
        app()->forgetScopedInstances();
        self::assertSame(0, DB::table('search_outbox')->count(), 'nothing to reindex');
        self::assertSame(2, $this->provider()->count((string) DB::table('search_index_runs')->value('target'), []), 'the stale documents are still indexed');
        $listing = $this->listing(['q' => 'nimbus']);
        self::assertSame(1, $listing['facets']['total']);
        self::assertSame(['Nimbus phone'], $this->suggest('nimbus'));
        $groups = collect($listing['facets']['groups'])->keyBy('key');
        self::assertSame(['Nimbus'], array_column($groups['brand']['options'], 'label'), 'brand facet counts only searchable products');
        self::assertSame(['Phones'], array_column($groups['category']['options'], 'label'));
        // Asking for the excluded category by ID finds nothing either.
        self::assertSame(1, $this->listing(['q' => 'nimbus', 'category' => (string) $cables->id])['facets']['total']);

        // A hidden (disabled) ancestor and a category gaining a subcategory (no longer a leaf) hide products too.
        DB::table('categories')->where('id', $accessories->id)->update(['exclude_from_discovery' => false, 'status' => 'disabled']);
        app()->forgetScopedInstances();
        self::assertSame(['Nimbus phone'], $this->suggest('nimbus'));
    }

    public function test_grouped_search_filters_members_before_grouping_and_keeps_all_facets(): void
    {
        [$colour, $black, $white] = $this->choiceFeature('Colour', ['Black', 'White']);
        [$ram, $small, $large] = $this->choiceFeature('RAM', ['16GB', '32GB']);
        $cheap = $this->product('Nimbus laptop black', null, $this->mice, ['price' => '20'], [$colour => $black, $ram => $small]);
        $other = $this->product('Nimbus laptop white', null, $this->mice, ['price' => '30'], [$colour => $white, $ram => $large]);
        $third = $this->product('Nimbus laptop white small', null, $this->mice, ['price' => '40'], [$colour => $white, $ram => $small]);
        $group = DB::table('variation_groups')->insertGetId(['name' => 'Nimbus', 'single_search' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$cheap, $other, $third] as $p) {
            DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $p->id, 'signature' => '0:'.$p->id, 'joined_at' => now()]);
        }
        $this->build();
        $all = $this->listing(['q' => 'laptop']);
        self::assertSame(1, $all['facets']['total']);
        self::assertSame([(string) $cheap->id], $all['search']['ids']);
        $colors = collect($all['facets']['groups'])->firstWhere('key', 'f'.$colour)['options'];
        self::assertCount(2, $colors);
        self::assertSame([1, 1], array_column($colors, 'count'));
        $filtered = $this->listing(['q' => 'laptop', 'f'.$ram => (string) $large]);
        self::assertSame(1, $filtered['facets']['total']);
        self::assertSame([(string) $cheap->id], $filtered['search']['ids']);
        self::assertSame(0, $this->listing(['q' => 'laptop', 'f'.$ram => (string) $large, 'f'.$colour => (string) $black])['facets']['total']);
        DB::table('variation_groups')->where('id', $group)->update(['single_search' => false]);
        self::assertTrue(DB::table('search_outbox')->where('product_id', $other->id)->value('critical'));
        $this->build();
        self::assertSame(3, $this->listing(['q' => 'laptop'])['facets']['total']);
    }

    public function test_filters_and_counts_combine_like_category_listings(): void
    {
        $nimbus = $this->brand('nimbus');
        $aster = $this->brand('aster');
        [$colour, $black, $white] = $this->choiceFeature('Colour', ['Black', 'White']);
        $this->product('Nimbus mouse black', null, $this->mice, ['brand_id' => $nimbus, 'price' => '20'], [$colour => $black]);
        $this->product('Nimbus mouse white', null, $this->mice, ['brand_id' => $nimbus, 'price' => '30'], [$colour => $white]);
        $this->product('Aster mouse black', null, $this->mice, ['brand_id' => $aster, 'price' => '45.5', 'quantity' => 0], [$colour => $black]);
        $this->build();

        $all = $this->listing(['q' => 'mouse']);
        self::assertSame(3, $all['facets']['total']);
        $groups = collect($all['facets']['groups'])->keyBy('key');
        self::assertSame(['20', '45.5'], [$groups['price']['min'], $groups['price']['max']]);
        self::assertSame(['Aster' => 1, 'Nimbus' => 2], array_column($groups['brand']['options'], 'count', 'label'));
        self::assertSame(['Black' => 2, 'White' => 1], array_column($groups['f'.$colour]['options'], 'count', 'label'));

        // One brand: the brand group still counts the other brand (OR within a group), the others narrow (AND).
        $filtered = $this->listing(['q' => 'mouse', 'brand' => 'nimbus', 'f'.$colour => (string) $black]);
        self::assertSame(1, $filtered['facets']['total']);
        $groups = collect($filtered['facets']['groups'])->keyBy('key');
        self::assertSame(['Aster' => 1, 'Nimbus' => 1], array_column($groups['brand']['options'], 'count', 'label'));
        self::assertSame(['Black' => 1, 'White' => 1], array_column($groups['f'.$colour]['options'], 'count', 'label'));
        self::assertCount(2, $filtered['facets']['active']);

        // Exact price bounds (no float rounding), stock, sorting and pagination.
        self::assertSame(2, $this->listing(['q' => 'mouse', 'price_min' => '30', 'price_max' => '45.5'])['facets']['total']);
        self::assertSame(2, $this->listing(['q' => 'mouse', 'in_stock' => '1'])['facets']['total']);
        $sorted = $this->listing(['q' => 'mouse', 'sort' => 'price_desc']);
        self::assertSame(['45.5', '30', '20'], DB::table('products')->whereIn('id', $sorted['search']['ids'])->orderByRaw('array_position(?::bigint[], id)', ['{'.implode(',', $sorted['search']['ids']).'}'])->pluck('price')->map(fn ($p) => rtrim(rtrim((string) $p, '0'), '.'))->all());
        $paged = app(ProductSearch::class)->listing(['q' => 'mouse', 'page' => '9'], ['locale' => 'en', 'customer' => null], [], ['default_sort' => 'recommended', 'products_per_page' => 2]);
        self::assertSame([2, 2, 1], [$paged['search']['page'], $paged['search']['last_page'], count($paged['search']['ids'])], 'a page past the end shows the last page');

        // Unknown, malformed or inactive filters are dropped, never passed on as engine syntax.
        $forged = $this->listing(['q' => 'mouse', 'brand' => 'x || true', 'f99999' => '1', 'price_min' => 'abc', 'category' => '1) || (1', 'sort' => 'price:asc,_eval(1)']);
        self::assertSame(3, $forged['facets']['total']);
        self::assertSame('relevance', $forged['search']['sort']);
    }

    public function test_customer_group_restrictions_follow_the_visitor(): void
    {
        $vip = (int) DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->product('Members lounge chair', null, $this->mice);
        DB::table('product_customer_groups')->insert(['product_id' => $product->id, 'group_id' => $vip]);
        $user = UserRecord::create(['name' => 'Ria', 'email' => 'ria@example.test', 'password' => 'a shopper passphrase']);
        $customer = (string) DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => 'ria@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $vip]);
        $this->build();

        self::assertSame(0, $this->listing(['q' => 'lounge'])['facets']['total']);
        self::assertSame(1, $this->listing(['q' => 'lounge'], $customer)['facets']['total']);
    }

    public function test_an_unreachable_engine_is_reported_never_shown_as_no_results(): void
    {
        $this->product('Wireless mouse', null, $this->mice);
        $this->build();
        Http::fake(['*' => Http::failedConnection()]);
        Cache::flush();

        $this->get('/search?q=mouse')->assertStatus(503)->assertHeader('Retry-After');
        self::assertSame('unavailable', $this->listing(['q' => 'mouse'])['page']['search']['state']);
        $this->getJson('/search/suggest?q=mouse')->assertStatus(503)->assertJsonPath('state', 'unavailable');

        // Queued changes wait for the engine without using up their attempts.
        DB::table('product_translations')->update(['name' => 'Silent mouse']);
        app(SearchIndex::class)->sync(5);
        $row = DB::table('search_outbox')->first();
        self::assertSame([0, null], [(int) $row->attempts, $row->failed_at]);
        self::assertNotNull($row->last_error);
        self::assertTrue(now()->lt($row->available_at));
        self::assertNotNull(DB::table('search_index_state')->value('last_error'));
        self::assertStringNotContainsString('local-only-typesense', (string) DB::table('search_index_state')->value('last_error'));
    }

    public function test_rebuilds_switch_the_alias_only_when_complete_and_keep_the_previous_index(): void
    {
        $this->product('Wireless mouse', null, $this->mice);
        // Before the first build, the store says search is being prepared.
        self::assertSame('not_ready', $this->listing(['q' => 'mouse'])['page']['search']['state']);
        $this->build();
        $first = (string) DB::table('search_index_runs')->value('target');
        $this->build();
        $second = (string) DB::table('search_index_runs')->orderByDesc('id')->value('target');
        self::assertNotSame($first, $second);
        $health = $this->provider()->health([], app(SearchSettings::class)->languages());
        self::assertTrue($health->ready && $health->current);
        self::assertSame($this->alias.' → '.$second, $health->index);

        // A cancelled rebuild drops its own index and leaves the live one alone.
        $provider = $this->provider();
        $target = $provider->startRebuild([], app(SearchSettings::class)->languages());
        $run = (int) DB::table('search_index_runs')->insertGetId(['provider' => 'typesense', 'target' => $target, 'status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        app(SearchIndex::class)->cancelRebuild($run);
        self::assertSame(['Wireless mouse'], $this->suggest('mouse'));
        $names = array_column((new Factory)->withHeaders(['X-TYPESENSE-API-KEY' => 'local-only-typesense'])->get('http://'.env('TYPESENSE_HOST', 'typesense').':8108/collections')->json(), 'name');
        self::assertNotContains($target, $names);
        self::assertContains($first, $names, 'the previous index is kept for recovery');
        // Clean-up keeps the live index and the newest previous one.
        self::assertSame([], $provider->cleanup([]));
    }

    public function test_reconciliation_queues_missing_and_stray_documents(): void
    {
        $kept = $this->product('Wireless mouse', null, $this->mice);
        $this->build();
        $missing = $this->product('Gaming mouse', null, $this->mice);
        DB::table('search_outbox')->delete();
        // A document the catalog no longer publishes, its change lost.
        DB::table('products')->where('id', $kept->id)->update(['status' => 'disabled']);
        DB::table('search_outbox')->delete();

        $summary = app(SearchIndex::class)->reconcile();
        self::assertSame(['indexed' => 1, 'eligible' => 1, 'missing' => 1, 'extra' => 1, 'retried' => 0], $summary);
        // Missing products are queued; stray documents are queued as critical, so they are hidden until removed.
        self::assertFalse((bool) DB::table('search_outbox')->where('product_id', $missing->id)->value('critical'));
        self::assertTrue((bool) DB::table('search_outbox')->where('product_id', $kept->id)->value('critical'));
        self::assertSame(2, DB::table('search_outbox')->count());
        app(SearchIndex::class)->sync(5);
        self::assertSame(['Gaming mouse'], $this->suggest('mouse'));
    }

    public function test_admin_recovery_warns_and_repairs_delayed_and_failed_updates_without_waiting_for_a_worker(): void
    {
        $one = $this->product('Recovery laptop black', null, $this->mice);
        $two = $this->product('Recovery laptop white', null, $this->mice);
        $this->build();
        $index = app(SearchIndex::class);
        $index->enqueue([(string) $one->id, (string) $two->id], true);
        DB::table('search_outbox')->where('product_id', $one->id)->update(['attempts' => 4, 'last_error' => 'Old document format', 'available_at' => now()->addHour()]);
        DB::table('search_outbox')->where('product_id', $two->id)->update(['attempts' => 8, 'last_error' => 'Refused update', 'failed_at' => now(), 'available_at' => now()->addHour()]);
        self::assertSame([], $this->suggest('recovery'));
        $this->actingAs($this->staff(['admin.access']));
        $this->post('/admin/settings/search/repair')->assertForbidden();
        $this->get('/admin')->assertInertia(fn ($p) => $p->where('searchHealth', null));
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'search.manage']));
        $this->get('/admin')->assertOk()->assertInertia(fn ($p) => $p->where('searchHealth.status', 'attention')->where('searchHealth.hidden', 2)->where('searchHealth.errors', 2));
        $this->get('/admin/settings/search')->assertInertia(fn ($p) => $p->where('recovery.status', 'attention')->where('recovery.hidden', 2));
        $this->from('/admin/settings/search')->post('/admin/settings/search/repair')->assertRedirect('/admin/settings/search')->assertSessionHasNoErrors()->assertSessionHas('status', __('search.repair_complete'));
        self::assertSame(0, DB::table('search_outbox')->count());
        self::assertNotNull(Cache::get('illuminate:queue:restart'));
        self::assertCount(2, $this->suggest('recovery'));
        $this->get('/admin')->assertOk()->assertInertia(fn ($p) => $p->where('searchHealth.status', 'healthy')->where('searchHealth.hidden', 0));
        $this->get('/admin/settings/search')->assertInertia(fn ($p) => $p->where('recovery.errors', 0));
    }

    public function test_admin_repair_builds_a_missing_index_without_a_queued_worker(): void
    {
        $this->product('New recovery laptop', null, $this->mice);
        $this->actingAs($this->staff(['admin.access', 'search.manage']));
        $this->from('/admin/settings/search')->post('/admin/settings/search/repair')->assertSessionHasNoErrors()->assertSessionHas('status', __('search.repair_complete'));
        self::assertSame('completed', DB::table('search_index_runs')->value('status'));
        self::assertCount(1, $this->suggest('recovery'));
    }

    public function test_search_health_distinguishes_recent_pending_work_from_stalled_updates(): void
    {
        $product = $this->product('Pending laptop', null, $this->mice);
        $health = app(SearchRecovery::class);
        self::assertSame('pending', $health->status()['status']);
        DB::table('search_outbox')->where('product_id', $product->id)->update(['queued_at' => DB::raw("clock_timestamp() - interval '6 minutes'")]);
        self::assertSame('attention', $health->status()['status']);
        self::assertSame(1, $health->status()['stalled']);
    }

    public function test_administration_is_protected_and_never_exposes_the_engine_key(): void
    {
        $this->product('Wireless mouse', null, $this->mice);
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/settings/search')->assertForbidden();
        $this->post('/admin/settings/search/rebuild')->assertForbidden();
        $this->post('/admin/settings/search/repair')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'search.manage']));
        $page = $this->get('/admin/settings/search')->assertOk();
        self::assertStringNotContainsString('local-only-typesense', $page->getContent());
        $this->post('/admin/settings/search/rebuild')->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame('completed', DB::table('search_index_runs')->value('status'));
        $this->get('/admin/settings/search')->assertInertia(fn ($p) => $p->where('engine.built', true)->where('engine.health.documents', 1)->where('catalog.eligible', 1));

        // Synonyms are validated, saved and applied to searches at once.
        $version = (int) DB::table('search_provider_settings')->value('lock_version');
        $this->put('/admin/settings/search', ['version' => $version, 'settings' => ['typo_tolerance' => 'normal', 'synonyms' => 'nonsense']])->assertSessionHasErrors('settings.synonyms');
        $this->put('/admin/settings/search', ['version' => $version, 'settings' => ['typo_tolerance' => 'normal', 'synonyms' => "rodent => mouse\nnotebook, laptop"]])->assertSessionHasNoErrors();
        self::assertSame(['Wireless mouse'], $this->suggest('rodent'));

        // Storefront visitors cannot reach any of it; public pages never carry the key.
        auth()->logout();
        $this->post('/admin/settings/search/rebuild')->assertRedirect('/admin/login');
        self::assertStringNotContainsString('local-only-typesense', $this->get('/search?q=mouse')->getContent());
        self::assertStringNotContainsString('local-only-typesense', $this->getJson('/search/suggest?q=mouse')->getContent());
    }

    public function test_empty_and_overlong_queries(): void
    {
        $this->product('Wireless mouse', null, $this->mice);
        $this->build();
        self::assertSame('no_query', $this->listing(['q' => "  \t "])['page']['search']['state']);
        self::assertSame(100, mb_strlen(app(ProductSearch::class)->text(str_repeat('ab ', 80))));
        self::assertSame('mouse x', app(ProductSearch::class)->text("mouse\x00\u{200B}x"));
        $this->getJson('/search/suggest?q=m')->assertOk()->assertJsonPath('items', []);
    }

    public function test_the_minimum_query_length_is_an_add_on_setting(): void
    {
        $this->product('Wireless mouse', null, $this->mice);
        $this->build();
        // Two characters by default: shorter queries are refused with the minimum, and get no suggestions.
        self::assertSame(['state' => 'too_short', 'min' => 2], array_intersect_key($this->listing(['q' => 'm'])['page']['search'], ['state' => 0, 'min' => 0]));
        $this->getJson('/search/suggest?q=m')->assertOk()->assertJsonPath('items', []);
        self::assertSame(['Wireless mouse'], $this->suggest('mo'));

        $this->actingAs($this->staff(['admin.access', 'search.manage']));
        $save = fn (string $min) => $this->put('/admin/settings/search', ['version' => (int) DB::table('search_provider_settings')->value('lock_version'), 'settings' => ['typo_tolerance' => 'normal', 'min_query_length' => $min]]);
        $save('11')->assertSessionHasErrors('settings.min_query_length');
        $save('3')->assertSessionHasNoErrors();
        self::assertSame('too_short', $this->listing(['q' => 'mo'])['page']['search']['state']);
        self::assertSame(['Wireless mouse'], $this->suggest('mou'));
        // Empty: no rule, though an empty query is still not a search.
        $save('')->assertSessionHasNoErrors();
        self::assertSame(['Wireless mouse'], $this->suggest('m'));
        self::assertSame('no_query', $this->listing(['q' => ''])['page']['search']['state']);
    }

    public function test_submitted_searches_are_counted_and_feed_the_popular_searches_block(): void
    {
        $this->product('Wireless mouse', null, $this->mice);
        $this->build();
        foreach (['Mouse', '  mouse ', 'MOUSE', 'wireless', 'zzzz'] as $q) {
            $this->listing(['q' => $q]);
        }
        // Paging or filtering the same search is not another search; refused queries are not counted.
        $this->listing(['q' => 'mouse', 'sort' => 'newest']);
        $this->listing(['q' => 'm']);
        self::assertSame(['mouse' => [3, 1], 'wireless' => [1, 1], 'zzzz' => [1, 0]], DB::table('search_terms')->orderBy('term')->get()->mapWithKeys(fn ($r) => [$r->term => [(int) $r->searches, (int) $r->results]])->all());

        $block = fn (array $settings, array $items = []) => app(BlockContent::class)->resolve('popular_searches', ['b' => ['settings' => $settings, 'selections' => ['items' => $items]]], ['locale' => 'en', 'customer' => null])['b']['popular_searches'] ?? [];
        // Most searched first; terms that found nothing are left out.
        self::assertSame([['term' => 'mouse', 'url' => '/search?q=mouse'], ['term' => 'wireless', 'url' => '/search?q=wireless']], $block(['source' => 'popular', 'limit' => 12]));
        self::assertSame(['mouse'], array_column($block(['source' => 'popular', 'limit' => 1]), 'term'));

        // Staff hide a term from the block on the Popular searches screen (search.manage).
        $id = (string) DB::table('search_terms')->where('term', 'mouse')->value('id');
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/search-terms')->assertForbidden();
        $this->patch("/admin/search-terms/$id/visibility", ['hidden' => true])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'search.manage']));
        $this->get('/admin/search-terms?results=with')->assertInertia(fn ($p) => $p->component('Admin/SearchTerms/Index')->where('terms.total', 2)->where('terms.data.0.term', 'mouse')->where('terms.data.0.searches', 3));
        $this->patch("/admin/search-terms/$id/visibility", ['hidden' => true])->assertSessionHasNoErrors()->assertRedirect();
        self::assertTrue((bool) DB::table('search_terms')->where('id', $id)->value('hidden'));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'search_term.updated')->where('entity_id', $id)->count());
        self::assertSame(['wireless'], array_column($block(['source' => 'popular', 'limit' => 12]), 'term'));
        // A block's own picks keep their order and show hidden terms too.
        $wireless = (string) DB::table('search_terms')->where('term', 'wireless')->value('id');
        self::assertSame(['wireless', 'mouse'], array_column($block(['source' => 'manual', 'limit' => 12], [$wireless, $id]), 'term'));
        $this->get('/admin/search-terms?visibility=hidden')->assertInertia(fn ($p) => $p->where('terms.total', 1));

        // Once-searched old terms are forgotten.
        DB::table('search_terms')->update(['last_searched_at' => now()->subDays(SearchTerms::PRUNE_DAYS + 1)]);
        self::assertSame(2, app(SearchTerms::class)->prune());
        self::assertSame(['mouse'], DB::table('search_terms')->pluck('term')->all());
    }

    public function test_without_an_enabled_search_add_on_the_field_is_hidden_and_search_unavailable(): void
    {
        $showsSearch = function (): array {
            $values = [];
            $page = json_decode((string) json_encode($this->get('/')->viewData('page')), true);
            array_walk_recursive($page, function ($value, $key) use (&$values): void {
                if ($key === 'show_search') {
                    $values[] = $value;
                }
            });

            return $values;
        };
        self::assertContains(true, $showsSearch());
        app(AddonManager::class)->disable(null, 'ambresoft/typesense');
        app()->forgetInstance(SearchSettings::class);
        self::assertNotContains(true, $showsSearch());
        self::assertSame('unavailable', $this->listing(['q' => 'mouse'])['page']['search']['state']);
        $this->get('/search?q=mouse')->assertStatus(503);
    }

    private function brand(string $code): int
    {
        $id = (int) DB::table('brands')->insertGetId(['code' => $code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => $this->en, 'name' => ucfirst($code)]);

        return $id;
    }

    /** @param list<string> $labels
     * @return list<int> The feature ID, then the option IDs */
    private function choiceFeature(string $name, array $labels): array
    {
        $group = (int) DB::table('feature_groups')->insertGetId(['position' => (int) DB::table('feature_groups')->max('position') + 1]);
        $feature = (int) DB::table('features')->insertGetId(['code' => Str::slug($name), 'type' => 'single', 'group_id' => $group, 'position' => 1, 'filterable' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('feature_translations')->insert(['feature_id' => $feature, 'locale_id' => $this->en, 'name' => $name]);
        $ids = [$feature];
        foreach ($labels as $n => $label) {
            $option = (int) DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'position' => $n + 1]);
            DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $this->en, 'label' => $label]);
            $ids[] = $option;
        }

        return $ids;
    }
}
