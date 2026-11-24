<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\DirectoryPages;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class DirectoryPagesTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const DATA = 'storefront.regions.content.0.blocks.0.content.directory';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    private function category(string $name, ?CategoryRecord $parent = null, string $status = 'active'): CategoryRecord
    {
        $row = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => 1, 'status' => $status]);
        foreach (DB::table('locales')->pluck('id', 'code') as $code => $locale) {
            $row->translations()->create(['locale_id' => $locale, 'name' => $name.($code === 'en' ? '' : ' '.$code), 'slug' => Str::slug($name).($code === 'en' ? '' : '-'.$code)]);
        }

        return $row;
    }

    private function page(string $name, string $status = 'active', bool $linked = true): int
    {
        $id = DB::table('pages')->insertGetId(['status' => $status, 'position' => 1]);
        foreach (DB::table('locales')->pluck('id', 'code') as $code => $locale) {
            DB::table('page_translations')->insert(['page_id' => $id, 'locale_id' => $locale, 'name' => $name.($code === 'en' ? '' : ' '.$code), 'slug' => Str::slug($name).($code === 'en' ? '' : '-'.$code)]);
        }
        if ($linked) {
            DB::table('storefront_layouts')->insert(['page_type' => 'custom.page'.$id, 'page_id' => $id]);
        }

        return $id;
    }

    private function brand(string $code, string $en, ?string $el = null, string $status = 'active'): int
    {
        $id = DB::table('brands')->insertGetId(['code' => $code, 'status' => $status]);
        DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => $en]);
        if ($el !== null) {
            DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'el')->value('id'), 'name' => $el]);
        }

        return $id;
    }

    public function test_both_default_layouts_render_and_have_protected_main_content(): void
    {
        foreach (['sitemap', 'brands'] as $kind) {
            $this->get('/'.$kind)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page->component('Storefront/Page')->where(self::DATA.'.kind', $kind));
            $layout = app(LayoutStore::class)->read($kind);
            $this->assertCount(1, $layout['placements']);
            $this->assertSame(16, $layout['grids'][0]['width']);
        }
        $this->actingAs($this->staff(super: true));
        $this->get('/admin/layouts/sitemap')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tabs', fn ($tabs) => collect($tabs)->whereIn('key', ['sitemap', 'brands'])->count() === 2));
        $layout = app(LayoutStore::class)->read('sitemap');
        $layout['placements'] = [];
        $this->put('/admin/layouts/sitemap', $layout)->assertSessionHasErrors();
    }

    public function test_sitemap_lists_published_information_pages_and_nested_visible_categories_only(): void
    {
        $privacy = $this->page('Privacy');
        $this->page('Hidden', 'hidden');
        $this->page('Disabled', 'disabled');
        $this->page('Unlinked', linked: false);
        $future = $this->page('Future');
        $expired = $this->page('Expired');
        DB::table('pages')->where('id', $future)->update(['available_from' => now()->addDay()]);
        DB::table('pages')->where('id', $expired)->update(['available_until' => now()->subDay()]);
        $tech = $this->category('Technology');
        $phones = $this->category('Phones', $tech);
        $this->category('Smartphones', $phones);
        $this->page('Technology');
        $this->page('Login');
        $disabled = $this->category('Disabled category', status: 'disabled');
        $this->category('Disabled descendant', $disabled);
        $this->get('/sitemap')->assertInertia(fn (Assert $page) => $page->where(self::DATA.'.pages', [['name' => 'Privacy', 'url' => '/privacy'], ['name' => 'All brands', 'url' => '/brands']])
            ->has(self::DATA.'.categories', 1)->where(self::DATA.'.categories.0.url', '/technology')
            ->where(self::DATA.'.categories.0.children.0.children.0.url', '/technology/phones/smartphones'));
        DB::table('pages')->where('id', $privacy)->update(['status' => 'disabled']);
        // Content stays live even after the layout structure has been cached.
        $this->get('/sitemap')->assertInertia(fn (Assert $page) => $page->has(self::DATA.'.pages', 1));
    }

    public function test_sitemap_does_not_expose_group_restricted_pages_or_category_descendants(): void
    {
        $page = $this->page('Members');
        $category = $this->category('Members category');
        $this->category('Members child', $category);
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('page_customer_groups')->insert(['page_id' => $page, 'group_id' => $group]);
        DB::table('category_customer_groups')->insert(['category_id' => $category->id, 'group_id' => $group]);
        $this->get('/sitemap')->assertInertia(fn (Assert $page) => $page->has(self::DATA.'.pages', 1)->has(self::DATA.'.categories', 0));
        $user = UserRecord::create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'a customer passphrase']);
        $user->markEmailAsVerified();
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $user->email, 'first_name' => 'Member', 'last_name' => 'Customer', 'status' => 'active']);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        $this->actingAs($user)->get('/sitemap')->assertInertia(fn (Assert $page) => $page->has(self::DATA.'.pages', 2)->has(self::DATA.'.categories.0.children', 1));
    }

    public function test_disabled_cart_checkout_and_wishlist_shortcuts_are_omitted(): void
    {
        DB::table('general_settings')->where('id', 1)->update(['cart_enabled' => false, 'wishlist_enabled' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $this->get('/sitemap')->assertInertia(fn (Assert $page) => $page->where(self::DATA.'.account', [['name' => 'My account', 'url' => '/account']]));
    }

    public function test_brand_index_is_localized_sorted_grouped_and_excludes_disabled_brands(): void
    {
        $this->brand('zebra', 'Zebra', 'Ζέβρα');
        $this->brand('apple', 'Apple', 'Άλφα');
        $this->brand('acer', 'Acer', 'Αθήνα');
        $this->brand('three', '3M', '3M');
        $this->brand('disabled-brand', 'Disabled brand', status: 'disabled');
        DB::table('brands')->insert(['code' => 'empty-brand', 'status' => 'active']);
        $data = app(DirectoryPages::class)->brands('en');
        $groups = array_column($data['groups'], 'items', 'letter');
        $this->assertSame(['Acer', 'Apple'], array_column($groups['A'], 'name'));
        $this->assertSame('/apple', $groups['A'][1]['url']);
        $this->assertSame('3M', $groups['#'][0]['name']);
        $this->assertSame(['#', 'A', 'Z'], array_column($data['groups'], 'letter'));
        $this->get('/locale/el');
        $this->get('/brands')->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Όλες οι μάρκες')->where(self::DATA.'.groups.1.letter', 'Α')->where(self::DATA.'.groups.1.items.0.name', 'Αθήνα'));
    }

    public function test_brand_links_follow_landing_source_and_reserved_url_rules(): void
    {
        $this->brand('apple', 'Apple');
        $this->brand('sitemap', 'Reserved');
        $groups = app(DirectoryPages::class)->brands('en')['groups'];
        $this->assertNull($groups[1]['items'][0]['url']);
        DB::table('general_settings')->where('id', 1)->update(['brand_landing_pages' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $this->assertNull(app(DirectoryPages::class)->brands('en')['groups'][0]['items'][0]['url']);
    }

    public function test_directory_layout_seo_is_editable_and_closed_stores_keep_the_closure_page(): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'sitemap')->value('id');
        DB::table('storefront_layout_translations')->where('layout_id', $layout)->where('locale_id', DB::table('locales')->where('code', 'en')->value('id'))->update(['page_title' => 'Our store map']);
        DB::table('storefront_layouts')->where('id', $layout)->update(['indexable' => false]);
        $this->get('/sitemap')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Our store map'));
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => false]);
        app()->forgetInstance(GeneralSettings::class);
        foreach (['sitemap', 'brands'] as $path) {
            $this->get('/'.$path)->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('StoreClosed'));
        }
    }

    public function test_layout_migration_is_idempotent_and_does_not_replace_merchant_changes(): void
    {
        DB::table('storefront_blocks')->where('layout_id', DB::table('storefront_layouts')->where('page_type', 'sitemap')->value('id'))->update(['name' => 'Merchant name']);
        $migration = require database_path('migrations/2026_11_13_000100_add_storefront_directory_layouts.php');
        $migration->up();
        $this->assertSame(2, DB::table('storefront_layouts')->whereIn('page_type', ['sitemap', 'brands'])->count());
        $this->assertTrue(DB::table('storefront_blocks')->where('name', 'Merchant name')->exists());
    }
}
