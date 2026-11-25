<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Storefront\Infrastructure\RecentlyViewedProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SearchHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app()->setLocale('en');
    }

    private function product(string $name, string $status = 'active'): ProductRecord
    {
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $category->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'History category '.$category->id, 'slug' => 'history-category-'.$category->id]);
        $product = ProductRecord::create(['sku' => 'HISTORY-'.Str::uuid(), 'category_id' => $category->id, 'status' => $status, 'price' => '10', 'quantity' => 5]);
        $product->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => $name, 'slug' => 'history-'.$product->id]);

        return $product;
    }

    public function test_empty_guest_history_is_private_and_does_not_require_a_search_engine(): void
    {
        $this->getJson('/search/recently-viewed')->assertOk()->assertExactJson(['items' => []])->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('private', $this->getJson('/search/recently-viewed')->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $this->getJson('/search/recently-viewed')->headers->get('Cache-Control'));
    }

    public function test_guest_history_keeps_view_order_and_filters_ineligible_records_without_tracking(): void
    {
        $older = $this->product('Earlier product');
        $newer = $this->product('Latest product');
        $disabled = $this->product('Disabled product', 'disabled');
        $private = $this->product('Restricted product');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('product_customer_groups')->insert(['product_id' => $private->id, 'group_id' => $group]);
        $this->withSession([RecentlyViewedProducts::SESSION => [
            $older->id => now()->subMinutes(4)->toIso8601String(), $newer->id => now()->subMinutes(3)->toIso8601String(),
            $disabled->id => now()->subMinutes(2)->toIso8601String(), $private->id => now()->subMinute()->toIso8601String(),
        ]]);
        $this->getJson('/search/recently-viewed')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', (string) $newer->id)->assertJsonPath('items.1.id', (string) $older->id)->assertJsonMissingPath('items.0.price')->assertJsonMissingPath('items.0.sku');
        $before = session(RecentlyViewedProducts::SESSION);
        $this->getJson('/search/recently-viewed')->assertOk();
        self::assertSame($before, session(RecentlyViewedProducts::SESSION));
        self::assertSame(0, DB::table('recently_viewed_products')->count());
        $this->flushSession();
        $this->getJson('/search/recently-viewed')->assertExactJson(['items' => []]);
    }

    public function test_customer_history_is_isolated_and_respects_customer_group_visibility(): void
    {
        $user = UserRecord::create(['name' => 'History customer', 'email' => 'history@example.test', 'password' => 'a long test passphrase']);
        $other = UserRecord::create(['name' => 'Another customer', 'email' => 'other-history@example.test', 'password' => 'a long test passphrase']);
        $product = $this->product('Member product');
        $otherProduct = $this->product('Other visitor product');
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $user->email, 'first_name' => 'History', 'last_name' => 'Customer']);
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        DB::table('product_customer_groups')->insert(['product_id' => $product->id, 'group_id' => $group]);
        DB::table('recently_viewed_products')->insert([
            ['user_id' => $user->id, 'product_id' => $product->id, 'viewed_at' => now()->subMinute()],
            ['user_id' => $other->id, 'product_id' => $otherProduct->id, 'viewed_at' => now()],
        ]);
        $this->actingAs($user)->getJson('/search/recently-viewed')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', (string) $product->id);
        $this->actingAs($other)->getJson('/search/recently-viewed')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', (string) $otherProduct->id);
        self::assertSame(2, DB::table('recently_viewed_products')->count());
    }

    public function test_history_is_bounded_to_twelve_and_hidden_while_the_store_is_closed(): void
    {
        $entries = [];
        $latest = null;
        for ($n = 0; $n < 14; $n++) {
            $product = $this->product('Viewed '.$n);
            $entries[$product->id] = now()->addSeconds($n)->toIso8601String();
            $latest = (string) $product->id;
        }
        $this->withSession([RecentlyViewedProducts::SESSION => $entries])->getJson('/search/recently-viewed')->assertOk()->assertJsonCount(12, 'items')->assertJsonPath('items.0.id', $latest);
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => false]);
        Cache::flush();
        $this->getJson('/search/recently-viewed')->assertExactJson(['items' => []]);
    }
}
