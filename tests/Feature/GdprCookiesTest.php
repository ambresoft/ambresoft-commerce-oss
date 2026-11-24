<?php

namespace Tests\Feature;

use Ambresoft\GdprCookies\Infrastructure\GdprCookies;
use Ambresoft\GoogleAnalytics\Infrastructure\Jobs\SendPurchase;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\AuthorizationServiceProvider;
use App\Http\Middleware\HandleInertiaRequests;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Application\Event\OrderPlaced;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Infrastructure\StorefrontConsent;
use App\Storefront\Infrastructure\StorefrontTagManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class GdprCookiesTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const BASE = '/admin/addons/ambresoft/gdpr-cookies';

    private const SNIPPET = '<script>window.statsLoaded = true;</script>';

    private UserRecord $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->admin = $this->staff(super: true);
        DB::table('general_settings')->update(['storefront_open' => true]);
        app()->forgetInstance(GeneralSettings::class);
        app()->setLocale('en');
        app(AddonManager::class)->installBundled(null, 'ambresoft/gdpr-cookies');
        app(AddonManager::class)->enable(null, 'ambresoft/gdpr-cookies');
        app('router')->getRoutes()->refreshNameLookups();
        // As on the following request, finalize gates after the newly enabled provider contributed its abilities.
        (new AuthorizationServiceProvider(app()))->boot(app(AccessChecker::class));
    }

    /** @return array<string, mixed>|null */
    private function consent(string $url): ?array
    {
        $found = null;
        $this->get($url)->assertInertia(function (Assert $page) use (&$found): void {
            $found = $page->toArray()['props']['storefrontConsent'] ?? null;
        });

        return $found;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function settings(array $changes = []): array
    {
        $s = DB::table('ambresoft_gdpr_settings')->first();

        return ['lock_version' => $s->lock_version, 'consent_months' => 6, 'privacy_url' => '/privacy', 'reopen_button' => true, ...$changes];
    }

    private function version(): int
    {
        return (int) DB::table('ambresoft_gdpr_settings')->value('version');
    }

    public function test_the_storefront_offers_the_banner_with_the_built_in_texts_and_the_cms_cookies_declared(): void
    {
        $banner = $this->consent('/');

        self::assertSame(1, $banner['version']);
        self::assertSame(6, $banner['months']);
        self::assertSame(__('cms-gdpr::gdpr.default_title'), $banner['texts']['title']);
        self::assertSame('Reject all', $banner['texts']['reject_all']);
        self::assertSame(['necessary', 'preferences', 'statistics', 'marketing'], array_column($banner['categories'], 'key'));
        self::assertContains('cart', array_column($banner['categories'][0]['cookies'], 'name'));
        self::assertSame(['_ga', '_ga_*', 'cms_ga'], array_column($banner['categories'][2]['cookies'], 'name'));
        self::assertSame([], $banner['scripts']);
        self::assertNull($this->consent('/admin/login'));
    }

    public function test_merchant_texts_are_per_language_with_fallback_and_inactive_languages_are_refused(): void
    {
        $this->actingAs($this->admin)->put(self::BASE, $this->settings([
            'texts' => ['en' => ['title' => 'Cookies at Acme', 'body' => '', 'about' => '']],
            'categories' => ['statistics' => ['en' => ['name' => 'Analytics', 'description' => '']]],
        ]))->assertSessionHasNoErrors();
        Auth::logout();

        $banner = $this->consent('/');
        self::assertSame('Cookies at Acme', $banner['texts']['title']);
        self::assertSame(__('cms-gdpr::gdpr.default_body'), $banner['texts']['body'], 'empty text keeps the built-in wording');
        self::assertSame('Analytics', $banner['categories'][2]['name']);
        self::assertSame('/privacy', $banner['privacyUrl']);

        $this->actingAs($this->admin)->put(self::BASE, $this->settings(['texts' => ['xx' => ['title' => 'Nope']]]))->assertSessionHasErrors('texts');
        $this->put(self::BASE, $this->settings(['lock_version' => 99]))->assertSessionHasErrors('lock_version');
        $this->put(self::BASE, $this->settings(['privacy_url' => 'javascript:alert(1)']))->assertSessionHasErrors('privacy_url');
    }

    public function test_initial_switches_are_validated_persisted_and_never_grant_consent(): void
    {
        $none = ['preferences' => false, 'statistics' => false, 'marketing' => false];
        self::assertSame($none, $this->consent('/')['initialCategories']);
        $initial = [...$none, 'marketing' => true];
        $this->actingAs($this->admin)->put(self::BASE, $this->settings(['initial_categories' => $initial]))->assertSessionHasNoErrors();
        $this->get(self::BASE)->assertInertia(fn (Assert $page) => $page->where('settings.initial_categories', $initial));
        $this->assertDatabaseHas('ambresoft_gdpr_settings', ['id' => 1, 'initial_marketing' => true, 'initial_statistics' => false]);
        $this->put(self::BASE, $this->settings(['initial_categories' => [...$initial, 'necessary' => false]]))->assertSessionHasErrors('initial_categories');
        $this->put(self::BASE, $this->settings(['initial_categories' => [...$initial, 'marketing' => 'yes']]))->assertSessionHasErrors('initial_categories.marketing');
        // Older clients omitting the new field preserve the saved settings.
        $this->put(self::BASE, $this->settings())->assertSessionHasNoErrors();
        Auth::logout();
        self::assertSame($initial, $this->consent('/')['initialCategories']);
        self::assertFalse(app(StorefrontConsent::class)->granted(request(), 'marketing'));
        self::assertSame(0, DB::table('ambresoft_gdpr_consents')->count());
    }

    public function test_only_the_scripts_capability_sees_or_changes_scripts_and_a_change_asks_everyone_again(): void
    {
        $manager = $this->staff(['admin.access', 'ambresoft.gdpr-cookies.view', 'ambresoft.gdpr-cookies.manage']);
        $this->actingAs($manager)->get(self::BASE)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Addons/GdprCookies')->where('scripts', null)->where('canScripts', false));
        $this->put(self::BASE.'/scripts', ['scripts' => [['category' => 'statistics', 'name' => 'Stats', 'code' => self::SNIPPET, 'enabled' => true]]])->assertForbidden();
        $this->flushSession();

        $this->actingAs($this->admin)->put(self::BASE.'/scripts', ['scripts' => [['category' => 'statistics', 'name' => 'Stats', 'code' => self::SNIPPET, 'enabled' => true]]])->assertSessionHasNoErrors();
        self::assertSame(2, $this->version(), 'new processing needs fresh consent');
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'gdpr_cookie_script', 'actor_id' => $this->admin->id]);
        $this->put(self::BASE.'/scripts', ['scripts' => [['category' => 'necessary', 'name' => 'X', 'code' => 'x', 'enabled' => true]]])->assertSessionHasErrors('scripts.0.category');

        $id = (int) DB::table('ambresoft_gdpr_scripts')->value('id');
        $this->put(self::BASE.'/scripts', ['scripts' => [['id' => $id, 'category' => 'statistics', 'name' => 'Stats', 'code' => self::SNIPPET, 'enabled' => true]]])->assertSessionHasNoErrors();
        self::assertSame(2, $this->version(), 'an unchanged save asks nobody again');
    }

    public function test_consented_scripts_reach_public_pages_only_and_private_pages_load_fresh(): void
    {
        $this->actingAs($this->admin)->put(self::BASE.'/scripts', ['scripts' => [['category' => 'marketing', 'name' => 'Pixel', 'code' => self::SNIPPET, 'enabled' => true]]]);
        Auth::logout();

        self::assertSame([['id' => (int) DB::table('ambresoft_gdpr_scripts')->value('id'), 'category' => 'marketing', 'code' => self::SNIPPET]], $this->consent('/')['scripts']);
        $private = $this->consent('/login');
        self::assertSame([], $private['scripts'], 'the banner shows, its scripts do not');
        self::assertNotEmpty($private['categories']);
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Cms-Tags' => '1'];
        $this->get('/login', $inertia)->assertStatus(409)->assertHeader('X-Inertia-Location', url('/login'));
        $this->get('/login', [...$inertia, 'X-Cms-Tags' => '0'])->assertOk(); // a document without tags keeps in-app visits
    }

    public function test_each_decision_is_recorded_without_personal_data_for_the_current_version_only(): void
    {
        $id = (string) Str::uuid();
        $decision = ['consent_id' => $id, 'version' => 1, 'preferences' => false, 'statistics' => true, 'marketing' => false, 'action' => 'custom'];

        $this->postJson('/storefront/gdpr-cookies/consents', $decision)->assertNoContent();
        $this->postJson('/storefront/gdpr-cookies/consents', [...$decision, 'version' => 7])->assertJsonValidationErrors('version');
        $this->postJson('/storefront/gdpr-cookies/consents', [...$decision, 'action' => 'maybe'])->assertJsonValidationErrors('action');

        $this->assertDatabaseHas('ambresoft_gdpr_consents', ['consent_id' => $id, 'statistics' => true, 'marketing' => false, 'action' => 'custom']);
        self::assertSame(['id', 'consent_id', 'version', 'preferences', 'statistics', 'marketing', 'action', 'created_at'],
            array_keys((array) DB::table('ambresoft_gdpr_consents')->first()), 'no IP address or user agent is stored');
        $this->actingAs($this->admin)->get(self::BASE.'?consent='.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('lookup.records.0.action', 'custom'));

        DB::table('ambresoft_gdpr_consents')->update(['created_at' => now()->subMonths(19)]);
        self::assertSame(1, app(GdprCookies::class)->purge());
    }

    public function test_server_side_purchases_wait_for_statistics_consent(): void
    {
        app(AddonManager::class)->installBundled(null, 'ambresoft/google-analytics');
        app(AddonManager::class)->enable(null, 'ambresoft/google-analytics');
        $settings = '/admin/addons/ambresoft/google-analytics/settings';
        $this->actingAs($this->admin)->get($settings)->assertOk();
        $this->put($settings, ['version' => (int) DB::table('addon_settings')->where('addon', 'ambresoft/google-analytics')->value('lock_version'),
            'settings' => ['measurement_id' => 'G-ABC123XYZ9', 'api_secret' => 'mp-Secret_1234567890']])->assertSessionHasNoErrors();
        Queue::fake();
        $place = function (array $cookies): void {
            $this->app->instance('request', Request::create('/checkout', 'POST', [], $cookies));
            event(new OrderPlaced('41', 'ORD-1', 'EUR', 2, '10', '1.9', '0', null, []));
        };
        $ga = [StorefrontTagManager::ANALYTICS_COOKIE => '123.456'];
        $choice = fn (string $flags, int $version = 1, ?int $time = null): string => Str::uuid().'.'.$version.'.'.$flags.'.'.($time ?? time());

        $place($ga);
        $place([...$ga, StorefrontConsent::COOKIE => $choice('100')]);
        $place([...$ga, StorefrontConsent::COOKIE => $choice('010', 2)]);
        $place([...$ga, StorefrontConsent::COOKIE => $choice('010', 1, time() - 200 * 86400)]);
        Queue::assertNothingPushed();

        $place([...$ga, StorefrontConsent::COOKIE => $choice('010')]);
        Queue::assertPushed(SendPurchase::class, 1);
    }

    public function test_the_bundled_package_uses_the_ambresoft_icon_and_links_from_addons(): void
    {
        self::assertFileEquals(base_path('modules/ambresoft/stripe-checkout/icon.svg'), base_path('modules/ambresoft/gdpr-cookies/icon.svg'));
        $this->actingAs($this->admin)->get('/admin/addons')->assertInertia(fn (Assert $page) => $page->where('addons', fn (iterable $addons): bool => collect($addons)
            ->firstWhere('name', 'ambresoft/gdpr-cookies')['settings'] === [['name' => 'GDPR Cookies', 'href' => self::BASE]]));
        app(AddonManager::class)->disable(null, 'ambresoft/gdpr-cookies');
        Auth::logout();
        self::assertNull($this->consent('/'));
    }
}
