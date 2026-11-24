<?php

namespace Tests\Feature;

use Ambresoft\GoogleAnalytics\Infrastructure\Jobs\SendPurchase;
use App\Http\Middleware\HandleInertiaRequests;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Application\Event\OrderPlaced;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Infrastructure\StorefrontTagManager;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const SETTINGS = '/admin/addons/ambresoft/google-analytics/settings';

    private const SECRET = 'mp-Secret_1234567890';

    private UserRecord $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->admin = $this->staff(super: true);
        DB::table('general_settings')->update(['storefront_open' => true, 'cart_enabled' => true]);
        app()->forgetInstance(GeneralSettings::class);
        app(AddonManager::class)->installBundled(null, 'ambresoft/google-analytics');
        app(AddonManager::class)->enable(null, 'ambresoft/google-analytics');
    }

    /**
     * @param  array<string, string>  $settings
     * @return TestResponse<Response>
     */
    private function save(array $settings): TestResponse
    {
        $this->actingAs($this->admin)->get(self::SETTINGS)->assertOk();
        $version = (int) DB::table('addon_settings')->where('addon', 'ambresoft/google-analytics')->value('lock_version');

        return $this->put(self::SETTINGS, ['version' => $version, 'settings' => $settings]);
    }

    private function analytics(string $url): mixed
    {
        $found = null;
        $this->get($url)->assertInertia(function (Assert $page) use (&$found): void {
            $found = $page->toArray()['props']['storefrontAnalytics'] ?? null;
        });

        return $found;
    }

    private function order(): OrderPlaced
    {
        return new OrderPlaced('41', 'ORD-1041', 'EUR', 2, '45.4', '8.79', '3.5', 'SPRING', [
            ['product_id' => '7', 'sku' => 'SKU-7', 'name' => 'Linen shirt', 'variation' => 'Blue / M', 'quantity' => 2, 'total' => '39.8'],
            ['product_id' => '9', 'sku' => null, 'name' => 'Socks', 'variation' => null, 'quantity' => 1, 'total' => '2.1'],
        ]);
    }

    /** As during the checkout request: the listener reads gtag's IDs from the visitor's cookie. */
    private function placeWithCookie(?string $cookie): void
    {
        $request = Request::create('/checkout', 'POST', [], $cookie === null ? [] : [StorefrontTagManager::ANALYTICS_COOKIE => $cookie]);
        $this->app->instance('request', $request);
        event($this->order());
    }

    public function test_public_pages_receive_the_property_and_private_pages_never_do(): void
    {
        self::assertNull($this->analytics('/'));
        $this->save(['measurement_id' => 'G-ABC123XYZ9'])->assertSessionHasNoErrors();
        Auth::logout();

        foreach (['/', '/cart', '/comparison'] as $url) {
            self::assertSame(['measurementId' => 'G-ABC123XYZ9', 'ecommerce' => true], $this->analytics($url), $url);
        }
        self::assertNull($this->analytics('/login'));
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Cms-Tags' => '1'];
        $this->get('/login', $inertia)->assertStatus(409)->assertHeader('X-Inertia-Location', url('/login'));
        $this->get('/login', [...$inertia, 'X-Cms-Tags' => '0'])->assertOk(); // a document without tags keeps in-app visits
    }

    public function test_settings_explain_retired_universal_analytics_ids_and_reject_malformed_values(): void
    {
        $this->save(['measurement_id' => 'UA-223116367-1'])->assertSessionHasErrors(['settings.measurement_id' => __('cms-ga::ga.universal_retired')]);
        $this->save(['measurement_id' => 'G-<script>'])->assertSessionHasErrors('settings.measurement_id');
        $this->save(['measurement_id' => 'G-ABC123XYZ9', 'api_secret' => 'has spaces'])->assertSessionHasErrors('settings.api_secret');
    }

    public function test_a_purchase_is_queued_only_for_a_visitor_gtag_identified_with_a_secret_configured(): void
    {
        Queue::fake();
        $this->save(['measurement_id' => 'G-ABC123XYZ9']);
        $this->placeWithCookie('123456789.1700000000:1700000123');
        Queue::assertNothingPushed();

        $this->save(['api_secret' => self::SECRET])->assertSessionHasNoErrors();
        foreach ([null, 'GA1.1.123.456', '1.2:three'] as $cookie) {
            $this->placeWithCookie($cookie);
        }
        Queue::assertNothingPushed();

        $this->placeWithCookie('123456789.1700000000:1700000123');
        Queue::assertPushed(SendPurchase::class, fn (SendPurchase $job): bool => $job->clientId === '123456789.1700000000' && $job->sessionId === '1700000123');

        $this->save(['track_ecommerce' => '0']);
        app(AddonManager::class)->disable(null, 'ambresoft/google-analytics');
        $this->placeWithCookie('123456789.1700000000');
        Queue::assertPushed(SendPurchase::class, 1);
    }

    public function test_the_purchase_reaches_the_measurement_protocol_as_ga4_expects(): void
    {
        $this->save(['measurement_id' => 'G-ABC123XYZ9', 'api_secret' => self::SECRET]);
        Http::fake(['www.google-analytics.com/*' => Http::response('', 204)]);

        (new SendPurchase($this->order(), '123456789.1700000000', '1700000123'))->handle(app(AddonSettingValues::class));

        Http::assertSent(function (HttpRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), SendPurchase::ENDPOINT.'?') && $query === ['measurement_id' => 'G-ABC123XYZ9', 'api_secret' => self::SECRET]
                && $request->data() === ['client_id' => '123456789.1700000000', 'events' => [['name' => 'purchase', 'params' => [
                    'transaction_id' => 'ORD-1041', 'currency' => 'EUR', 'value' => 41.9, 'tax' => 8.79, 'shipping' => 3.5, 'coupon' => 'SPRING',
                    'session_id' => '1700000123', 'engagement_time_msec' => 1, 'items' => [
                        ['item_id' => 'SKU-7', 'item_name' => 'Linen shirt', 'item_variant' => 'Blue / M', 'price' => 19.9, 'quantity' => 2],
                        ['item_id' => '9', 'item_name' => 'Socks', 'price' => 2.1, 'quantity' => 1],
                    ]]]]];
        });
    }

    public function test_a_refused_purchase_is_logged_not_retried(): void
    {
        $this->save(['measurement_id' => 'G-ABC123XYZ9', 'api_secret' => self::SECRET]);
        Http::fake(['www.google-analytics.com/*' => Http::response('', 400)]);
        $job = new SendPurchase($this->order(), '1.2', null);
        $job->withFakeQueueInteractions();

        $job->handle(app(AddonSettingValues::class));

        $job->assertNotReleased();
        Http::assertSentCount(1);
    }

    public function test_the_visitor_cookie_is_read_as_written_by_the_storefront(): void
    {
        self::assertTrue(app(EncryptCookies::class)->isDisabled(StorefrontTagManager::ANALYTICS_COOKIE));
        self::assertFileEquals(base_path('modules/ambresoft/stripe-checkout/icon.svg'), base_path('modules/ambresoft/google-analytics/icon.svg'));
    }
}
