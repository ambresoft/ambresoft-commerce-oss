<?php

namespace Tests\Feature;

use App\Settings\Infrastructure\CheckoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CheckoutSettingsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actingAs($this->staff(super: true));
    }

    private function save(array $checkout, int $version = 1): TestResponse
    {
        return $this->from('/admin/settings/general?tab=checkout')->put('/admin/settings/general', ['version' => $version, 'out_of_stock_action' => 'N', 'checkout_settings' => $checkout]);
    }

    public function test_checkout_credentials_are_encrypted_write_only_preserved_when_blank_and_explicitly_removable(): void
    {
        $this->save(['version' => 1, 'enabled' => true, 'aade_username' => 'test-registry-user', 'aade_password' => 'test-registry-password', 'vatsense_key' => 'test-global-key'])->assertSessionHasNoErrors();
        $cipher = DB::table('checkout_settings')->value('secrets');
        self::assertStringNotContainsString('test-registry', $cipher);
        self::assertStringNotContainsString('test-global-key', $cipher);
        $this->get('/admin/settings/general?tab=checkout')->assertOk()->assertInertia(fn (Assert $page) => $page->where('checkoutSettings', ['enabled' => true, 'version' => 2, 'greek_provider' => 'vies', 'international_provider' => 'vies', 'country_codes' => [], 'aade_configured' => true, 'vatsense_configured' => true]));
        $this->save(['version' => 2, 'enabled' => true, 'aade_username' => '', 'aade_password' => '', 'vatsense_key' => ''], 2)->assertSessionHasNoErrors();
        self::assertSame('test-global-key', app(CheckoutSettings::class)->credentials()['vatsense_key']);
        self::assertStringNotContainsString('test-registry', DB::table('audit_logs')->where('entity_type', 'checkout_settings')->get()->toJson());
        $this->save(['version' => 3, 'enabled' => false, 'clear_aade_username' => true, 'clear_aade_password' => true, 'clear_vatsense_key' => true], 3)->assertSessionHasNoErrors();
        self::assertNull(DB::table('checkout_settings')->value('secrets'));
        self::assertFalse(app(CheckoutSettings::class)->all()['enabled']);
    }

    public function test_invalid_stale_and_partial_configuration_rolls_back_the_entire_save_without_flashing_credentials(): void
    {
        $this->save(['version' => 1, 'enabled' => true, 'greek_provider' => 'aade'])->assertSessionHasErrors('checkout_settings.aade_username');
        $response = $this->save(['version' => 1, 'enabled' => true, 'aade_username' => 'test-secret-user', 'vatsense_key' => 'test-secret-key']);
        $response->assertSessionHasErrors('checkout_settings.aade_password')->assertSessionMissing('_old_input.checkout_settings.aade_username')->assertSessionMissing('_old_input.checkout_settings.vatsense_key');
        self::assertSame(1, (int) DB::table('general_settings')->value('lock_version'));
        self::assertSame(1, app(CheckoutSettings::class)->all()['version']);
        $this->save(['version' => 1, 'enabled' => true, 'vatsense_key' => 'test-secret-key'])->assertSessionHasNoErrors();
        $this->save(['version' => 1, 'enabled' => false], 2)->assertSessionHasErrors('checkout_settings.version');
        self::assertTrue(app(CheckoutSettings::class)->all()['enabled']);
        self::assertSame(2, (int) DB::table('general_settings')->value('lock_version'));
    }

    public function test_checkout_configuration_cannot_be_written_without_settings_manage(): void
    {
        $this->actingAs($this->staff());
        $this->save(['version' => 1, 'enabled' => true, 'vatsense_key' => 'test-secret-key'])->assertForbidden();
        self::assertNull(DB::table('checkout_settings')->value('secrets'));
    }

    public function test_free_methods_need_no_credentials_and_provider_choices_are_validated(): void
    {
        $this->save(['version' => 1, 'enabled' => true, 'greek_provider' => 'vies', 'international_provider' => 'vies'])->assertSessionHasNoErrors();
        self::assertNull(DB::table('checkout_settings')->value('secrets'));
        self::assertTrue(app(CheckoutSettings::class)->all()['enabled']);
        $this->save(['version' => 2, 'enabled' => true, 'international_provider' => 'vatsense'], 2)->assertSessionHasErrors('checkout_settings.vatsense_key');
        $this->save(['version' => 2, 'enabled' => true, 'greek_provider' => 'external-url', 'international_provider' => 'local'], 2)->assertSessionHasErrors(['checkout_settings.greek_provider', 'checkout_settings.international_provider']);
        self::assertSame('vies', app(CheckoutSettings::class)->all()['international_provider']);
    }

    public function test_invoice_countries_start_with_shipping_choices_and_are_saved_independently_with_versioning_and_audit(): void
    {
        DB::table('shipping_countries')->insert([['country_code' => 'GR', 'created_at' => now()], ['country_code' => 'CY', 'created_at' => now()]]);
        $migration = require database_path('migrations/2026_11_12_170048_create_checkout_invoice_countries.php');
        $migration->down();
        $migration->up();
        self::assertSame(['CY', 'GR'], app(CheckoutSettings::class)->all()['country_codes']);
        $this->get('/admin/settings/general?tab=checkout')->assertInertia(fn (Assert $p) => $p->where('checkoutSettings.country_codes', ['CY', 'GR']));
        $this->save(['version' => 1, 'enabled' => true, 'country_codes' => ['GR', 'DE']])->assertSessionHasNoErrors();
        self::assertSame(['DE', 'GR'], app(CheckoutSettings::class)->all()['country_codes']);
        self::assertSame(['CY', 'GR'], DB::table('shipping_countries')->orderBy('country_code')->pluck('country_code')->all());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'checkout_invoice_country')->where('actor_id', auth()->id())->exists());
        DB::table('shipping_countries')->insert(['country_code' => 'FR', 'created_at' => now()]);
        self::assertSame(['DE', 'GR'], app(CheckoutSettings::class)->all()['country_codes']);
        $this->save(['version' => 1, 'enabled' => true, 'country_codes' => []], 2)->assertSessionHasErrors('checkout_settings.version');
        self::assertSame(['DE', 'GR'], app(CheckoutSettings::class)->all()['country_codes']);
        $this->save(['version' => 2, 'enabled' => true], 2)->assertSessionHasNoErrors();
        self::assertSame(['DE', 'GR'], app(CheckoutSettings::class)->all()['country_codes'], 'Omitted countries preserve the selection');
        $this->save(['version' => 3, 'enabled' => true, 'country_codes' => []], 3)->assertSessionHasNoErrors();
        self::assertSame([], app(CheckoutSettings::class)->all()['country_codes'], 'An explicit empty list disables every country');
    }

    public function test_invalid_or_unauthorized_country_lists_do_not_change_settings(): void
    {
        foreach ([['XX'], ['gr'], ['GR', 'GR'], 'GR', ['GR' => 'GR']] as $countries) {
            $this->save(['version' => 1, 'enabled' => true, 'country_codes' => $countries])->assertSessionHasErrors();
            self::assertSame(1, app(CheckoutSettings::class)->all()['version']);
            self::assertSame(1, (int) DB::table('general_settings')->value('lock_version'));
            $this->assertDatabaseCount('checkout_invoice_countries', 0);
        }
        $this->flushSession();
        $this->actingAs($this->staff());
        $this->save(['version' => 1, 'enabled' => true, 'country_codes' => ['GR']])->assertForbidden();
        $this->assertDatabaseCount('checkout_invoice_countries', 0);
    }
}
