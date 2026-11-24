<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class GoogleTagManagerTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const SETTINGS = '/admin/addons/ambresoft/google-tag-manager/settings';

    private UserRecord $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->admin = $this->staff(super: true);
        DB::table('general_settings')->update(['storefront_open' => true, 'cart_enabled' => true]);
        app()->forgetInstance(GeneralSettings::class);
        app(AddonManager::class)->installBundled(null, 'ambresoft/google-tag-manager');
        app(AddonManager::class)->enable(null, 'ambresoft/google-tag-manager');
    }

    /**
     * @param  array<string, string>  $settings
     * @return TestResponse<Response>
     */
    private function save(array $settings): TestResponse
    {
        $this->actingAs($this->admin)->get(self::SETTINGS)->assertOk();
        $version = (int) DB::table('addon_settings')->where('addon', 'ambresoft/google-tag-manager')->value('lock_version');

        return $this->put(self::SETTINGS, ['version' => $version, 'settings' => $settings]);
    }

    private function tagManager(string $url): mixed
    {
        $found = null;
        // Not assertOk: a search without an engine answers 503 with a real page.
        $this->get($url)->assertInertia(function (Assert $page) use (&$found): void {
            $found = $page->toArray()['props']['storefrontTagManager'] ?? null;
        });

        return $found;
    }

    public function test_public_pages_receive_the_container_and_enabled_events_with_cs_cart_names(): void
    {
        self::assertNull($this->tagManager('/'), 'nothing until a container id is saved');
        $this->save(['container_id' => 'GTM-ABC1234', 'add_to_wishlist' => '0'])->assertSessionHasNoErrors();
        Auth::logout();

        $expected = ['container' => 'GTM-ABC1234', 'dataLayer' => 'dataLayer', 'events' => ['add_to_cart' => 'gtm-add-to-cart',
            'remove_from_cart' => 'gtm-remove-to-cart', 'add_to_comparison' => 'gtm-comparison-list', 'call_request' => 'gtm-call-request']];
        foreach (['/', '/search?q=x', '/new', '/cart', '/comparison'] as $url) {
            self::assertSame($expected, $this->tagManager($url), $url);
        }
        foreach (['/login', '/register', '/order-status'] as $url) {
            self::assertNull($this->tagManager($url), $url);
        }
        $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('storefrontTagManager', null));
    }

    public function test_an_in_app_visit_to_a_private_page_becomes_a_full_page_load(): void
    {
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Cms-Tags' => '1'];
        $this->get('/login', [...$inertia, 'X-Cms-Tags' => '0'])->assertOk();
        $this->save(['container_id' => 'GTM-ABC1234']);

        $this->get('/login', $inertia)->assertStatus(409)->assertHeader('X-Inertia-Location', url('/login'));
        $this->get('/cart', $inertia)->assertOk();
        Auth::logout();
        $this->get('/login', [...$inertia, 'X-Cms-Tags' => '0'])->assertOk(); // a document without tags keeps in-app visits
        $this->get('/login')->assertOk()->assertDontSee('googletagmanager', false);
    }

    public function test_settings_reject_values_that_cannot_be_a_container_global_or_event_name(): void
    {
        $this->save(['container_id' => 'UA-1234-1', 'data_layer' => 'data layer', 'add_to_cart_name' => '"><script>'])
            ->assertSessionHasErrors(['settings.container_id', 'settings.data_layer', 'settings.add_to_cart_name']);
        self::assertNull($this->tagManager('/'));
    }

    public function test_a_disabled_add_on_stops_the_container(): void
    {
        $this->save(['container_id' => 'GTM-ABC1234']);
        app(AddonManager::class)->disable(null, 'ambresoft/google-tag-manager');

        self::assertNull($this->tagManager('/'));
    }

    public function test_the_core_slot_has_one_owner(): void
    {
        $this->expectException(LogicException::class);
        app(StorefrontRegistry::class)->googleTagManager('acme/other', fn (): ?array => null);
    }

    public function test_bundled_package_uses_the_ambresoft_icon(): void
    {
        self::assertFileEquals(base_path('modules/ambresoft/stripe-checkout/icon.svg'), base_path('modules/ambresoft/google-tag-manager/icon.svg'));
    }
}
