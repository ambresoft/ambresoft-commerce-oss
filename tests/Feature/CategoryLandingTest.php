<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductState;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CategoryLandingTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    /** Where the Category landing layout's main content block carries the page. */
    private const BLOCK = 'storefront.regions.content.0.blocks.0.content.';

    private UserRecord $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        app()->setLocale('en');
        $this->staff = $this->staff(super: true);
    }

    private function category(string $name, ?CategoryRecord $parent = null, bool $landing = false, int $position = 1, string $status = 'active'): CategoryRecord
    {
        $c = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => $position, 'status' => $status]);
        $c->forceFill(['is_landing_page' => $landing])->save();
        // Complete in every active language, as the editor requires.
        foreach (DB::table('locales')->where('is_active', true)->pluck('id', 'code') as $code => $locale) {
            $c->translations()->create(['locale_id' => $locale, 'name' => $code === 'en' ? $name : $name.' '.$code, 'slug' => Str::slug($name).($code === 'en' ? '' : '-'.$code),
                'seo_title' => 'Title', 'seo_description' => 'Description', 'image_alt' => 'Picture', 'meta_keywords' => 'keywords']);
        }

        return $c;
    }

    /** @return array<string, mixed> */
    private function form(CategoryRecord $c, array $changes): array
    {
        return ['parent_id' => $c->parent_id, 'position' => $c->position, 'status' => $c->status, 'version' => $c->fresh()->lock_version, 'translations' => [], 'remove_image' => false, ...$changes];
    }

    public function test_staff_turn_a_category_into_a_landing_page_but_never_one_that_holds_products(): void
    {
        $this->actingAs($this->staff);
        $tech = $this->category('Technology');
        $this->get('/admin/categories/'.$tech->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.isLandingPage', false));
        $this->put('/admin/categories/'.$tech->id, $this->form($tech, ['is_landing_page' => true]))->assertSessionHasNoErrors();
        self::assertTrue($tech->fresh()->is_landing_page);
        $this->get('/admin/categories/'.$tech->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.isLandingPage', true));
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'category')->where('changes', 'like', '%is_landing_page%')->exists());
        // Leaving the field out keeps the saved choice.
        $this->put('/admin/categories/'.$tech->id, $this->form($tech, []))->assertSessionHasNoErrors();
        self::assertTrue($tech->fresh()->is_landing_page);

        // A category with products stays a product category; products cannot go into a landing page.
        $phones = $this->category('Phones', $tech);
        ProductRecord::create(['sku' => 'LP-1', 'price' => '10', 'quantity' => 1, 'category_id' => $phones->id, 'status' => 'active']);
        $this->put('/admin/categories/'.$phones->id, $this->form($phones, ['is_landing_page' => true]))->assertSessionHasErrors('category');
        self::assertFalse($phones->fresh()->is_landing_page);
        $empty = $this->category('Empty landing', null, true, 2);
        self::assertFalse(app(ProductState::class)->validCategory((string) $empty->id));
        self::assertTrue(app(ProductState::class)->validCategory((string) $phones->id));
        // The system category never becomes one.
        $this->expectException(QueryException::class);
        DB::table('categories')->whereNotNull('system_key')->update(['is_landing_page' => true]);
    }

    public function test_a_landing_page_shows_its_visible_subcategories_in_order_with_its_trail(): void
    {
        $tech = $this->category('Technology', null, true);
        $gaming = $this->category('Gaming', $tech, true, 2);
        $this->category('Tablets', $tech, false, 1);
        $this->category('Hidden things', $tech, false, 3, 'disabled');
        $this->category('Consoles', $gaming);
        $this->category('Chairs', $gaming, false, 2);

        $this->get('/technology')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Category')
            ->where(self::BLOCK.'category.name', 'Technology')->where(self::BLOCK.'category.url', '/technology')
            ->has(self::BLOCK.'category.items', 2)->where(self::BLOCK.'category.items.0.name', 'Tablets')->where(self::BLOCK.'category.items.0.url', '/technology/tablets')
            ->where(self::BLOCK.'category.items.1.url', '/technology/gaming')
            // The main content block's own settings, with their defaults.
            ->where(self::BLOCK.'per_row', 5)->where(self::BLOCK.'card_shadow', true)->where(self::BLOCK.'mobile_display', 'grid')->where(self::BLOCK.'mobile_per_row', 2));
        // Without its own page title the category name is the title.
        DB::table('category_translations')->where('category_id', $gaming->id)->update(['seo_title' => '']);
        $this->get('/technology/gaming')->assertOk()->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.path.0.name', 'Technology')
            ->where(self::BLOCK.'category.path.1.name', 'Gaming')->has(self::BLOCK.'category.items', 2)->where('seo.title', 'Gaming'));

        // Product categories list their products with the Category layout; unknown and wrongly nested paths are not found.
        $this->get('/technology/tablets')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.1.blocks.0.content.listing.category.name', 'Tablets'));
        $this->get('/gaming')->assertNotFound();
        $this->get('/technology/nothing')->assertNotFound();
        // A disabled landing page is gone with everything under it.
        $tech->update(['status' => 'disabled']);
        $this->get('/technology')->assertNotFound();
        $this->get('/technology/gaming')->assertNotFound();
    }

    private function layoutTitle(int $layout, string $title): void
    {
        foreach (DB::table('locales')->pluck('id') as $locale) {
            DB::table('storefront_layout_translations')->updateOrInsert(['layout_id' => $layout, 'locale_id' => $locale], ['page_title' => $title]);
        }
    }

    public function test_the_layout_page_title_follows_the_dynamic_title_as_a_suffix(): void
    {
        $tech = $this->category('Technology', null, true);
        $this->category('Tablets', $tech);
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Title'));
        // Text typed in the layout's page title is added after the category's title exactly as written.
        $layout = DB::table('storefront_layouts')->where('page_type', 'category_landing')->value('id');
        $this->layoutTitle((int) $layout, '- Namestore');
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Title - Namestore'));
        // Without a title of its own the category name leads.
        DB::table('category_translations')->where('category_id', $tech->id)->update(['seo_title' => '']);
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Technology - Namestore'));
        // The same holds for products.
        $product = ProductRecord::create(['sku' => 'SUF-1', 'price' => '10', 'quantity' => 1, 'category_id' => CategoryRecord::where('parent_id', $tech->id)->value('id'), 'status' => 'active']);
        $product->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Tab one', 'slug' => 'tab-one', 'seo_title' => 'Tab one buy']);
        $products = DB::table('storefront_layouts')->where('page_type', 'products')->value('id');
        $this->layoutTitle((int) $products, '- Namestore');
        $this->get('/technology/tablets/tab-one')->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Tab one buy - Namestore'));
    }

    public function test_the_landing_layout_has_card_settings_on_its_main_content_block(): void
    {
        $this->actingAs($this->staff);
        $this->get('/admin/layouts/category_landing')->assertOk()->assertInertia(fn (Assert $page) => $page->where('layout.page_type', 'category_landing')->where('definitions.main_content.defaults.mobile_display', 'grid'));
        $layout = app(LayoutStore::class)->read('category_landing');
        $block = $layout['placements'][0]['block'];
        self::assertSame('main_content', $block['type']);
        $tech = $this->category('Technology', null, true);
        $this->category('Tablets', $tech);
        DB::table('storefront_blocks')->where('id', $block['id'])->update(['settings' => json_encode(['per_row' => 6, 'card_shadow' => false, 'mobile_display' => 'carousel', 'mobile_per_row' => 3])]);
        Cache::flush();
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'per_row', 6)->where(self::BLOCK.'card_shadow', false)
            ->where(self::BLOCK.'mobile_display', 'carousel')->where(self::BLOCK.'mobile_per_row', 3));
    }

    public function test_an_age_restricted_landing_page_shows_only_a_gate_until_the_visitor_confirms_the_age(): void
    {
        $adult = $this->category('Adult', landing: true);
        $adult->forceFill(['age_verification' => true, 'age_limit' => 18])->save();
        $this->category('Wines', $adult);
        DB::table('category_translations')->where('category_id', $adult->id)->update(['description' => '<p>Fine wines</p>']);

        // First visit: the gate, without the subcategories or the description.
        $this->get('/adult')->assertOk()->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.age_gate.age', 18)
            ->where(self::BLOCK.'category.age_gate.message', '')->has(self::BLOCK.'category.items', 0)->where(self::BLOCK.'category.description', ''));
        // The category's own warning replaces the default text.
        DB::table('category_translations')->where('category_id', $adult->id)->update(['age_warning_message' => 'Adults only.']);
        $this->get('/adult')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.age_gate.message', 'Adults only.'));

        // Confirming is remembered: the page opens, and so does any limit up to the confirmed age.
        $this->postJson('/age-verification', ['age' => 0])->assertJsonValidationErrors('age');
        $cookie = $this->postJson('/age-verification', ['age' => 18])->assertNoContent()->getCookie('age_verified')?->getValue();
        self::assertSame('18', (string) $cookie);
        $this->withCookie('age_verified', '18')->get('/adult')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.age_gate', null)
            ->has(self::BLOCK.'category.items', 1)->where(self::BLOCK.'category.description', '<p>Fine wines</p>'));
        $adult->forceFill(['age_limit' => 21])->save();
        $this->withCookie('age_verified', '18')->get('/adult')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.age_gate.age', 21));
    }

    public function test_a_landing_page_shows_its_active_questions_and_answers(): void
    {
        $tech = $this->category('Technology', landing: true);
        $en = (int) DB::table('locales')->where('code', 'en')->value('id');
        foreach ([['Do you ship abroad?', 'active', 1], ['Old question', 'disabled', 2]] as [$question, $status, $position]) {
            $faq = DB::table('faqs')->insertGetId(['type' => 'category', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('faq_translations')->insert(['faq_id' => $faq, 'locale_id' => $en, 'question' => $question, 'answer' => '<p>Yes</p><script>x()</script>']);
            DB::table('category_faqs')->insert(['category_id' => $tech->id, 'faq_id' => $faq, 'faq_type' => 'category', 'position' => $position]);
        }

        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'category.faqs', 1)
            ->where(self::BLOCK.'category.faqs.0.question', 'Do you ship abroad?')->where(self::BLOCK.'category.faqs.0.answer', '<p>Yes</p>'));
    }

    public function test_customer_groups_decide_who_sees_a_landing_page_and_its_subcategories(): void
    {
        $tech = $this->category('Technology', landing: true);
        $this->category('Tablets', $tech);
        $vip = $this->category('Vip corner', $tech, false, 2);
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_customer_groups')->insert(['category_id' => $vip->id, 'group_id' => $group]);

        // A guest sees only the open subcategory.
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'category.items', 1)->where(self::BLOCK.'category.items.0.name', 'Tablets'));
        // Limiting the landing page itself hides it from everyone outside the group.
        DB::table('category_customer_groups')->insert(['category_id' => $tech->id, 'group_id' => $group]);
        $this->get('/technology')->assertNotFound();

        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => 'ria@example.test', 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => 'ria@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        $this->actingAs($user)->get('/technology')->assertOk()->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'category.items', 2));
    }

    public function test_a_landing_page_shows_its_banner_image_unless_the_age_gate_is_up(): void
    {
        $tech = $this->category('Technology', landing: true);
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.banner', null));
        Storage::disk('local')->put('test/banner.png', 'png');
        $media = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'test/banner.png', 'mime_type' => 'image/png', 'byte_size' => 3, 'width' => 1600, 'height' => 400, 'created_at' => now(), 'updated_at' => now()]);
        $tech->forceFill(['banner_image_media_id' => $media])->save();

        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.banner.width', 1600)->where(self::BLOCK.'category.banner_alt', 'Picture')->where(self::BLOCK.'category.banner_link', null));
        $tech->forceFill(['banner_link' => '/technology/tablets'])->save();
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.banner_link', '/technology/tablets'));
        // The storefront serves the picture as the category's own.
        $this->get('/storefront/media/category/'.$tech->id.'/'.$media)->assertOk();
        $tech->forceFill(['age_verification' => true, 'age_limit' => 18])->save();
        $this->get('/technology')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'category.banner', null));
    }
}
