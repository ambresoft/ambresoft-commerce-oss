<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GeneratesAddons;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StorefrontWidgetTest extends TestCase
{
    use GeneratesAddons, RefreshDatabase, StaffIdentities;

    private const ADDON = 'acme/widget';

    private const WIDGET = ['addon' => self::ADDON, 'src' => 'https://widgets.example.test/frame?store=public-id', 'title' => 'Shop assistant'];

    private UserRecord $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->staff = $this->staff(super: true);
        DB::table('general_settings')->update(['storefront_open' => true, 'cart_enabled' => true]);
        app()->forgetInstance(GeneralSettings::class);
        $this->installAddon(self::ADDON, ['src/Provider.php' => $this->provider(self::ADDON, self::WIDGET['src'])]);
    }

    private function provider(string $addon, string $src): string
    {
        return <<<PHP
<?php

namespace {ns};

use App\Storefront\Application\Service\StorefrontRegistry;

final class Provider extends \Illuminate\Support\ServiceProvider
{
    public function boot(StorefrontRegistry \$storefront): void
    {
        \$storefront->widget('$addon', '$src', 'Shop assistant');
    }
}
PHP;
    }

    private function pageProvider(string $src): string
    {
        return <<<PHP
<?php

namespace {ns};

use App\Storefront\Application\Service\StorefrontRegistry;

final class Provider extends \Illuminate\Support\ServiceProvider
{
    public function boot(StorefrontRegistry \$storefront): void
    {
        \$storefront->framePage('acme/page', 'shop-assistant', '$src', 'Shop assistant');
    }
}
PHP;
    }

    protected function tearDown(): void
    {
        $this->removeGeneratedAddons();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $expected */
    private function assertWidgets(string $url, array $expected): void
    {
        // Not assertOk: a closed store and a search without an engine answer 503 with a real page.
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('storefrontWidgets', $expected));
    }

    public function test_approved_storefront_pages_receive_the_widget(): void
    {
        foreach (['/', '/search?q=x', '/new', '/brands', '/sitemap', '/cart', '/comparison'] as $url) {
            $this->assertWidgets($url, [self::WIDGET]);
        }
    }

    public function test_every_other_page_receives_nothing(): void
    {
        foreach (['/login', '/register', '/order-status', '/admin/login'] as $url) {
            $this->assertWidgets($url, []);
        }
        $this->actingAs($this->staff);
        $this->assertWidgets('/admin', []);
        $this->assertWidgets('/admin/addons', []);
    }

    public function test_a_widget_is_never_written_into_the_page_shell(): void
    {
        $this->get('/')->assertOk()->assertDontSee('<script src="https://widgets.example.test', false);
    }

    public function test_a_widget_on_the_stores_own_host_is_refused_because_the_sandbox_would_not_hold(): void
    {
        $this->installAddon('acme/same-host', ['src/Provider.php' => $this->provider('acme/same-host', 'https://localhost/frame')]);

        $this->assertWidgets('/', [self::WIDGET]);
    }

    public function test_placeholders_in_a_widget_address_are_filled(): void
    {
        $this->installAddon('acme/filled', ['src/Provider.php' => $this->provider('acme/filled', 'https://widgets.example.test/frame?locale={locale}&accent={primary}')]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('storefrontWidgets.1.src', 'https://widgets.example.test/frame?locale='.app()->getLocale().'&accent=%2305ab7d'));
    }

    public function test_a_frame_page_keeps_the_store_around_its_frame(): void
    {
        $this->installAddon('acme/page', ['src/Provider.php' => $this->pageProvider('https://widgets.example.test/frame?locale={locale}')]);

        $this->get('/shop-assistant')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('Storefront/Page')
                ->where('storefront.frame.addon', 'acme/page')
                ->where('storefront.frame.src', 'https://widgets.example.test/frame?locale='.app()->getLocale())
                ->where('storefront.frame.title', 'Shop assistant'));
    }

    public function test_the_header_link_follows_the_addon_and_the_store(): void
    {
        $this->installAddon('acme/page', ['src/Provider.php' => $this->pageProvider('https://widgets.example.test/frame')]);
        $link = ['addon' => 'acme/page', 'url' => '/shop-assistant', 'title' => 'Shop assistant'];

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('storefrontPageLinks', [$link]));

        DB::table('general_settings')->update(['storefront_open' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('storefrontPageLinks', []));
    }

    public function test_a_frame_page_follows_the_addon_and_the_store(): void
    {
        $this->installAddon('acme/page', ['src/Provider.php' => $this->pageProvider('https://widgets.example.test/frame')]);
        $this->actingAs($this->staff)->post('/admin/addons/acme/page/disable')->assertRedirect();
        Auth::logout();
        $this->get('/shop-assistant')->assertNotFound();

        $this->actingAs($this->staff)->post('/admin/addons/acme/page/enable')->assertRedirect();
        Auth::logout();
        DB::table('general_settings')->update(['storefront_open' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $this->assertSame(503, $this->get('/shop-assistant')->getStatusCode());
    }

    public function test_a_frame_page_never_replaces_a_real_page_or_reaches_other_paths(): void
    {
        $this->installAddon('acme/page', ['src/Provider.php' => $this->pageProvider('https://widgets.example.test/frame')]);

        $this->get('/shop-assistant/deeper')->assertNotFound();
        $this->get('/shop-assistant-two')->assertNotFound();
    }

    public function test_a_frame_page_on_the_stores_own_host_is_refused(): void
    {
        $this->installAddon('acme/page', ['src/Provider.php' => $this->pageProvider('https://localhost/frame')]);

        $this->get('/shop-assistant')->assertNotFound();
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function invalidFramePages(): array
    {
        return [
            ['Shop', 'https://widgets.example.test/', 'A title'],
            ['shop/assistant', 'https://widgets.example.test/', 'A title'],
            ['shop-', 'https://widgets.example.test/', 'A title'],
            ['shop', 'http://widgets.example.test/', 'A title'],
            ['shop', 'https://user:pass@widgets.example.test/', 'A title'],
            ['shop', 'https://widgets.example.test/', ' '],
        ];
    }

    #[DataProvider('invalidFramePages')]
    public function test_invalid_frame_pages_are_refused(string $slug, string $src, string $title): void
    {
        $this->expectException(LogicException::class);
        (new StorefrontRegistry)->framePage('acme/page', $slug, $src, $title);
    }

    public function test_two_frame_pages_cannot_share_a_slug(): void
    {
        $registry = new StorefrontRegistry;
        $registry->framePage('acme/one', 'shop', 'https://widgets.example.test/', 'One');
        $this->expectException(LogicException::class);
        $registry->framePage('acme/two', 'shop', 'https://widgets.example.test/', 'Two');
    }

    public function test_a_closed_store_shows_no_widget(): void
    {
        DB::table('general_settings')->update(['storefront_open' => false]);
        app()->forgetInstance(GeneralSettings::class);

        $this->assertWidgets('/', []);
    }

    public function test_disabling_the_addon_removes_the_widget_at_once(): void
    {
        $this->assertWidgets('/', [self::WIDGET]);
        app(AddonManager::class)->disable(null, self::ADDON);

        $this->assertWidgets('/', []);

        app(AddonManager::class)->enable(null, self::ADDON);
        $this->assertWidgets('/', [self::WIDGET]);
    }
}
