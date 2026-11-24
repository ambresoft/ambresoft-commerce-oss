<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BlackFridayThemeTest extends TestCase
{
    use RefreshDatabase;

    private const ADDON = 'ambresoft/black-friday-theme';

    public function test_palette_follows_addon_lifecycle_without_changing_saved_storefront_data(): void
    {
        $this->withoutVite();
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => true]);
        $saved = DB::table('storefront_theme_settings')->get()->toJson();
        $blocks = DB::table('storefront_blocks')->orderBy('id')->get()->toJson();
        $manager = app(AddonManager::class);
        $manager->installBundled(null, self::ADDON);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefrontStylesheets', []));

        $manager->enable(null, self::ADDON);
        $css = app(StorefrontRegistry::class)->stylesheets()[self::ADDON];
        foreach (['/', '/login', '/cart'] as $url) {
            $this->get($url)->assertOk()->assertSee('style="color-scheme: dark; background-color: #12151b"', false)->assertInertia(fn (Assert $page) => $page->where('storefrontStylesheets', [self::ADDON => $css]));
        }
        $this->get('/admin/login')->assertOk()->assertDontSee('style="color-scheme: dark; background-color: #12151b"', false)->assertInertia(fn (Assert $page) => $page->where('storefrontStylesheets', []));

        // Always props also refresh on partial visits; a disabled provider may still exist in this process.
        $manager->disable(null, self::ADDON);
        $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'Auth/Login', 'X-Inertia-Partial-Data' => 'app'])
            ->assertOk()->assertJsonPath('props.storefrontStylesheets', [])->assertJsonPath('props.storefrontDocumentPalette', null);
        $manager->enable(null, self::ADDON);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefrontStylesheets', [self::ADDON => $css]));
        $manager->disable(null, self::ADDON);
        $manager->uninstall(null, self::ADDON);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefrontStylesheets', []));
        self::assertSame($saved, DB::table('storefront_theme_settings')->get()->toJson());
        self::assertSame($blocks, DB::table('storefront_blocks')->orderBy('id')->get()->toJson());
    }
}
