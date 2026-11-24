<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\FirstPartyBlock;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StorefrontCacheTest extends TestCase
{
    use DatabaseMigrations, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['storefront.cache_store' => 'array']);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    private function nextRequest(): StorefrontCache
    {
        app()->forgetInstance(StorefrontCache::class);

        return app(StorefrontCache::class);
    }

    public function test_disabled_default_warm_hits_partial_clear_full_clear_and_permissions(): void
    {
        $admin = $this->staff(super: true);
        $this->actingAs($admin)->get('/admin/cache')->assertInertia(fn (Assert $p) => $p->component('Admin/Settings/Cache')->where('settings.enabled', false));
        $calls = 0;
        $load = function () use (&$calls): array {
            return ['calls' => ++$calls];
        };
        $cache = $this->nextRequest();
        self::assertSame(['calls' => 1], $cache->remember('products', ['one'], $load));
        self::assertSame(['calls' => 2], $cache->remember('products', ['one'], $load));
        $this->put('/admin/cache', ['enabled' => true, 'version' => 1])->assertSessionHasNoErrors();
        $cache = $this->nextRequest();
        self::assertSame(['calls' => 3], $cache->remember('products', ['one'], $load));
        self::assertSame(['calls' => 3], $cache->remember('products', ['one'], $load));
        self::assertSame(['calls' => 4], $cache->remember('categories', ['one'], $load));
        Cache::store('array')->put('unrelated-session', 'keep', 60);
        $this->post('/admin/cache/clear', ['group' => 'products'])->assertSessionHasNoErrors();
        $cache = $this->nextRequest();
        self::assertSame(['calls' => 4], $cache->remember('categories', ['one'], $load));
        self::assertSame(['calls' => 5], $cache->remember('products', ['one'], $load));
        $this->post('/admin/cache/clear', ['group' => 'all'])->assertSessionHasNoErrors();
        self::assertSame(['calls' => 6], $this->nextRequest()->remember('categories', ['one'], $load));
        self::assertSame('keep', Cache::store('array')->get('unrelated-session'));
        $this->put('/admin/cache', ['enabled' => false, 'version' => 1])->assertSessionHasErrors('version');
        $this->post('/admin/cache/clear', ['group' => 'sessions'])->assertSessionHasErrors('group');
        $this->put('/admin/cache', ['enabled' => 'invalid', 'version' => 2])->assertSessionHasErrors('enabled');
        $this->put('/admin/cache', ['enabled' => false, 'version' => 2])->assertSessionHasNoErrors();
        self::assertFalse((bool) $this->nextRequest()->state()->enabled);
        self::assertSame(2, DB::table('audit_logs')->where('action', 'cache.cleared')->count());
        $this->flushSession();
        $staff = $this->staff();
        $this->actingAs($staff)->get('/admin/cache')->assertForbidden();
        $this->put('/admin/cache', ['enabled' => true, 'version' => 3])->assertForbidden();
        $this->post('/admin/cache/clear', ['group' => 'all'])->assertForbidden();
        $this->flushSession();
        $manager = $this->staff(['admin.access', 'cache.manage']);
        $this->actingAs($manager)->get('/admin/cache')->assertOk();
    }

    public function test_clear_and_save_return_to_cache_page_even_with_an_action_url_as_referrer(): void
    {
        $this->actingAs($this->staff(super: true));
        foreach (['all', 'categories'] as $group) {
            $response = $this->from('/admin/cache/clear')->post('/admin/cache/clear', ['group' => $group]);
            $response->assertSessionHasNoErrors()->assertRedirect('/admin/cache')->assertSessionHas('status', __($group === 'all' ? 'cache.cleared' : 'cache.cleared_'.$group));
            $this->followRedirects($response)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Settings/Cache'));
        }
        $response = $this->from('/admin/cache/clear')->put('/admin/cache', ['enabled' => true, 'version' => 1]);
        $response->assertSessionHasNoErrors()->assertRedirect('/admin/cache');
        $this->followRedirects($response)->assertOk()->assertInertia(fn (Assert $p) => $p->where('settings.enabled', true));
    }

    public function test_source_changes_invalidate_cached_layout_and_translations_and_rollbacks_do_not_publish(): void
    {
        DB::table('storefront_cache_state')->update(['enabled' => true]);
        $store = app(LayoutStore::class);
        $cold = $store->read('homepage', true);
        DB::enableQueryLog();
        $this->nextRequest();
        self::assertSame($cold, $store->read('homepage', true));
        $warmQueries = DB::getQueryLog();
        DB::disableQueryLog();
        // One generation-state read instead of all layout, block, selection and locale queries.
        self::assertCount(1, $warmQueries);
        DB::table('storefront_layouts')->where('page_type', 'homepage')->increment('lock_version');
        $this->nextRequest();
        self::assertSame($cold['version'] + 1, $store->read('homepage', true)['version']);
        $id = DB::table('categories')->insertGetId(['position' => 1, 'status' => 'active']);
        $locale = DB::table('locales')->where('code', 'en')->value('id');
        DB::table('category_translations')->insert(['category_id' => $id, 'locale_id' => $locale, 'name' => 'Original', 'slug' => 'original']);
        $this->nextRequest();
        $content = app(BlockContent::class);
        self::assertSame('Original', $content->translations('category_translations', 'category_id', [$id])[$id]['en']['name']);
        self::assertSame('/original', app(CategoryUrls::class)->paths([(string) $id], 'en')[$id]);
        DB::beginTransaction();
        DB::table('category_translations')->where('category_id', $id)->update(['name' => 'Uncommitted', 'slug' => 'uncommitted']);
        self::assertSame('Uncommitted', $content->translations('category_translations', 'category_id', [$id])[$id]['en']['name']);
        DB::rollBack();
        $this->nextRequest();
        self::assertSame('Original', $content->translations('category_translations', 'category_id', [$id])[$id]['en']['name']);
        DB::table('category_translations')->where('category_id', $id)->update(['name' => 'Imported', 'slug' => 'imported']);
        $this->nextRequest();
        self::assertSame('Imported', $content->translations('category_translations', 'category_id', [$id])[$id]['en']['name']);
        self::assertSame('/imported', app(CategoryUrls::class)->paths([(string) $id], 'en')[$id]);
    }

    public function test_warm_public_blocks_recheck_restrictions_after_bulk_edits_and_products_keep_live_prices(): void
    {
        DB::table('storefront_cache_state')->update(['enabled' => true]);
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $locale = DB::table('locales')->where('code', 'en')->value('id');
        $category->translations()->create(['locale_id' => $locale, 'name' => 'Visible', 'slug' => 'visible']);
        $type = new FirstPartyBlock('categories');
        $block = ['b' => ['id' => 'b', 'template' => $type->definition()['templates'][0], 'settings' => $type->definition()['defaults'], 'translations' => [], 'selections' => ['items' => [(string) $category->id]]]];
        $block['b']['settings']['source'] = 'manual';
        $content = app(BlockContent::class);
        $this->nextRequest();
        $shown = $content->resolve('categories', $block, ['locale' => 'en', 'customer' => null]);
        self::assertSame('Visible', $shown['b']['items'][0]['name']);
        self::assertSame($shown, $content->resolve('categories', $block, ['locale' => 'en', 'customer' => null]));
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('category_customer_groups')->insert(['category_id' => $category->id, 'group_id' => $group]);
        $this->nextRequest();
        self::assertSame([], $content->resolve('categories', $block, ['locale' => 'en', 'customer' => null]));
        DB::table('category_customer_groups')->where('category_id', $category->id)->delete();
        $product = ProductRecord::create(['sku' => 'CACHE-PRICE', 'price' => '100', 'quantity' => 5, 'category_id' => $category->id, 'status' => 'active']);
        $product->translations()->create(['locale_id' => $locale, 'name' => 'Product', 'slug' => 'product']);
        $cards = fn (): array => $content->productCards([(string) $product->id], ['locale' => 'en', 'customer' => null], app(PublicCatalog::class)->products(null))['cards'];
        $this->nextRequest();
        $first = $cards()[$product->id];
        DB::table('products')->where('id', $product->id)->update(['price' => '125', 'quantity' => 0]);
        $this->nextRequest();
        $second = $cards()[$product->id];
        self::assertNotSame($first['price'], $second['price']);
        DB::table('products')->where('id', $product->id)->update(['status' => 'disabled']);
        $this->nextRequest();
        self::assertSame([], $cards());
    }

    public function test_redis_round_trip_null_payload_and_expiration(): void
    {
        config(['storefront.cache_store' => 'storefront']);
        DB::table('storefront_cache_state')->update(['enabled' => true]);
        $cache = $this->nextRequest();
        self::assertTrue($cache->reachable());
        $calls = 0;
        $load = function () use (&$calls): ?array {
            $calls++;

            return null;
        };
        self::assertNull($cache->remember('brands', ['null-result'], $load));
        self::assertNull($this->nextRequest()->remember('brands', ['null-result'], $load));
        self::assertSame(1, $calls);
        // Arrays survive the real Redis serializer without enabling arbitrary PHP objects.
        $value = ['nested' => [42 => ['name' => 'Ελληνικά', 'enabled' => false]]];
        self::assertSame($value, $cache->remember('products', ['roundtrip'], fn () => $value));
        self::assertSame($value, $this->nextRequest()->remember('products', ['roundtrip'], fn () => ['wrong']));
        // Test expiry with the clock-aware array store; real Redis TTL is wall time.
        config(['storefront.cache_store' => 'array']);
        $cache->remember('brands', ['expiry'], $load);
        $this->travel(6)->minutes();
        $cache->remember('brands', ['expiry'], $load);
        self::assertSame(3, $calls);
    }

    public function test_backend_failure_falls_back_without_caching_or_swallowing_source_errors(): void
    {
        DB::table('storefront_cache_state')->update(['enabled' => true]);
        config(['storefront.cache_store' => 'not-configured']);
        $cache = $this->nextRequest();
        self::assertSame(['live' => true], $cache->remember('blocks', ['x'], fn () => ['live' => true]));
        self::assertFalse($cache->reachable());
        $admin = $this->staff(super: true);
        $this->actingAs($admin)->put('/admin/cache', ['enabled' => true, 'version' => 1])->assertSessionHasErrors('enabled');
        $this->put('/admin/cache', ['enabled' => false, 'version' => 1])->assertSessionHasNoErrors();
        DB::table('storefront_cache_state')->update(['enabled' => true]);
        config(['storefront.cache_store' => 'array']);
        $this->expectExceptionMessage('source failure');
        $this->nextRequest()->remember('products', ['broken'], fn () => throw new \RuntimeException('source failure'));
    }
}
