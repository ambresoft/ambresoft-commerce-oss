<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Customer\Infrastructure\CustomerInput;
use App\Http\Middleware\KeepOutOfSearchEngines;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class GeneralPreferencesTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @param array<string, mixed> $fields */
    private function save(array $fields, int $version = 1): void
    {
        app(GeneralSettings::class)->save((string) $this->actor->id, ['version' => $version, 'out_of_stock_action' => 'N', ...$fields]);
    }

    public function test_bestseller_settings_preserve_defaults_validate_and_audit_changes(): void
    {
        $settings = app(GeneralSettings::class)->all();
        self::assertSame([0, 1, 1, []], [$settings['bestsellers_days'], $settings['bestsellers_min_units'], $settings['bestsellers_min_orders'], $settings['bestsellers_excluded_categories']]);
        foreach (['bestsellers_days' => -1, 'bestsellers_min_units' => 0, 'bestsellers_min_orders' => 0, 'bestsellers_excluded_categories' => ['999999999']] as $field => $invalid) {
            $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', $field => $invalid])->assertSessionHasErrors();
        }
        $category = CategoryRecord::create(['status' => 'active', 'position' => 1]);
        $fields = ['bestsellers_days' => 90, 'bestsellers_min_units' => 3, 'bestsellers_min_orders' => 2, 'bestsellers_excluded_categories' => [(string) $category->id]];
        $this->save($fields);
        $this->get('/admin/settings/general?tab=catalog')->assertInertia(fn ($page) => $page->where('settings.bestsellers_days', 90)->where('settings.bestsellers_excluded_categories', [(string) $category->id]));
        $this->save(['new_products_days' => 30], 2);
        self::assertSame(2, app(GeneralSettings::class)->all()['bestsellers_min_orders']);
        self::assertSame([(string) $category->id], app(GeneralSettings::class)->all()['bestsellers_excluded_categories']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%bestsellers_days%')->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'bestseller_excluded_category')->exists());
        $this->save(['bestsellers_excluded_categories' => []], 3);
        self::assertSame([], app(GeneralSettings::class)->all()['bestsellers_excluded_categories']);
    }

    public function test_back_to_top_is_enabled_by_default_and_can_be_disabled(): void
    {
        self::assertTrue(app(GeneralSettings::class)->all()['show_back_to_top']);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.show_back_to_top', true));
        try {
            $this->save(['show_back_to_top' => 'invalid']);
            self::fail('Invalid boolean accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('show_back_to_top', $e->errors());
        }
        $this->save(['show_back_to_top' => false]);
        $this->assertDatabaseHas('general_settings', ['id' => 1, 'show_back_to_top' => false]);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.show_back_to_top', false));
        $this->get('/admin/settings/general?tab=appearance')->assertInertia(fn ($page) => $page->where('settings.show_back_to_top', false));
        $this->save(['site_max_width' => 1440], 2);
        self::assertFalse(app(GeneralSettings::class)->all()['show_back_to_top']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%show_back_to_top%')->exists());
    }

    public function test_the_comparison_limit_defaults_to_four_validates_and_is_audited(): void
    {
        self::assertSame(4, app(GeneralSettings::class)->all()['comparison_max_products']);
        foreach ([1, 11, 0, 'many', null] as $invalid) {
            try {
                $this->save(['comparison_max_products' => $invalid]);
                self::fail('Invalid comparison limit was accepted');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('comparison_max_products', $e->errors());
            }
        }
        $this->save(['comparison_max_products' => 6]);
        self::assertSame(6, app(GeneralSettings::class)->all()['comparison_max_products']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%comparison_max_products%')->exists());
    }

    public function test_new_products_period_defaults_to_ninety_days_validates_and_is_audited(): void
    {
        self::assertSame(90, app(GeneralSettings::class)->all()['new_products_days']);
        foreach ([0, 3651, '30 days', 45.5, null] as $invalid) {
            try {
                $this->save(['new_products_days' => $invalid]);
                self::fail('Invalid new products period was accepted');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('new_products_days', $e->errors());
            }
        }
        $this->save(['new_products_days' => 30]);
        self::assertSame(30, app(GeneralSettings::class)->all()['new_products_days']);
        $this->get('/admin/settings/general?tab=catalog')->assertInertia(fn ($page) => $page->where('settings.new_products_days', 30));
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%new_products_days%')->exists());
    }

    public function test_site_width_defaults_validates_persists_and_reaches_the_storefront(): void
    {
        self::assertSame(1320, app(GeneralSettings::class)->all()['site_max_width']);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.max_width', 1320));
        foreach ([319, 3841, '1200px', 1320.5, null] as $invalid) {
            try {
                $this->save(['site_max_width' => $invalid]);
                self::fail('Invalid site width was accepted');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('site_max_width', $e->errors());
            }
        }
        $this->save(['site_max_width' => 1600]);
        $this->assertDatabaseHas('general_settings', ['id' => 1, 'site_max_width' => 1600]);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.max_width', 1600));
        $this->get('/admin/settings/general?tab=appearance')->assertInertia(fn ($page) => $page->where('settings.site_max_width', 1600));
        $this->save(['show_menu_descriptions' => false], 2);
        self::assertSame(1600, app(GeneralSettings::class)->all()['site_max_width']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%site_max_width%')->exists());
        try {
            DB::transaction(fn () => DB::table('general_settings')->where('id', 1)->update(['site_max_width' => 0]));
            self::fail('Database accepted an invalid site width');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_product_picture_blending_is_on_by_default_and_reaches_the_storefront(): void
    {
        self::assertTrue(app(GeneralSettings::class)->all()['blend_product_images']);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.blend_images', true));
        $this->save(['blend_product_images' => false]);
        self::assertFalse(DB::table('general_settings')->where('id', 1)->value('blend_product_images'));
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.blend_images', false));
        $this->get('/admin/settings/general?tab=catalog')->assertOk()->assertInertia(fn ($page) => $page->where('settings.blend_product_images', false));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'general_settings']);
    }

    public function test_product_card_style_defaults_to_classic_and_reaches_the_storefront(): void
    {
        self::assertSame('classic', app(GeneralSettings::class)->all()['product_card_style']);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.card_style', 'classic'));
        try {
            $this->save(['product_card_style' => 'fancy']);
            self::fail('An unknown card style was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('product_card_style', $e->errors());
        }
        $this->save(['product_card_style' => 'showcase']);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.card_style', 'showcase'));
        $this->get('/admin/settings/general?tab=catalog')->assertInertia(fn ($page) => $page->where('settings.product_card_style', 'showcase'));
        $this->save(['product_card_style' => 'signature'], 2);
        $this->get('/')->assertInertia(fn ($page) => $page->where('storefront.card_style', 'signature'));
        $this->get('/admin/settings/general?tab=catalog')->assertInertia(fn ($page) => $page->where('settings.product_card_style', 'signature'));
    }

    public function test_closing_shows_escaped_localized_merchant_text_and_keeps_admin_and_health_accessible(): void
    {
        $this->save(['storefront_open' => false, 'closed_messages' => ['en' => 'Closed for development <script>alert(1)</script>', 'el' => 'Κλειστό για ανάπτυξη']]);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Home')->where('seo.indexable', false));
        Auth::logout();
        $this->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('StoreClosed')->where('message', 'Closed for development <script>alert(1)</script>'));
        // The page is the store's own: its name and logo (served even while closed), never the platform's name.
        Storage::fake('local');
        Storage::disk('local')->put('test/logo.png', 'png');
        $logo = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'test/logo.png', 'mime_type' => 'image/png', 'byte_size' => 3, 'width' => 200, 'height' => 60, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_settings')->updateOrInsert(['id' => 1], ['name' => 'Corner Shop', 'logo_media_id' => $logo]);
        $this->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->where('storeName', 'Corner Shop')->where('logo.width', 200)->has('colors.primary')->missing('applicationName'));
        $this->get('/storefront/media/company/1/'.$logo)->assertOk();
        $this->get('/storefront/media/category/1/'.$logo)->assertNotFound();
        $this->withSession(['storefront_locale' => 'el'])->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->where('message', 'Κλειστό για ανάπτυξη'));
        $this->actingAs($this->actor)->get('/admin/settings/general')->assertOk();
        $this->get('/up')->assertOk();
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%storefront_open%')->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings_translation')->exists());
        $this->save(['storefront_open' => true], 2);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Home'));
    }

    public function test_storefront_noindex_is_saved_audited_and_can_be_disabled_before_launch(): void
    {
        self::assertFalse(app(GeneralSettings::class)->all()['storefront_noindex']);
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'storefront_noindex' => 'maybe'])->assertSessionHasErrors('storefront_noindex');
        $this->save(['storefront_noindex' => true]);
        self::assertTrue(app(GeneralSettings::class)->all()['storefront_noindex']);
        Auth::logout();
        $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', KeepOutOfSearchEngines::DIRECTIVES)->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertInertia(fn (Assert $page) => $page->where('seo.indexable', false)->where('seo.graph', []));
        self::assertTrue(DB::table('audit_logs')->where('changes', 'like', '%storefront_noindex%')->exists());
        $this->save(['storefront_noindex' => false], 2);
        $this->get('/')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertInertia(fn (Assert $page) => $page->where('seo.indexable', true));
    }

    public function test_only_verified_staff_with_admin_access_can_preview_a_closed_store(): void
    {
        $this->save(['storefront_open' => false]);
        $staff = $this->staff(['admin.access']);
        $this->actingAs($staff)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Home')->where('seo.indexable', false));
        $staff->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($staff)->get('/')->assertStatus(503);
        $customer = UserRecord::create(['name' => 'Shopper', 'email' => 'preview@example.test', 'password' => 'a long shopper passphrase']);
        $customer->markEmailAsVerified();
        $this->actingAs($customer)->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('StoreClosed'));
    }

    public function test_closed_store_staff_preview_includes_catalog_media_search_and_cart_without_opening_them_to_guests(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('test/preview.png', 'png');
        $image = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'test/preview.png', 'mime_type' => 'image/png', 'byte_size' => 3, 'width' => 100, 'height' => 100, 'created_at' => now(), 'updated_at' => now()]);
        $locale = LocaleRecord::where('code', 'en')->value('id');
        $category = CategoryRecord::create(['status' => 'active', 'position' => 1]);
        $category->forceFill(['image_media_id' => $image])->save();
        $category->translations()->create(['locale_id' => $locale, 'name' => 'Preview', 'slug' => 'preview']);
        $product = ProductRecord::create(['category_id' => $category->id, 'sku' => 'PREVIEW', 'price' => '10', 'quantity' => 10, 'status' => 'active']);
        $product->translations()->create(['locale_id' => $locale, 'name' => 'Preview product', 'slug' => 'preview-product']);
        $this->save(['storefront_open' => false]);
        foreach (['/preview', '/preview/preview-product', '/cart'] as $url) {
            $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', KeepOutOfSearchEngines::DIRECTIVES);
        }
        // Search keeps its own unavailable-engine status, rather than returning the closed-store page.
        $this->get('/search?q=Preview')->assertInertia(fn (Assert $page) => $page->component('Storefront/Category'))->assertHeader('X-Robots-Tag', KeepOutOfSearchEngines::DIRECTIVES);
        $imageUrl = '/storefront/media/category/'.$category->id.'/'.$image;
        $this->get($imageUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Auth::logout();
        foreach (['/preview', '/preview/preview-product', '/search?q=Preview', '/cart'] as $url) {
            $this->get($url)->assertStatus(503);
        }
        $this->get($imageUrl)->assertNotFound();
    }

    public function test_hiding_prices_is_one_installation_wide_switch_with_localized_messages(): void
    {
        $settings = app(GeneralSettings::class);
        self::assertFalse($settings->pricesHidden());
        self::assertSame('', $settings->priceHiddenMessage('en'));

        $this->save(['hide_product_prices' => true, 'price_hidden_messages' => ['en' => ' Please contact the shop <b>for a price</b> ', 'el' => 'Επικοινωνήστε με το κατάστημα']]);
        $settings = app(GeneralSettings::class);
        self::assertTrue($settings->pricesHidden());
        // Text is stored trimmed and escaped on output by the storefront; it is never trusted as HTML here.
        self::assertSame('Please contact the shop <b>for a price</b>', $settings->priceHiddenMessage('en'));
        self::assertSame('Επικοινωνήστε με το κατάστημα', $settings->priceHiddenMessage('el'));
        self::assertSame('', $settings->priceHiddenMessage('fr'));
        // The existing closed-store message is untouched by saving price messages.
        self::assertNotSame('', DB::table('general_settings_translations')->where('locale_id', LocaleRecord::where('code', 'en')->value('id'))->value('storefront_closed_message'));

        // Omitted fields keep their values; an empty message means "show nothing"; hiding can be switched off again.
        $this->save(['allow_product_comparison' => false], 2);
        self::assertTrue(app(GeneralSettings::class)->pricesHidden());
        $this->save(['hide_product_prices' => false, 'price_hidden_messages' => ['en' => '', 'el' => '']], 3);
        self::assertFalse(app(GeneralSettings::class)->pricesHidden());
        self::assertSame('', app(GeneralSettings::class)->priceHiddenMessage('en'));

        // The administration still returns product prices regardless of the switch, and the page receives the setting.
        $this->save(['hide_product_prices' => true], 4);
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('settings.hide_product_prices', true)->has('settings.price_hidden_messages'));
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%hide_product_prices%')->exists());
    }

    public function test_price_messages_reject_inactive_languages_long_text_and_control_characters(): void
    {
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'price_hidden_messages' => ['en' => 'x', 'fr' => 'y']])->assertSessionHasErrors('price_hidden_messages');
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'price_hidden_messages' => ['en' => str_repeat('x', 501)]])->assertSessionHasErrors('price_hidden_messages.en');
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'price_hidden_messages' => ['en' => "bad\x07text"]])->assertSessionHasErrors('price_hidden_messages.en');
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'hide_product_prices' => 'maybe'])->assertSessionHasErrors('hide_product_prices');
        self::assertFalse(app(GeneralSettings::class)->pricesHidden());
    }

    public function test_new_settings_are_versioned_and_omitted_values_are_preserved(): void
    {
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'B', 'timezone' => 'Europe/Athens', 'date_format' => 'Y-m-d', 'time_format' => 'h:i A', 'calendar_week_start' => 0, 'calendar_date_format' => 'm/d/Y', 'notification_duration' => 0, 'warn_unsaved_changes' => false, 'show_menu_descriptions' => false, 'phone_validation' => 'digits', 'allow_product_comparison' => false, 'single_coupon' => false])->assertSessionHasNoErrors();
        $this->save(['low_stock_threshold' => 4], 2);
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('settings.timezone', 'Europe/Athens')->where('settings.single_coupon', false)->where('settings.low_stock_threshold', 4)->where('settings.version', 3)->where('preferences.notification_duration', 0)->where('preferences.calendar_week_start', 0));
        $this->put('/admin/settings/general', ['version' => 2, 'out_of_stock_action' => 'N', 'storefront_open' => false])->assertSessionHasErrors('version');
        self::assertTrue(app(GeneralSettings::class)->all()['storefront_open']);
    }

    public function test_server_rejects_invalid_formats_ranges_and_inactive_message_languages(): void
    {
        foreach (['timezone' => 'Invalid/Zone', 'date_format' => 'anything', 'time_format' => 'HH:mm', 'calendar_date_format' => 'anything', 'calendar_week_start' => 2, 'notification_duration' => -1, 'low_stock_threshold' => -1, 'grams_per_weight_unit' => '0', 'weight_unit' => '', 'phone_validation' => 'anything', 'storefront_open' => 'maybe'] as $field => $value) {
            $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', $field => $value])->assertSessionHasErrors($field);
        }
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'closed_messages' => ['en' => 'Closed']])->assertSessionHasErrors('closed_messages.el');
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'closed_messages' => ['en' => 'Closed', 'el' => 'Κλειστό', 'fr' => 'Fermé']])->assertSessionHasErrors('closed_messages');
        self::assertSame(1, app(GeneralSettings::class)->all()['version']);
    }

    public function test_weight_conversion_preserves_physical_weight_including_trash_and_invalidates_open_editors(): void
    {
        $product = ProductRecord::create(['weight' => '1.5']);
        $trash = ProductRecord::create(['weight' => '2', 'deleted_at' => now()]);
        $this->save(['weight_unit' => 'g', 'grams_per_weight_unit' => '1']);
        self::assertSame('1500.000000', $product->refresh()->weight);
        self::assertSame('2000.000000', $trash->refresh()->weight);
        self::assertSame(2, $product->lock_version);
        $this->save(['weight_unit' => 'kg', 'grams_per_weight_unit' => '1000'], 2);
        self::assertSame('1.500000', $product->refresh()->weight);
        self::assertSame(3, $product->lock_version);
    }

    public function test_unrepresentable_weight_conversion_rolls_back_settings_and_products(): void
    {
        $product = ProductRecord::create(['weight' => '0.000001']);
        try {
            $this->save(['grams_per_weight_unit' => '1000000', 'storefront_open' => false]);
            self::fail('A positive weight cannot round to zero');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('grams_per_weight_unit', $exception->errors());
        }
        self::assertSame('0.000001', $product->refresh()->weight);
        self::assertTrue(app(GeneralSettings::class)->all()['storefront_open']);
        self::assertSame(1, app(GeneralSettings::class)->all()['version']);
    }

    public function test_weight_overflow_is_reported_as_a_field_error_without_changing_products(): void
    {
        $product = ProductRecord::create(['weight' => '999999999']);
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'weight_unit' => 'g', 'grams_per_weight_unit' => '1'])->assertSessionHasErrors('grams_per_weight_unit');
        self::assertSame('999999999.000000', $product->refresh()->weight);
        self::assertSame('kg', app(GeneralSettings::class)->all()['weight_unit']);
    }

    public function test_phone_policy_is_used_by_customer_and_address_validation(): void
    {
        $phone = ['phone' => '+30 210 1234567'];
        self::assertFalse(validator($phone, ['phone' => app(CustomerInput::class)->addressRules()['phone']])->fails());
        $this->save(['phone_validation' => 'digits']);
        self::assertTrue(validator($phone, ['phone' => app(CustomerInput::class)->addressRules()['phone']])->fails());
        self::assertFalse(validator(['phone' => '302101234567'], ['phone' => app(CustomerInput::class)->rules([], false)['phone']])->fails());
    }

    public function test_low_stock_labels_follow_the_threshold_and_inventory_tracking_flag(): void
    {
        ProductRecord::create(['quantity' => 3]);
        $this->save(['low_stock_threshold' => 3]);
        $this->get('/admin/products')->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.data.0.low_stock', true));
        $this->save(['track_inventory' => false], 2);
        $this->get('/admin/products')->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.data.0.quantity', 3)->where('products.data.0.low_stock', false));
    }

    public function test_postgres_rejects_invalid_configuration_even_outside_the_save_operation(): void
    {
        foreach (['bestsellers_days' => -1, 'bestsellers_min_units' => 0, 'bestsellers_min_orders' => 1000001, 'calendar_week_start' => 4, 'notification_duration' => 301, 'grams_per_weight_unit' => '0', 'low_stock_threshold' => -1, 'phone_validation' => 'any'] as $field => $value) {
            DB::beginTransaction();
            try {
                DB::table('general_settings')->where('id', 1)->update([$field => $value]);
                self::fail('Expected a settings CHECK constraint');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_additive_migration_round_trip_preserves_existing_stock_and_tax_settings(): void
    {
        DB::table('general_settings')->where('id', 1)->update(['out_of_stock_action' => 'B', 'tax_calculation_method' => 'subtotal']);
        // The later price-visibility migration depends on these tables, so it unwinds first and is reapplied last.
        $prices = require database_path('migrations/2026_10_21_000100_add_price_visibility_setting.php');
        $migration = require database_path('migrations/2026_10_14_000900_expand_general_settings.php');
        $prices->down();
        $migration->down();
        self::assertFalse(Schema::hasTable('general_settings_translations'));
        $migration->up();
        $prices->up();
        $values = app(GeneralSettings::class)->all();
        self::assertSame('B', $values['out_of_stock_action']);
        self::assertSame('subtotal', $values['tax_calculation_method']);
        self::assertSame('kg', $values['weight_unit']);
        self::assertTrue($values['storefront_open']);
    }
}
