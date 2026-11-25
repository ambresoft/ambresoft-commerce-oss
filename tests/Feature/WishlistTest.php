<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Customer\Infrastructure\Wishlists;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class WishlistTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Audio', 'slug' => 'audio']);
    }

    private function product(string $slug, string $status = 'active'): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'WL-'.Str::upper(Str::random(6)), 'price' => '10', 'quantity' => 5, 'category_id' => $this->category->id, 'status' => $status]);
        $p->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => Str::headline($slug), 'slug' => $slug]);

        return $p;
    }

    /** A signed-in customer (verified, linked profile). */
    private function customer(string $email = 'ria@example.test'): array
    {
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => $email, 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $id = (int) DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $email, 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $id];
    }

    /** @return array<string, mixed> */
    private function lists(array $json): array
    {
        return array_column($json['lists'], null, 'id');
    }

    public function test_only_signed_in_customers_reach_wishlists(): void
    {
        $p = $this->product('guest-speaker');
        $this->get('/wishlist')->assertRedirect('/login');
        $this->getJson('/wishlists/product/'.$p->id)->assertUnauthorized();
        $this->postJson('/wishlists', ['name' => 'Mine'])->assertUnauthorized();
        $this->putJson('/wishlists/1/products/'.$p->id)->assertUnauthorized();
        // Staff without a customer profile are not customers either.
        $this->actingAs($this->staff(super: true));
        $this->getJson('/wishlists/product/'.$p->id)->assertRedirect();
    }

    public function test_the_general_list_holds_every_saved_product_and_named_lists_group_some_of_them(): void
    {
        [$user, $customer] = $this->customer();
        $this->actingAs($user);
        [$a, $b, $c] = [$this->product('speaker-a'), $this->product('speaker-b'), $this->product('speaker-c')];

        // The general list exists from the first visit; saving to it holds the product in it only.
        $json = $this->getJson('/wishlists/product/'.$a->id)->assertOk()->json();
        self::assertCount(1, $json['lists']);
        self::assertTrue($json['lists'][0]['default']);
        self::assertFalse($json['favorite']);
        $general = $json['lists'][0]['id'];
        $this->putJson('/wishlists/'.$general.'/products/'.$a->id)->assertOk()->assertJsonPath('favorite', true);

        // A new list with a product saves it there and in the general list; named lists are optional groupings.
        $created = $this->postJson('/wishlists', ['name' => '  Black   Friday ', 'product_id' => $b->id])->assertOk()->json();
        $black = $created['created'];
        self::assertSame('Black Friday', $this->lists($created)[$black]['name']);
        self::assertSame([true, true], [$this->lists($created)[$black]['has'], $this->lists($created)[$general]['has']]);
        $this->putJson('/wishlists/'.$black.'/products/'.$c->id)->assertOk();
        self::assertSame([3, 2], [DB::table('wishlist_items')->where('wishlist_id', $general)->count(), DB::table('wishlist_items')->where('wishlist_id', $black)->count()]);

        // Adding twice changes nothing; removing from a named list keeps the product in the general list.
        $this->putJson('/wishlists/'.$black.'/products/'.$c->id)->assertOk();
        $this->deleteJson('/wishlists/'.$black.'/products/'.$c->id)->assertOk()->assertJsonPath('favorite', true);
        self::assertSame([3, 1], [DB::table('wishlist_items')->where('wishlist_id', $general)->count(), DB::table('wishlist_items')->where('wishlist_id', $black)->count()]);

        // Removing from the general list removes the product from every list.
        $this->deleteJson('/wishlists/'.$general.'/products/'.$b->id)->assertOk()->assertJsonPath('favorite', false);
        self::assertSame([2, 0], [DB::table('wishlist_items')->where('wishlist_id', $general)->count(), DB::table('wishlist_items')->where('wishlist_id', $black)->count()]);

        // Renaming and deleting a named list; its products stay in the general list. The general list is fixed.
        $this->patchJson('/wishlists/'.$black, ['name' => 'Gifts'])->assertOk();
        self::assertSame('Gifts', DB::table('wishlists')->where('id', $black)->value('name'));
        $this->patchJson('/wishlists/'.$general, ['name' => 'Mine'])->assertNotFound();
        $this->deleteJson('/wishlists/'.$general)->assertNotFound();
        $this->deleteJson('/wishlists/'.$black)->assertOk();
        $this->assertDatabaseMissing('wishlists', ['id' => $black]);
        self::assertSame(2, DB::table('wishlist_items')->where('wishlist_id', $general)->count());
    }

    public function test_list_names_and_limits_are_validated(): void
    {
        [$user] = $this->customer();
        $this->actingAs($user);
        $this->postJson('/wishlists', ['name' => 'Gifts'])->assertOk();
        foreach (['gifts', ' GIFTS '] as $duplicate) {
            $this->postJson('/wishlists', ['name' => $duplicate])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        foreach (['', '   ', str_repeat('a', 61), "Bad\x07name", '<b>x</b>'] as $invalid) {
            $this->postJson('/wishlists', ['name' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        // The same name is free for another customer; a customer can have at most 50 named lists.
        [$other] = $this->customer('other@example.test');
        $this->flushSession();
        $this->actingAs($other)->postJson('/wishlists', ['name' => 'Gifts'])->assertOk();
        $this->flushSession();
        $this->actingAs($user);
        for ($n = 2; $n <= Wishlists::MAX_LISTS; $n++) {
            $this->postJson('/wishlists', ['name' => 'List '.$n])->assertOk();
        }
        $this->postJson('/wishlists', ['name' => 'One too many'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_nobody_reaches_another_customers_lists_and_only_visible_products_can_be_saved(): void
    {
        [$owner, $ownerId] = $this->customer('owner@example.test');
        [$intruder] = $this->customer('intruder@example.test');
        $p = $this->product('private-speaker');
        $this->actingAs($owner);
        $created = $this->postJson('/wishlists', ['name' => 'Secret', 'product_id' => $p->id])->json();
        $list = $created['created'];
        $this->flushSession();
        $this->actingAs($intruder);
        $this->putJson('/wishlists/'.$list.'/products/'.$p->id)->assertNotFound();
        $this->deleteJson('/wishlists/'.$list.'/products/'.$p->id)->assertNotFound();
        $this->patchJson('/wishlists/'.$list, ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson('/wishlists/'.$list)->assertNotFound();
        self::assertSame('Secret', DB::table('wishlists')->where('id', $list)->value('name'));
        // The intruder's own state shows nothing of the owner's lists.
        self::assertCount(1, $this->getJson('/wishlists/product/'.$p->id)->json('lists'));

        // Disabled, hidden and deleted products cannot be saved.
        $this->flushSession();
        $this->actingAs($owner);
        foreach (['disabled', 'hidden'] as $status) {
            $x = $this->product('x-'.$status, $status);
            $this->putJson('/wishlists/'.$list.'/products/'.$x->id)->assertUnprocessable()->assertJsonValidationErrors('product');
        }
        $gone = $this->product('gone-speaker');
        $gone->delete();
        $this->putJson('/wishlists/'.$list.'/products/'.$gone->id)->assertUnprocessable();
        $this->postJson('/wishlists', ['name' => 'Another', 'product_id' => $gone->id])->assertUnprocessable();
        $this->assertDatabaseMissing('wishlists', ['name' => 'Another']);
    }

    public function test_the_wishlist_page_shows_visible_saved_products_in_a_configurable_grid(): void
    {
        [$user] = $this->customer();
        $this->actingAs($user);
        $products = array_map(fn (string $s) => $this->product($s), ['one', 'two', 'three', 'four']);
        $general = $this->getJson('/wishlists/product/'.$products[0]->id)->json('lists.0.id');
        foreach ($products as $n => $p) {
            DB::table('wishlist_items')->insertOrIgnore(['wishlist_id' => $general, 'product_id' => $p->id, 'created_at' => now()->addSeconds($n)]);
        }
        $black = $this->postJson('/wishlists', ['name' => 'Black Friday', 'product_id' => $products[1]->id])->json('created');
        $main = function (Assert $page): array {
            foreach ($page->toArray()['props']['storefront']['regions']['content'] as $grid) {
                foreach ($grid['blocks'] as $block) {
                    if ($block['template'] === 'main_content') {
                        return $block['content'];
                    }
                }
            }
            self::fail('No main content block');
        };
        $content = null;
        $this->get('/wishlist')->assertOk()->assertHeader('X-Robots-Tag')->assertSee('name="robots"', false)
            ->assertInertia(function (Assert $page) use (&$content, $main): void {
                $page->component('Storefront/Wishlist')->where('storefront.signed_in', true);
                $content = $main($page);
            });
        $wishlist = $content['wishlist'];
        // Newest first, every card flagged as saved; the grid default is five per row.
        self::assertSame(['Four', 'Three', 'Two', 'One'], array_column($wishlist['items'], 'name'));
        self::assertSame([true], array_values(array_unique(array_column($wishlist['items'], 'favorite'))));
        self::assertSame(5, $content['per_row']);
        self::assertSame([4, 1], [$wishlist['lists'][0]['count'], $wishlist['lists'][1]['count']]);
        self::assertSame('Black Friday', $wishlist['lists'][1]['name']);
        // A named list shows only its products; an unknown list id falls back to the general list.
        $this->get('/wishlist?list='.$black)->assertInertia(function (Assert $page) use ($main, $black): void {
            self::assertSame(['Two'], array_column($main($page)['wishlist']['items'], 'name'));
            self::assertSame($black, $main($page)['wishlist']['current']);
        });
        $this->get('/wishlist?list=999999')->assertInertia(fn (Assert $page) => self::assertCount(4, $main($page)['wishlist']['items']));
        // Products that stop being visible leave the page and the counts, and return when visible again.
        DB::table('products')->where('id', $products[2]->id)->update(['status' => 'hidden']);
        $this->get('/wishlist')->assertInertia(function (Assert $page) use ($main): void {
            self::assertSame(['Four', 'Two', 'One'], array_column($main($page)['wishlist']['items'], 'name'));
            self::assertSame(3, $main($page)['wishlist']['lists'][0]['count']);
        });
        DB::table('products')->where('id', $products[2]->id)->update(['status' => 'active']);
        // Product pages carry the heart's saved state too.
        $this->get('/audio/one')->assertOk()->assertInertia(function (Assert $page) use ($main): void {
            self::assertTrue($main($page)['product']['favorite']);
        });
    }

    public function test_the_wishlist_layout_has_a_protected_main_content_block_with_a_per_row_setting(): void
    {
        $this->actingAs($this->staff(super: true));
        $this->get('/admin/layouts/wishlist')->assertOk()->assertInertia(fn (Assert $p) => $p->where('layout.page_type', 'wishlist')->where('pageKind', 'wishlist')
            ->where('definitions.main_content.creatable', false)->where('definitions.main_content.pages', ['products', 'category_landing', 'category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search', 'cart', 'checkout', 'wishlist', 'account', 'comparison', 'order_tracking', 'sitemap', 'brands', 'blog', 'blog_post', 'homepage'])
            ->where('definitions.main_content.fields', fn ($fields) => collect($fields)->firstWhere('key', 'per_row')['pages'] === ['wishlist', 'category_landing'])->where('definitions.main_content.defaults.per_row', 5));
        $layout = app(LayoutStore::class)->read('wishlist');
        $main = $layout['placements'][0];
        self::assertSame('main_content', $main['block']['type']);
        $this->put('/admin/layouts/wishlist', [...$layout, 'placements' => []])->assertSessionHasErrors('placements');
        foreach ([0, 9, 'x'] as $bad) {
            $main['block']['settings'] = ['per_row' => $bad];
            $this->put('/admin/layouts/wishlist', [...$layout, 'placements' => [$main]])->assertSessionHasErrors();
        }
        $main['block']['settings'] = ['per_row' => 3];
        $this->put('/admin/layouts/wishlist', [...$layout, 'placements' => [$main]])->assertSessionHasNoErrors();

        [$user] = $this->customer();
        $this->flushSession();
        $this->actingAs($user)->get('/wishlist')->assertInertia(function (Assert $page): void {
            $content = $page->toArray()['props']['storefront']['regions']['content'][0]['blocks'][0]['content'];
            self::assertSame(3, $content['per_row']);
        });
    }

    public function test_the_store_wide_switch_removes_wishlists_everywhere(): void
    {
        [$user] = $this->customer();
        $p = $this->product('switch-speaker');
        $this->actingAs($user);
        $general = $this->getJson('/wishlists/product/'.$p->id)->assertOk()->json('lists.0.id');
        $this->putJson('/wishlists/'.$general.'/products/'.$p->id)->assertOk();
        self::assertTrue(app(GeneralSettings::class)->all()['wishlist_enabled']);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('storefront.wishlist_enabled', true));
        $card = fn (Assert $page) => $page->toArray()['props']['storefront'];

        // Switched off: no page, no endpoints, no hearts' saved state; the lists are kept.
        DB::table('general_settings')->update(['wishlist_enabled' => false]);
        Cache::flush();
        $this->get('/wishlist')->assertNotFound();
        $this->getJson('/wishlists/product/'.$p->id)->assertNotFound();
        $this->postJson('/wishlists', ['name' => 'Gifts'])->assertNotFound();
        $this->putJson('/wishlists/'.$general.'/products/'.$p->id)->assertNotFound();
        $this->get('/audio/switch-speaker')->assertOk()->assertInertia(function (Assert $page) use ($card): void {
            self::assertFalse($card($page)['wishlist_enabled']);
            foreach ($card($page)['regions']['content'] as $grid) {
                foreach ($grid['blocks'] as $block) {
                    if ($block['template'] === 'main_content') {
                        self::assertFalse($block['content']['product']['favorite']);
                    }
                }
            }
        });
        self::assertSame(1, DB::table('wishlist_items')->count());

        // Switched on again, the saved list is back.
        DB::table('general_settings')->update(['wishlist_enabled' => true]);
        Cache::flush();
        $this->get('/wishlist')->assertOk();
        $this->get('/audio/switch-speaker')->assertInertia(function (Assert $page) use ($card): void {
            foreach ($card($page)['regions']['content'] as $grid) {
                foreach ($grid['blocks'] as $block) {
                    if ($block['template'] === 'main_content') {
                        self::assertTrue($block['content']['product']['favorite']);
                    }
                }
            }
        });
    }

    public function test_the_wishlist_switch_is_a_catalog_setting_that_is_saved_and_audited(): void
    {
        $this->actingAs($this->staff(super: true));
        $this->get('/admin/settings/general?tab=catalog')->assertOk()->assertInertia(fn (Assert $page) => $page->where('settings.wishlist_enabled', true));
        $version = (int) DB::table('general_settings')->value('lock_version');
        app(GeneralSettings::class)->save((string) $this->staff(super: true)->id, ['version' => $version, 'out_of_stock_action' => 'N', 'wishlist_enabled' => false]);
        self::assertFalse((bool) DB::table('general_settings')->value('wishlist_enabled'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'general_settings']);
    }
}
