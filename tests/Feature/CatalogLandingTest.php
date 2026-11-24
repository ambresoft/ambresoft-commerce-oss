<?php

namespace Tests\Feature;

use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\CatalogLandingPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CatalogLandingTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    private function source(bool $brands): void
    {
        DB::table('general_settings')->where('id', 1)->update(['brand_landing_pages' => $brands]);
        app()->forgetInstance(GeneralSettings::class);
    }

    private function brand(string $code = 'apple'): string
    {
        $id = (string) DB::table('brands')->insertGetId(['code' => $code, 'status' => 'active']);
        foreach (DB::table('locales')->pluck('id', 'code') as $locale => $localeId) {
            DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => $localeId, 'name' => $locale === 'el' ? 'Μήλο' : 'Apple', 'description' => '<p>Brand description</p>']);
        }

        return $id;
    }

    /** Preserved feature-option content from an installation using the legacy source. */
    private function option(): array
    {
        $feature = (string) DB::table('features')->insertGetId(['position' => 1, 'code' => 'LEGACY-BRAND', 'type' => 'single', 'purpose' => 'landing_pages', 'group_id' => DB::table('feature_groups')->where('system_key', 'general')->value('id')]);
        $option = (string) DB::table('feature_options')->insertGetId(['position' => 1, 'feature_id' => $feature]);
        foreach (DB::table('locales')->pluck('id', 'code') as $locale => $localeId) {
            DB::table('feature_translations')->insert(['feature_id' => $feature, 'locale_id' => $localeId, 'name' => 'Legacy brand']);
            DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $localeId, 'label' => 'Legacy Apple', 'slug' => 'apple', 'description' => '<p>Legacy description</p>', 'seo_title' => 'Apple legacy SEO']);
        }

        return [$feature, $option];
    }

    public function test_default_brand_page_and_exclusive_legacy_source(): void
    {
        $brand = $this->brand();
        [$feature, $option] = $this->option();
        // The brand heading across the page, then the filters beside the listing (empty: the brand has no products).
        $this->get('/apple')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Page')->where('seo.title', 'Apple')
            ->where('storefront.regions.content.0.width', 16)->where('storefront.regions.content.0.blocks.0.template', 'page_header')
            ->where('storefront.regions.content.0.blocks.0.content.brand_page.id', $brand)
            ->where('storefront.regions.content.0.blocks.0.content.brand_page.description', '<p>Brand description</p>')
            ->where('storefront.regions.content.1.blocks.0.template', 'product_filters')
            ->where('storefront.regions.content.2.blocks.0.content.listing.brand.source', 'brand')
            ->where('storefront.regions.content.2.blocks.0.content.listing.items', [])
            ->where('storefront.regions.content.2.blocks.0.content.listing.total', 0));
        self::assertSame(['apple' => '/apple'], app(CatalogLandingPages::class)->brandUrls(['apple']));
        $this->source(false);
        DB::table('feature_option_translations')->where('option_id', $option)->where('locale_id', DB::table('locales')->where('code', 'el')->value('id'))->delete();
        self::assertSame('Legacy Apple', app(CatalogLandingPages::class)->resolve('/apple', 'el')['name']);
        $this->get('/apple')->assertOk()->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Apple legacy SEO')
            ->where('storefront.regions.content.0.blocks.0.content.brand_page.source', 'feature_option')
            ->where('storefront.regions.content.0.blocks.0.content.brand_page.id', $option));
        self::assertSame([], app(CatalogLandingPages::class)->brandUrls(['apple']));
        DB::table('feature_options')->where('id', $option)->update(['status' => 'archived']);
        $this->get('/apple')->assertNotFound();
        $this->source(true);
        $this->get('/apple')->assertOk();
        DB::table('brands')->where('id', $brand)->update(['status' => 'disabled']);
        DB::table('feature_options')->where('id', $option)->update(['status' => 'active']);
        $this->get('/apple')->assertNotFound();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('feature_option_translations', ['option_id' => $option, 'seo_title' => 'Apple legacy SEO']);
    }

    public function test_landing_pages_respect_localization_and_existing_urls(): void
    {
        $this->brand();
        self::assertSame('Μήλο', app(CatalogLandingPages::class)->resolve('/apple', 'el')['name']);
        self::assertSame('Apple', app(CatalogLandingPages::class)->resolve('/apple', 'fr')['name']);
        $this->brand('login');
        self::assertNull(app(CatalogLandingPages::class)->resolve('/login', 'en'));
        $category = DB::table('categories')->insertGetId(['position' => 1, 'status' => 'active']);
        DB::table('category_translations')->insert(['category_id' => $category, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Apple category', 'slug' => 'apple']);
        self::assertSame([], app(CatalogLandingPages::class)->brandUrls(['apple', 'login']));
        // The category owns the address: it lists its products, never the brand.
        $this->get('/apple')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Category'));
        DB::table('categories')->where('id', $category)->delete();
        $page = DB::table('pages')->insertGetId(['status' => 'disabled', 'position' => 0]);
        DB::table('page_translations')->insert(['page_id' => $page, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Reserved Apple', 'slug' => 'apple']);
        self::assertSame([], app(CatalogLandingPages::class)->brandUrls(['apple']));
        $this->get('/apple')->assertNotFound();
        self::assertNull(app(CatalogLandingPages::class)->resolve('/nested/apple', 'en'));
    }

    public function test_localized_slug_takes_priority_over_another_options_default_fallback(): void
    {
        $this->source(false);
        [$feature, $apple] = $this->option();
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $el = DB::table('locales')->where('code', 'el')->value('id');
        DB::table('feature_option_translations')->where('option_id', $apple)->where('locale_id', $el)->delete();
        $other = DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'position' => 2]);
        DB::table('feature_option_translations')->insert([
            ['option_id' => $other, 'locale_id' => $en, 'label' => 'Other', 'slug' => 'other'],
            ['option_id' => $other, 'locale_id' => $el, 'label' => 'Τοπικό', 'slug' => 'apple'],
        ]);
        self::assertSame((string) $other, app(CatalogLandingPages::class)->resolve('/apple', 'el')['id']);
        self::assertSame($apple, app(CatalogLandingPages::class)->resolve('/apple', 'en')['id']);
    }

    public function test_legacy_image_delivery_checks_source_status_and_ownership(): void
    {
        Storage::fake('local');
        [$feature, $option] = $this->option();
        Storage::disk('local')->put('landing.png', 'image');
        $image = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'landing.png', 'mime_type' => 'image/png', 'byte_size' => 5, 'width' => 1, 'height' => 1]);
        DB::table('feature_options')->where('id', $option)->update(['landing_image_media_id' => $image]);
        $url = '/storefront/media/feature-option/'.$option.'/'.$image;
        $this->get($url)->assertNotFound();
        $this->source(false);
        $this->get($url)->assertOk();
        $this->get('/storefront/media/feature-option/999999/'.$image)->assertNotFound();
        DB::table('feature_options')->where('id', $option)->update(['status' => 'archived']);
        $this->get($url)->assertNotFound();
        DB::table('feature_options')->where('id', $option)->update(['status' => 'active']);
        DB::table('features')->where('id', $feature)->update(['status' => 'archived']);
        $this->get($url)->assertNotFound();
        $this->get('/apple')->assertNotFound();
    }

    public function test_source_change_requires_seo_confirmation_and_is_audited(): void
    {
        $this->actingAs($this->staff(super: true));
        $payload = ['version' => 1, 'out_of_stock_action' => 'N', 'brand_landing_pages' => false];
        $this->put('/admin/settings/general', $payload)->assertSessionHasErrors('brand_landing_pages');
        $this->assertDatabaseHas('general_settings', ['id' => 1, 'brand_landing_pages' => true, 'lock_version' => 1]);
        $this->put('/admin/settings/general', [...$payload, 'brand_landing_pages_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('general_settings', ['id' => 1, 'brand_landing_pages' => false, 'lock_version' => 2]);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%brand_landing_pages%')->exists());
        $this->put('/admin/settings/general', [...$payload, 'version' => 2])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->put('/admin/settings/general', [...$payload, 'version' => 3, 'brand_landing_pages_confirmed' => true])->assertForbidden();
    }

    public function test_changing_source_requires_its_own_revocable_capability(): void
    {
        $permission = DB::table('permissions')->where('name', 'settings.brand-landing-pages')->value('id');
        self::assertNotNull($permission);
        self::assertSame(0, DB::table('permission_role')->where('permission_id', $permission)->count());
        $editor = $this->staff(['admin.access', 'settings.manage']);
        $this->actingAs($editor);
        $this->get('/admin/settings/general')->assertOk()->assertInertia(fn (Assert $p) => $p->where('capabilities', fn ($caps) => $caps['settings.brand-landing-pages'] === false));
        $payload = ['version' => 1, 'out_of_stock_action' => 'B', 'brand_landing_pages' => false, 'brand_landing_pages_confirmed' => true];
        $this->put('/admin/settings/general', $payload)->assertForbidden();
        $this->assertDatabaseHas('general_settings', ['brand_landing_pages' => true, 'out_of_stock_action' => 'N', 'lock_version' => 1]);
        // Ordinary settings saves may include the read-only source's unchanged value.
        $this->put('/admin/settings/general', [...$payload, 'brand_landing_pages' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('general_settings', ['brand_landing_pages' => true, 'out_of_stock_action' => 'B', 'lock_version' => 2]);
        $editor->roles()->firstOrFail()->permissions()->attach($permission);
        $this->put('/admin/settings/general', [...$payload, 'version' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('general_settings', ['brand_landing_pages' => false, 'lock_version' => 3]);
        $editor->roles()->firstOrFail()->permissions()->detach($permission);
        $this->put('/admin/settings/general', [...$payload, 'version' => 3, 'brand_landing_pages' => true])->assertForbidden();
        $this->assertDatabaseHas('general_settings', ['brand_landing_pages' => false, 'lock_version' => 3]);
    }

    public function test_brand_mode_blocks_landing_metadata_but_keeps_labels_and_variations_editable(): void
    {
        $this->actingAs($this->staff(super: true));
        [$feature, $option] = $this->option();
        $definition = ['version' => 1, 'type' => 'single', 'purpose' => 'landing_pages', 'required' => false, 'filterable' => false, 'comparison' => false, 'categories' => [], 'translations' => ['en' => ['name' => 'New brand']]];
        $this->post('/admin/features', $definition)->assertSessionHasErrors('purpose');
        $this->post('/admin/features/'.$feature, $definition)->assertSessionHasNoErrors();
        $payload = ['feature_version' => 2, 'version' => 1, 'action' => 'save', 'translations' => ['en' => ['label' => 'New label', 'seo_title' => 'Forbidden']]];
        $this->post('/admin/features/'.$feature.'/options/'.$option, $payload)->assertSessionHasErrors('translations.en');
        $payload['translations']['en'] = ['label' => 'New label'];
        $this->post('/admin/features/'.$feature.'/options/'.$option, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('feature_option_translations', ['option_id' => $option, 'label' => 'New label', 'seo_title' => 'Apple legacy SEO']);
        $this->post('/admin/features', [...$definition, 'purpose' => 'variations', 'translations' => ['en' => ['name' => 'Size']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('features', ['purpose' => 'variations']);
    }
}
