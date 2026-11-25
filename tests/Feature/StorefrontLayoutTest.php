<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\CatalogDemoSeeder;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\BannerRules;
use App\Marketing\Infrastructure\Stickers;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Infrastructure\PaymentSettings;
use App\Pricing\Infrastructure\ProductSales;
use App\Reporting\Infrastructure\ReportOrders;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\BannerPreload;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutStore;
use App\Storefront\Infrastructure\RecentlyViewedProducts;
use App\Storefront\Infrastructure\StoreBrand;
use App\Storefront\Infrastructure\ThemeColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StorefrontLayoutTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function layout(string $page = 'homepage'): array
    {
        return app(LayoutStore::class)->read($page);
    }

    private function save(array $layout): void
    {
        $this->put('/admin/layouts/'.$layout['page_type'], $layout)->assertSessionHasNoErrors()->assertRedirect();
    }

    private function grid(int $width = 16, ?string $parent = null, int $position = 1, int $row = 1, string $region = 'content'): array
    {
        return ['id' => (string) Str::uuid(), 'parent_id' => $parent, 'region' => $region, 'width' => $width, 'row' => $row, 'position' => $position];
    }

    private function placement(string $grid, string $type = 'html', int $position = 1, array $settings = []): array
    {
        $defaults = app(StorefrontRegistry::class)->type($type)->definition();

        return ['id' => (string) Str::uuid(), 'grid_id' => $grid, 'position' => $position, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true,
            'block' => ['id' => (string) Str::uuid(), 'type' => $type, 'name' => 'Private editor name', 'template' => $defaults['templates'][0], 'css_class' => '', 'settings' => $settings ?: $defaults['defaults'], 'selections' => [], 'translations' => ['en' => ['title' => 'Welcome', 'html' => '<p>Hello <strong>store</strong></p>'], 'el' => ['title' => 'Καλώς ήρθατε', 'html' => '<p>Γεια σας</p>']]]];
    }

    private function rendered(string $locale = 'en'): array
    {
        return json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', $locale, null)), true);
    }

    private function product(?CategoryRecord $category = null, string $price = '100'): ProductRecord
    {
        $category ??= CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $p = ProductRecord::create(['sku' => 'LAY-'.Str::uuid(), 'price' => $price, 'quantity' => 10, 'category_id' => $category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Product '.$p->id, 'slug' => 'product-'.$p->id]);

        return $p;
    }

    public function test_starter_layouts_editor_public_contract_and_empty_store(): void
    {
        $this->assertDatabaseCount('storefront_layouts', count(app(StorefrontRegistry::class)->pages()));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('storefront_layouts', count(app(StorefrontRegistry::class)->pages()));
        $this->get('/admin/layouts')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Layouts/Edit')->where('layout.page_type', 'homepage')->has('definitions.products')->has('sharedLayout.placements', 4));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Storefront/Home')->where('storefront.theme', 'default')->has('storefront.regions.header')->missing('storefront.regions.content')->missing('auth')->missing('navigation'));
    }

    public function test_nested_sixteen_column_rows_order_movement_and_deletion_persist(): void
    {
        $layout = $this->layout();
        $otherBlocks = DB::table('storefront_blocks')->where('layout_id', '<>', $layout['id'])->count();
        $left = $this->grid(8);
        $right = $this->grid(8, position: 2);
        $child = $this->grid(4, $left['id']);
        $child2 = $this->grid(4, $left['id'], 2);
        $layout['grids'] = [$left, $right, $child, $child2];
        $a = $this->placement($child['id']);
        $b = $this->placement($right['id']);
        $layout['placements'] = [$b, $a];
        $this->save($layout);
        $tree = $this->rendered()['regions']['content'];
        self::assertSame([$left['id'], $right['id']], array_column($tree, 'id'));
        self::assertSame($a['id'], $tree[0]['children'][0]['blocks'][0]['id']);
        $layout = $this->layout();
        foreach ($layout['placements'] as &$p) {
            $p['grid_id'] = $right['id'];
            $p['position'] = $p['id'] === $a['id'] ? 1 : 2;
        }
        unset($p);
        $this->save($layout);
        self::assertSame([$a['id'], $b['id']], array_column($this->rendered()['regions']['content'][0]['blocks'], 'id'));
        $layout = $this->layout();
        $layout['placements'] = [];
        $layout['grids'] = [];
        $this->save($layout);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        // Removing homepage blocks preserves every other layout's blocks.
        $this->assertDatabaseCount('storefront_blocks', $otherBlocks);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'storefront_layout')->exists());
    }

    public function test_invalid_grids_cycles_ownership_and_stale_versions_are_rejected_atomically(): void
    {
        $initial = $this->layout();
        $cases = [];
        $a = $this->grid(8);
        $b = $this->grid(9, position: 2);
        $cases[] = [$a, $b];
        $cases[] = [$a, $this->grid(9, $a['id'])];
        $b['width'] = 8;
        $a['parent_id'] = $b['id'];
        $b['parent_id'] = $a['id'];
        $cases[] = [$a, $b];
        $cases[] = [$this->grid(17)];
        foreach ($cases as $grids) {
            $this->put('/admin/layouts/homepage', [...$initial, 'grids' => $grids, 'placements' => []])->assertSessionHasErrors();
            self::assertSame($initial['version'], $this->layout()['version']);
        }
        $foreign = $this->layout('default')['grids'][0];
        $foreign['region'] = 'content';
        $this->put('/admin/layouts/homepage', [...$initial, 'grids' => [$foreign], 'placements' => []])->assertSessionHasErrors();
        $this->save($initial);
        $this->put('/admin/layouts/homepage', $initial)->assertSessionHasErrors('version');
    }

    public function test_shared_content_override_disable_reset_and_protected_identity(): void
    {
        $shared = $this->layout('default');
        $grid = $this->grid(region: 'footer', row: 9);
        $block = $this->placement($grid['id']);
        $shared['grids'][] = $grid;
        $shared['placements'][] = $block;
        $this->save($shared);
        self::assertSame('Welcome', $this->rendered()['regions']['footer'][0]['blocks'][0]['title']);
        $home = $this->layout();
        $copy = $block['block'];
        $copy['id'] = (string) Str::uuid();
        $copy['translations']['en']['title'] = 'Homepage only';
        $home['overrides'] = [['placement_id' => $block['id'], 'enabled' => true, 'block' => $copy]];
        $this->save($home);
        self::assertSame('Homepage only', $this->rendered()['regions']['footer'][0]['blocks'][0]['title']);
        self::assertSame('Welcome', collect($this->layout('default')['placements'])->firstWhere('id', $block['id'])['block']['translations']['en']['title']);
        $home = $this->layout();
        $home['overrides'][0]['enabled'] = false;
        $this->save($home);
        self::assertArrayNotHasKey('footer', $this->rendered()['regions']);
        $home = $this->layout();
        $home['overrides'] = [];
        $this->save($home);
        self::assertArrayHasKey('footer', $this->rendered()['regions']);
        $shared = $this->layout('default');
        $shared['placements'] = [$block];
        $this->put('/admin/layouts/default', $shared)->assertSessionHasErrors('placements');
        $home = $this->layout();
        $home['overrides'] = [['placement_id' => collect($this->layout('default')['placements'])->first(fn ($p) => $p['block']['type'] === 'identity')['id'], 'enabled' => false, 'block' => null]];
        $this->put('/admin/layouts/homepage', $home)->assertSessionHasErrors('placements');
    }

    public function test_removing_a_shared_source_cleans_its_override_configuration_and_versions(): void
    {
        $shared = $this->layout('default');
        $grid = $this->grid(region: 'footer', row: 9);
        $p = $this->placement($grid['id']);
        $shared['grids'][] = $grid;
        $shared['placements'][] = $p;
        $this->save($shared);
        $home = $this->layout();
        $copy = $p['block'];
        $copy['id'] = (string) Str::uuid();
        $home['overrides'] = [['placement_id' => $p['id'], 'enabled' => true, 'block' => $copy]];
        $this->save($home);
        $this->rendered();
        $shared = $this->layout('default');
        $shared['placements'] = array_values(array_filter($shared['placements'], fn ($item) => $item['id'] !== $p['id']));
        $shared['grids'] = array_values(array_filter($shared['grids'], fn ($item) => $item['id'] !== $grid['id']));
        $this->save($shared);
        self::assertSame($home['version'] + 2, $this->layout()['version']);
        $this->assertDatabaseMissing('storefront_blocks', ['id' => $copy['id']]);
        self::assertSame([], $this->layout()['overrides']);
        self::assertArrayNotHasKey('footer', $this->rendered()['regions']);
    }

    public function test_block_validation_sanitization_locale_fallback_and_visibility(): void
    {
        $layout = $this->layout();
        $grid = $layout['grids'][0];
        $p = $this->placement($grid['id']);
        $p['mobile'] = false;
        $p['block']['translations']['en']['html'] = '<h2>Safe</h2><p onclick="bad()">Hello <strong>bold</strong><script>evil()</script><a href="javascript:evil()">bad link</a></p>';
        $layout['placements'] = [$p];
        $this->save($layout);
        $out = $this->rendered('fr')['regions']['content'][0]['blocks'][0];
        self::assertFalse($out['mobile']);
        self::assertSame('Welcome', $out['title']);
        self::assertStringContainsString('<strong>bold</strong>', $out['content']['html']);
        foreach (['script', 'onclick', 'javascript:', 'Private editor name', 'settings', 'translations'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($out));
        }
        foreach ([['type', 'unknown'], ['template', '../secret.php'], ['css_class', 'x" onmouseover="bad()']] as [$field, $value]) {
            $bad = $this->layout();
            $bad['placements'][0]['block'][$field] = $value;
            $this->put('/admin/layouts/homepage', $bad)->assertSessionHasErrors();
        }
        $malformed = $this->layout();
        $malformed['placements'][0]['block']['translations']['en'] = 'invalid';
        $this->put('/admin/layouts/homepage', $malformed)->assertSessionHasErrors();
        // A setting the type does not declare is never stored (dropped, rather than refusing the whole layout).
        $bad = $this->layout();
        $bad['placements'][0]['block']['settings'] = ['code' => 'bad'];
        $this->save($bad);
        self::assertSame([], json_decode(DB::table('storefront_blocks')->where('id', $bad['placements'][0]['block']['id'])->value('settings'), true));
        $layout = $this->layout();
        $layout['placements'][0]['enabled'] = false;
        $this->save($layout);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
    }

    public function test_manual_newest_sale_products_use_live_eligibility_prices_and_batched_queries(): void
    {
        $a = $this->product();
        $b = $this->product();
        $hidden = $this->product();
        $hidden->update(['status' => 'hidden']);
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'manual', 'limit' => 48]);
        $p['block']['selections'] = ['items' => [(string) $b->id, (string) $hidden->id, (string) $a->id]];
        $layout['placements'] = [$p];
        $this->save($layout);
        $items = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'];
        self::assertSame([(string) $b->id, (string) $a->id], array_column($items, 'id'));
        self::assertSame('100.00', $items[0]['price']);
        app(ProductSales::class)->sync((string) $b->id, [['minimum_quantity' => 1, 'priority' => 1, 'kind' => 'percentage', 'amount' => '25', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19']]);
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['source'] = 'sale';
        $this->save($layout);
        $items = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'];
        self::assertSame([(string) $b->id], array_column($items, 'id'));
        self::assertSame('75.00', $items[0]['price']);
        $this->travel(1)->days();
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        $this->travelBack();
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['source'] = 'newest';
        $this->save($layout);
        $this->rendered();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->rendered();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 12; $i++) {
            $this->product();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->rendered();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual($before + 2, $after);
        $a->update(['status' => 'disabled']);
        $b->update(['quantity' => 0]);
        $items = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'];
        self::assertNotContains((string) $a->id, array_column($items, 'id'));
        self::assertFalse(collect($items)->firstWhere('id', (string) $b->id)['available']);
    }

    public function test_single_item_templates_fill_one_grid_without_implicit_parallel_content(): void
    {
        $a = $this->product();
        $b = $this->product();
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'manual', 'limit' => 8]);
        $p['block']['template'] = 'product_single';
        $p['block']['selections'] = ['items' => [(string) $a->id, (string) $b->id]];
        $layout['placements'] = [$p];
        $this->save($layout);
        self::assertCount(1, $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items']);
        $layout = $this->layout();
        $layout['placements'][0]['block']['template'] = 'product_carousel';
        $this->save($layout);
        self::assertCount(2, $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items']);
    }

    public function test_a_banner_carousel_says_how_many_banners_show_at_once(): void
    {
        $layout = $this->layout();
        $this->banner('First', '2026-10-01');
        $this->banner('Second', '2026-10-02');
        $p = $this->placement($layout['grids'][0]['id'], 'banners');
        $p['block']['template'] = 'banner_carousel';
        // One at a time unless the merchant says otherwise; blocks saved before the setting get the same.
        self::assertSame([1, 1], [$p['block']['settings']['visible_desktop'], $p['block']['settings']['visible_tablet']]);
        self::assertSame([100, 100], [$p['block']['settings']['height_desktop'], $p['block']['settings']['height_tablet']]);
        $p['block']['settings'] = [...$p['block']['settings'], 'visible_desktop' => 3, 'visible_tablet' => 2, 'height_desktop' => 160, 'height_tablet' => 125];
        $layout['placements'] = [$p];
        $this->save($layout);
        $display = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['display'] ?? [];
        self::assertSame([3, 2], [$display['visible_desktop'] ?? null, $display['visible_tablet'] ?? null]);
        self::assertSame([160, 125], [$display['height_desktop'] ?? null, $display['height_tablet'] ?? null]);
        foreach (['height_desktop' => 99, 'height_tablet' => 251] as $key => $bad) {
            $layout['placements'][0]['block']['settings'][$key] = $bad;
            $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
            $layout['placements'][0]['block']['settings'][$key] = 100;
        }
        foreach ([0, 5] as $bad) {
            $layout['placements'][0]['block']['settings']['visible_desktop'] = $bad;
            $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        }
    }

    public function test_a_product_carousel_shows_an_uploaded_picture_beside_it_that_is_never_a_link(): void
    {
        $upload = fn () => (string) $this->post('/admin/layouts-media', ['image' => UploadedFile::fake()->createWithContent('side.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='))], ['Accept' => 'application/json'])->assertOk()->json('id');
        $image = $upload();
        $this->get('/admin/layouts-media/'.$image)->assertOk();
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products', settings: [...app(StorefrontRegistry::class)->type('products')->definition()['defaults'], 'source' => 'manual', 'image_side' => 'right']);
        $p['block']['template'] = 'product_carousel';
        $p['block']['selections'] = ['items' => [(string) $this->product()->id]];
        $p['block']['image_media_id'] = $image;
        $layout['placements'] = [$p];
        $this->save($layout);

        // Without a phone picture, phones get the desktop one; the fit follows the setting (contain by default).
        $content = $this->rendered()['regions']['content'][0]['blocks'][0]['content'];
        self::assertSame(['url' => '/storefront/media/block/'.$p['block']['id'].'/'.$image, 'width' => 1, 'height' => 1, 'mobile' => null, 'side' => 'right', 'fit' => 'contain', 'fit_mobile' => 'contain', 'link' => null], $content['side_image']);
        $this->get($content['side_image']['url'])->assertOk();
        $mobile = $upload();
        $layout = $this->layout();
        $layout['placements'][0]['block']['image_mobile_media_id'] = $mobile;
        $layout['placements'][0]['block']['settings']['image_fit'] = 'cover';
        $layout['placements'][0]['block']['settings']['image_fit_mobile'] = 'fill';
        $layout['placements'][0]['block']['settings']['image_link'] = '/offers';
        $this->save($layout);
        $side = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['side_image'];
        self::assertSame(['/storefront/media/block/'.$p['block']['id'].'/'.$mobile, 'cover', 'fill'], [$side['mobile']['url'], $side['fit'], $side['fit_mobile']]);
        self::assertSame('/offers', $side['link']);
        $this->get($side['mobile']['url'])->assertOk();
        $layout['placements'][0]['block']['settings']['image_fit'] = 'stretch';
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        // Only the block that shows it serves it.
        $this->get('/storefront/media/block/'.Str::uuid().'/'.$image)->assertNotFound();

        // A picture this editor did not upload (and no block shows) is refused, as is a picture on a block type without one.
        $other = $upload();
        $this->flushSession();
        $this->actingAs($this->actor);
        $layout = $this->layout();
        $layout['placements'][0]['block']['image_media_id'] = $other;
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        $this->get('/admin/layouts-media/'.$other)->assertNotFound();
        $layout = $this->layout();
        $html = $this->placement($layout['grids'][0]['id']);
        $html['block']['image_media_id'] = $image;
        $layout['placements'][] = $html;
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();

        // Removing it leaves the carousel alone.
        $layout = $this->layout();
        $layout['placements'][0]['block']['image_media_id'] = null;
        $this->save($layout);
        self::assertArrayNotHasKey('side_image', $this->rendered()['regions']['content'][0]['blocks'][0]['content']);
    }

    public function test_category_ancestry_deleted_references_and_missing_images(): void
    {
        $parent = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $child = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $parent->id]);
        $child->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Category', 'slug' => 'category']);
        $p = $this->placement($this->layout()['grids'][0]['id'], 'categories');
        $p['block']['selections'] = ['items' => [(string) $child->id]];
        $layout = $this->layout();
        $layout['placements'] = [$p];
        $this->save($layout);
        self::assertNull($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'][0]['image']);
        $parent->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Tech', 'slug' => 'technologia']);
        $child->translations()->update(['slug' => 'smartphones']);
        self::assertSame('/technologia/smartphones', $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'][0]['url']);
        $parent->update(['status' => 'disabled']);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        $parent->update(['status' => 'active']);
        DB::table('categories')->where('id', $child->id)->delete();
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        $this->assertDatabaseCount('storefront_block_selections', 0);
    }

    public function test_display_settings_defaults_root_categories_and_validation(): void
    {
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $root = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $root->translations()->create(['locale_id' => $en, 'name' => 'Root', 'slug' => 'root']);
        $child = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $root->id]);
        $child->translations()->create(['locale_id' => $en, 'name' => 'Child', 'slug' => 'child']);
        $p = $this->placement($this->layout()['grids'][0]['id'], 'categories');
        $p['block']['settings'] = ['source' => 'root', 'limit' => 8, 'per_row' => 4, 'rows' => '2', 'autoscroll' => true, 'navigation' => 'inside', 'image_width' => 220, 'image_height' => 180];
        $layout = $this->layout();
        $layout['placements'] = [$p];
        $this->save($layout);
        $content = $this->rendered()['regions']['content'][0]['blocks'][0]['content'];
        self::assertSame(['Root'], array_column($content['items'], 'name'));
        self::assertSame(['per_row' => 4, 'rows' => '2', 'card_shadow' => false, 'autoscroll' => true, 'drag_scroll' => false, 'infinite_scroll' => false, 'navigation' => 'inside', 'hide_arrows_mobile' => false, 'image_width' => 220, 'image_height' => 180, 'image_radius' => 16, 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => ''], $content['display']);

        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['card_shadow'] = true;
        $this->save($layout);
        self::assertTrue($this->rendered()['regions']['content'][0]['blocks'][0]['content']['display']['card_shadow']);
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['card_shadow'] = false;
        $this->save($layout);
        self::assertFalse($this->rendered()['regions']['content'][0]['blocks'][0]['content']['display']['card_shadow']);

        // Blocks saved before these settings existed still render with the shipped defaults.
        $legacy = app(StorefrontRegistry::class)->type('products')->validate(['source' => 'newest', 'limit' => 8]);
        self::assertSame([5, true, true, false, 'inside'], [$legacy['per_row'], $legacy['show_price'], $legacy['show_add_to_cart'], $legacy['autoscroll'], $legacy['navigation']]);
        $banners = app(StorefrontRegistry::class)->type('banners')->validate(['source' => 'latest', 'limit' => 3, 'autoplay_seconds' => 0, 'navigation_style' => 'dots']);
        self::assertSame([false, false], [$banners['full_width'], $banners['remove_border_radius']]);
        self::assertTrue(app(StorefrontRegistry::class)->type('banners')->validate(['source' => 'latest', 'limit' => 3, 'full_width' => true])['full_width']);
        self::assertSame([0, 'dots'], [$banners['autoplay_seconds'], $banners['navigation_style']]);

        $withBackground = app(StorefrontRegistry::class)->type('products')->validate(['source' => 'newest', 'limit' => 8, 'background_color' => '#F0F0F0']);
        self::assertSame('#f0f0f0', $withBackground['background_color']);
        self::assertSame('', $legacy['background_color']);
        foreach (['red', '#fff', 'url(x)'] as $invalid) {
            try {
                app(StorefrontRegistry::class)->type('products')->validate(['source' => 'newest', 'limit' => 8, 'background_color' => $invalid]);
                self::fail($invalid);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $titled = app(StorefrontRegistry::class)->type('categories')->validate(['source' => 'manual', 'limit' => 8, 'title_size_mobile' => 18, 'title_color' => '#AA0000']);
        self::assertSame([24, 22, 18, '#aa0000'], [$titled['title_size_desktop'], $titled['title_size_tablet'], $titled['title_size_mobile'], $titled['title_color']]);
        foreach ([['title_size_desktop' => 5], ['title_size_tablet' => 200], ['title_color' => 'blue']] as $invalid) {
            try {
                app(StorefrontRegistry::class)->type('products')->validate(['source' => 'newest', 'limit' => 8, ...$invalid]);
                self::fail(json_encode($invalid));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $bad = $this->layout();
        $p['block']['settings'] = ['source' => 'root', 'limit' => 8, 'per_row' => 20];
        $bad['placements'] = [$p];
        $this->put('/admin/layouts/homepage', $bad)->assertSessionHasErrors();
    }

    public function test_theme_colors_are_validated_audited_and_part_of_the_public_page(): void
    {
        self::assertSame(ThemeColors::DEFAULTS, $this->rendered()['colors']);
        $this->get('/admin/settings/general')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefrontColors.version', 1)->where('storefrontColors.colors.primary', '#05ab7d'));
        $colors = ['primary' => '#D3242F', 'primary_hover' => '#B01F28', 'on_primary' => '#ffffff', 'text' => '#111111', 'background' => '#fafafa', 'sale' => '#cc0000'];
        $this->put('/admin/settings/storefront-colors', ['version' => 1, ...$colors])->assertSessionHasNoErrors();
        self::assertSame('#d3242f', $this->rendered()['colors']['primary']);
        foreach (['red', '#12345', 'url(x)', '#12345g', '#123456;}body{'] as $bad) {
            $this->put('/admin/settings/storefront-colors', ['version' => 2, ...$colors, 'primary' => $bad])->assertSessionHasErrors('primary');
        }
        $this->put('/admin/settings/storefront-colors', ['version' => 1, ...$colors])->assertSessionHasErrors('version');
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'storefront_theme')->exists());
    }

    public function test_the_customer_sign_in_header_follows_the_primary_color_until_it_gets_its_own(): void
    {
        $header = fn () => app(StoreBrand::class)->all()['auth_header'];
        // Default: the store's primary color, with white text on it.
        self::assertSame(['background' => '#05ab7d', 'text' => '#ffffff'], $header());
        $colors = ['primary' => '#d3242f', 'primary_hover' => '#b01f28', 'on_primary' => '#ffffff', 'text' => '#111111', 'background' => '#fafafa', 'sale' => '#cc0000'];
        $this->put('/admin/settings/storefront-colors', ['version' => 1, ...$colors])->assertSessionHasNoErrors();
        self::assertSame('#d3242f', $header()['background']);
        // The sign-in page for visitors carries it.
        auth()->logout();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('storeBrand.auth_header.background', '#d3242f')->where('storeBrand.auth_header.text', '#ffffff'));
        $this->actingAs($this->actor);
        // Its own color (light ones get dark text); invalid values are refused; empty goes back to the store color.
        $this->put('/admin/settings/storefront-colors', ['version' => 2, ...$colors, 'auth_header' => '#FFE066'])->assertSessionHasNoErrors();
        self::assertSame(['background' => '#ffe066', 'text' => '#111111'], $header());
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('storefrontColors.auth_header', '#ffe066'));
        $this->put('/admin/settings/storefront-colors', ['version' => 3, ...$colors, 'auth_header' => 'red'])->assertSessionHasErrors('auth_header');
        $this->put('/admin/settings/storefront-colors', ['version' => 3, ...$colors, 'auth_header' => ''])->assertSessionHasNoErrors();
        self::assertSame('#d3242f', $header()['background']);
    }

    public function test_product_cards_carry_their_stickers_and_expose_public_sticker_images(): void
    {
        // Two products share a category, so the de-duplicated category list has gaps in its keys.
        $shared = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $a = $this->product($shared);
        $b = $this->product($shared);
        $c = $this->product();
        app(Stickers::class)->save((string) $this->actor->id, null, [
            'name' => 'Internal', 'type' => 'manual', 'style' => 'text', 'status' => 'active', 'starts_at' => null, 'ends_at' => null, 'priority' => 100, 'match' => 'all', 'placement' => 'image_top', 'show_card' => true, 'show_detail' => true, 'show_mobile' => true, 'show_tablet' => true, 'show_desktop' => true,
            'background' => '#111827', 'color' => '#ffffff', 'border_color' => '#111827', 'border_width' => 0, 'radius' => 4, 'padding' => 6, 'font_size' => 12, 'alignment' => 'center', 'image_width' => 64, 'css_class' => '', 'image_media_id' => null,
            'translations' => ['en' => ['text' => 'New arrival', 'short_text' => 'New', 'tooltip' => 'Fresh'], 'el' => ['text' => 'Νέα άφιξη', 'short_text' => 'Νέο', 'tooltip' => '']], 'conditions' => [], 'products' => [(string) $a->id],
        ]);
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'manual', 'limit' => 48]);
        $p['block']['selections'] = ['items' => [(string) $a->id, (string) $b->id, (string) $c->id]];
        $layout['placements'] = [$p];
        $this->save($layout);
        $items = array_column($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'], null, 'id');
        self::assertCount(3, $items);
        self::assertSame(['New'], array_column($items[(string) $a->id]['stickers'], 'text'));
        self::assertSame('image_top', $items[(string) $a->id]['stickers'][0]['placement']);
        self::assertArrayNotHasKey('image_media_id', $items[(string) $a->id]['stickers'][0]);
        self::assertSame([], $items[(string) $b->id]['stickers']);
    }

    public function test_list_price_is_the_struck_through_reference_only_when_higher(): void
    {
        $listed = $this->product(price: '2799');
        $listed->update(['list_price' => '3000']);
        $lower = $this->product(price: '100');
        $lower->update(['list_price' => '50']);
        $plain = $this->product(price: '100');
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'manual', 'limit' => 48]);
        $p['block']['selections'] = ['items' => [(string) $listed->id, (string) $lower->id, (string) $plain->id]];
        $layout['placements'] = [$p];
        $this->save($layout);
        $items = array_column($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'], null, 'id');
        self::assertSame(['symbol', 'position', 'thousands', 'decimal', 'decimals'], array_keys(array_diff_key($this->rendered()['regions']['content'][0]['blocks'][0]['content']['currency_format'], ['code' => 1])));
        self::assertSame(['2799.00', '3000.00'], [$items[(string) $listed->id]['price'], $items[(string) $listed->id]['base_price']]);
        self::assertNull($items[(string) $lower->id]['base_price']);
        self::assertNull($items[(string) $plain->id]['base_price']);
        // The showcase card's badge: 2799 against 3000 is a 7% reduction (whole percent); none without a reference.
        self::assertSame(7, $items[(string) $listed->id]['discount_percent']);
        self::assertNull($items[(string) $plain->id]['discount_percent']);
        // The heart and the card shadow are shown unless the block switches them off.
        $display = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['display'];
        self::assertSame([true, true], [$display['show_favorites'], $display['card_shadow']]);
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings'] = [...$layout['placements'][0]['block']['settings'], 'show_favorites' => false, 'card_shadow' => false];
        $this->save($layout);
        $display = $this->rendered()['regions']['content'][0]['blocks'][0]['content']['display'];
        self::assertSame([false, false], [$display['show_favorites'], $display['card_shadow']]);
        // Reviews: on by default; cards carry the average and count of approved reviews only, from one grouped query.
        self::assertTrue($display['show_rating']);
        $customer = DB::table('customers')->insertGetId(['email' => 'rev@example.test', 'first_name' => 'Rev', 'last_name' => 'Iewer', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('customers')->insertGetId(['email' => 'rev2@example.test', 'first_name' => 'Rev', 'last_name' => 'Two', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$customer, 5, 'approved'], [$other, 4, 'approved']] as [$who, $stars, $status]) {
            DB::table('product_reviews')->insert(['product_id' => $listed->id, 'customer_id' => $who, 'rating' => $stars, 'body' => 'Good one.', 'status' => $status, 'moderated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('product_reviews')->insert(['product_id' => $lower->id, 'customer_id' => $customer, 'rating' => 1, 'body' => 'Pending.', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $items = array_column($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'], null, 'id');
        self::assertSame(['average' => '4.5', 'count' => 2], $items[(string) $listed->id]['rating']);
        self::assertNull($items[(string) $lower->id]['rating']);
        self::assertNull($items[(string) $plain->id]['rating']);
    }

    public function test_out_of_stock_products_can_be_kept_out_of_listings(): void
    {
        $stocked = $this->product();
        $empty = $this->product();
        $empty->update(['quantity' => 0]);
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'newest', 'limit' => 48]);
        $layout['placements'] = [$p];
        $this->save($layout);
        $ids = fn () => array_column($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'], 'id');
        self::assertEqualsCanonicalizing([(string) $stocked->id, (string) $empty->id], $ids());
        DB::table('general_settings')->where('id', 1)->update(['hide_out_of_stock_listings' => true]);
        app(GeneralSettings::class)->save((string) $this->actor->id, ['version' => DB::table('general_settings')->value('lock_version'), 'out_of_stock_action' => 'N']);
        self::assertSame([(string) $stocked->id], $ids());
        // The product itself stays publicly reachable for its own page.
        self::assertTrue(app(PublicCatalog::class)->products()->where('products.id', $empty->id)->exists());
    }

    public function test_a_duplicated_block_saves_even_when_it_carries_texts_of_inactive_languages(): void
    {
        $layout = $this->layout();
        $source = $layout['placements'][0];
        // Starter blocks store a row for every language, including inactive ones.
        self::assertGreaterThan(count(app(LocaleCatalog::class)->active()), count($source['block']['translations']));
        $grid = $this->grid(row: 9);
        $copy = $source;
        $copy['id'] = (string) Str::uuid();
        $copy['grid_id'] = $grid['id'];
        $copy['position'] = 1;
        $copy['block']['id'] = (string) Str::uuid();
        $layout['grids'][] = $grid;
        $layout['placements'][] = $copy;
        $this->save($layout);
        self::assertCount(4, $this->layout()['placements']);
        $unknown = $this->layout();
        $unknown['placements'][1]['block']['translations']['xx'] = ['title' => 'x', 'html' => ''];
        $this->put('/admin/layouts/homepage', $unknown)->assertSessionHasErrors();
    }

    public function test_header_account_icon_tracks_the_authenticated_session_without_requiring_a_customer_profile(): void
    {
        self::assertTrue($this->rendered()['regions']['header'][0]['blocks'][0]['content']['account_signed_in']);
        Auth::logout();
        self::assertFalse($this->rendered()['regions']['header'][0]['blocks'][0]['content']['account_signed_in']);
    }

    public function test_header_settings_top_bar_and_page_hiding(): void
    {
        $shared = $this->layout('default');
        $identity = collect($shared['placements'])->firstWhere('block.type', 'identity');
        $topbar = collect($shared['placements'])->firstWhere('block.type', 'topbar');
        self::assertNotNull($topbar);
        // An empty top bar renders nothing; the header follows the store colors by default.
        $page = $this->rendered();
        self::assertArrayNotHasKey('top_panel', $page['regions']);
        $header = $page['regions']['header'][0]['blocks'][0]['content'];
        self::assertSame(['var(--sf-primary)', 'var(--sf-on-primary)', true], [$header['display']['background_color'], $header['display']['text_color'], $header['display']['full_bleed']]);
        self::assertTrue($header['display']['show_cart']);
        self::assertArrayNotHasKey('show_account_label', $header['display']);
        // The search field needs an enabled search add-on (none in this test).
        self::assertFalse($header['display']['show_search']);
        // Header icons follow the header text color (white) until the block sets its own icon color.
        self::assertSame('', $header['display']['icon_color']);
        self::assertFalse($header['display']['show_call_request']);
        self::assertNull($header['call_request_form']);
        $withIcons = $shared;
        $withIcons['placements'] = array_map(function (array $p) use ($identity): array {
            if ($p['id'] === $identity['id']) {
                $p['block']['settings'] = [...$p['block']['settings'], 'icon_color' => '#FFD54F', 'show_call_request' => true];
            }

            return $p;
        }, $shared['placements']);
        $this->save($withIcons);
        self::assertSame('#ffd54f', $this->rendered()['regions']['header'][0]['blocks'][0]['content']['display']['icon_color']);
        self::assertSame($identity['block']['id'], $this->rendered()['regions']['header'][0]['blocks'][0]['content']['call_request_form']);
        $bad = $withIcons;
        $bad['placements'] = array_map(function (array $p) use ($identity): array {
            if ($p['id'] === $identity['id']) {
                $p['block']['settings'] = [...$p['block']['settings'], 'icon_color' => 'red'];
            }

            return $p;
        }, $shared['placements']);
        $bad['version'] = $this->layout('default')['version'];
        $this->put('/admin/layouts/default', $bad)->assertSessionHasErrors();
        $shared = $this->layout('default');

        $shared['placements'] = array_map(function (array $p) use ($identity, $topbar): array {
            if ($p['id'] === $identity['id']) {
                $p['block']['settings'] = ['show_logo' => true, 'show_search' => false, 'show_account' => true, 'show_favorites' => false, 'show_cart' => true, 'background_color' => '#112233', 'text_color' => '', 'hidden_paths' => "/checkout\n/checkout/*"];
            }
            if ($p['id'] === $topbar['id']) {
                $p['block']['settings'] = ['layout' => 'split', 'font_size_desktop' => 16, 'font_size_tablet' => 14, 'font_size_mobile' => 12, 'background_color' => '#ffffff', 'text_color' => '', 'hidden_paths' => ''];
                $p['block']['translations']['en'] = ['title' => '', 'html' => '<p><strong>Free</strong> shipping</p><script>x()</script>', 'html_right' => '<p>Discover the benefits</p>'];
            }

            return $p;
        }, $shared['placements']);
        $this->save($shared);
        $page = $this->rendered();
        $header = $page['regions']['header'][0]['blocks'][0]['content']['display'];
        self::assertSame([false, false, '#112233'], [$header['show_search'], $header['show_favorites'], $header['background_color']]);
        $bar = $page['regions']['top_panel'][0]['blocks'][0]['content'];
        self::assertStringContainsString('<strong>Free</strong>', $bar['html']);
        self::assertStringNotContainsString('script', $bar['html']);
        self::assertSame('<p>Discover the benefits</p>', $bar['html_right']);
        self::assertSame(16, $bar['display']['font_size_desktop']);

        // Hidden on the listed pages only.
        $on = fn (string $path) => json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null, $path)), true)['regions'];
        self::assertArrayHasKey('header', $on('/'));
        self::assertArrayNotHasKey('header', $on('/checkout'));
        self::assertArrayNotHasKey('header', $on('/checkout/payment'));
        self::assertArrayHasKey('header', $on('/checkouts'));

        $bad = $this->layout('default');
        foreach (['checkout', '/a b', '/x;y', '/ok/*/no'] as $line) {
            $bad['placements'] = array_map(function (array $p) use ($line): array {
                if ($p['block']['type'] === 'identity') {
                    $p['block']['settings']['hidden_paths'] = $line;
                }

                return $p;
            }, $shared['placements']);
            $bad['version'] = $this->layout('default')['version'];
            $this->put('/admin/layouts/default', $bad)->assertSessionHasErrors();
        }
    }

    public function test_optional_see_all_link_needs_text_and_a_safe_url(): void
    {
        $product = $this->product();
        $grid = $this->layout()['grids'][0]['id'];
        $with = function (string $url, string $text) use ($product, $grid): array {
            $p = $this->placement($grid, 'products', settings: ['source' => 'manual', 'limit' => 8, 'link_url' => $url]);
            $p['block']['selections'] = ['items' => [(string) $product->id]];
            $p['block']['translations']['en']['link_text'] = $text;

            return [...$this->layout(), 'placements' => [$p]];
        };
        $content = fn () => $this->rendered()['regions']['content'][0]['blocks'][0]['content'];
        $this->save($with('/technologia', 'See all'));
        self::assertSame(['text' => 'See all', 'url' => '/technologia'], $content()['link']);
        $this->save($with('/technologia', ''));
        self::assertArrayNotHasKey('link', $content());
        $this->save($with('', 'See all'));
        self::assertArrayNotHasKey('link', $content());
        foreach (['javascript:alert(1)', '//evil.test', 'ftp://x.test', '/a b'] as $bad) {
            $this->put('/admin/layouts/homepage', $with($bad, 'x'))->assertSessionHasErrors();
        }
    }

    public function test_menu_block_renders_public_menus_and_language_switcher(): void
    {
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $menu = fn (string $name, string $status = 'active') => (function () use ($en, $name, $status): int {
            $id = DB::table('website_menus')->insertGetId(['status' => $status, 'placement' => 'header', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_menu_translations')->insert(['menu_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        })();
        $item = function (int $menu, ?int $parent, string $name, string $kind = 'url', ?string $url = '/x', string $status = 'active') use ($en): int {
            $id = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'parent_id' => $parent, 'status' => $status, 'position' => 10, 'kind' => $kind, 'url' => $kind === 'url' ? $url : null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_menu_item_translations')->insert(['item_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        };
        $main = $menu('Main');
        $top = $item($main, null, 'Categories', 'heading', null);
        $books = $item($main, $top, 'Books', 'url', '/books');
        $item($main, $books, 'All books', 'url', '/books/all');
        $item($main, $top, 'Hidden', 'url', '/hidden', 'disabled');
        $item($main, $top, 'Unsafe', 'url', 'javascript:alert(1)');
        $item($main, null, 'Empty heading', 'heading', null);
        $secondary = $menu('Links');
        $item($secondary, null, 'Gift card', 'url', '/gift-card');
        $disabled = $menu('Off', 'disabled');
        $item($disabled, null, 'Never', 'url', '/never');

        // The editor offers the menus for the block's pickers.
        $this->get('/admin/layouts/default')->assertOk()->assertInertia(fn (Assert $page) => $page->where('options.website_menus', fn ($options) => collect($options)->pluck('name')->contains('Main')));
        $shared = $this->layout('default');
        $placement = collect($shared['placements'])->firstWhere('block.type', 'menu');
        self::assertNotNull($placement);
        // Without a chosen menu the block renders nothing.
        self::assertArrayNotHasKey('navigation', $this->rendered()['regions']);
        $shared['placements'] = array_map(function (array $p) use ($placement, $main, $secondary): array {
            if ($p['id'] === $placement['id']) {
                $p['block']['selections'] = ['main' => [(string) $main], 'secondary' => [(string) $secondary]];
                $p['block']['settings'] = ['secondary_mobile' => false, 'mobile_style' => 'burger', 'hidden_paths' => '/checkout/*'];
            }

            return $p;
        }, $shared['placements']);
        $this->save($shared);
        // Reading a saved menu selection returns each chosen menu once, so the layout can be saved again unchanged.
        $reloaded = collect($this->layout('default')['placements'])->firstWhere('block.type', 'menu')['block']['selections'];
        self::assertSame(['main' => [(string) $main], 'secondary' => [(string) $secondary]], $reloaded);
        $this->save($this->layout('default'));
        $content = $this->rendered()['regions']['navigation'][0]['blocks'][0]['content'];
        self::assertSame(['Categories'], array_column($content['main'], 'name'));
        self::assertSame(['Books'], array_column($content['main'][0]['children'], 'name'));
        self::assertSame(['All books'], array_column($content['main'][0]['children'][0]['children'], 'name'));
        self::assertSame(['Gift card'], array_column($content['secondary'], 'name'));
        self::assertSame(['en', 'el'], array_column($content['languages'], 'code'));
        self::assertTrue($content['display']['main_desktop'] && ! $content['display']['secondary_mobile'] && $content['display']['allow_overflow']);
        foreach (['menu'] as $template) {
            $shared = $this->layout('default');
            foreach ($shared['placements'] as &$p) {
                if ($p['block']['type'] === 'menu') {
                    $p['block']['template'] = $template;
                    $p['block']['settings']['show_language_switcher'] = false;

                }
            }
            unset($p);
            $this->save($shared);
            $public = $this->rendered()['regions']['navigation'][0]['blocks'][0];
            self::assertSame($template, $public['template']);
            self::assertSame([], $public['content']['languages']);
            self::assertFalse($public['content']['display']['show_language_switcher']);
        }

        $on = fn (string $path) => json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null, $path)), true)['regions'];
        self::assertArrayHasKey('navigation', $on('/'));
        self::assertArrayNotHasKey('navigation', $on('/checkout/pay'));
        // A disabled menu is never public, even when a block points at it.
        DB::table('website_menus')->where('id', $main)->update(['status' => 'disabled']);
        self::assertArrayHasKey('navigation', $on('/'));
        self::assertSame([], $on('/')['navigation'][0]['blocks'][0]['content']['main']);

        // Language switching: only active languages, remembered in the session, redirected back inside the site.
        $this->get('/locale/xx')->assertNotFound();
        $this->get('/locale/fr')->assertNotFound();
        $this->withHeader('referer', 'http://localhost/books')->get('/locale/en')->assertRedirect('/books')->assertSessionHas('storefront_locale', 'en');
        $this->withHeader('referer', 'https://evil.test/phish')->get('/locale/el')->assertRedirect('/');
        // A same-host referer whose path starts with // or /\ would otherwise leave the site (protocol-relative redirect).
        $this->withHeader('referer', 'http://localhost//evil.test/phish')->get('/locale/el')->assertRedirect('/');
        $this->withHeader('referer', 'http://localhost/%5Cevil.test')->get('/locale/el')->assertRedirect('/%5Cevil.test');
    }

    public function test_menu_item_pictures_are_public_only_for_active_items(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('images/menu.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
        $file = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'images/menu.png', 'mime_type' => 'image/png', 'byte_size' => 70, 'width' => 1, 'height' => 1]);
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $menu = DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => 'header', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'status' => 'active', 'position' => 10, 'kind' => 'url', 'url' => '/x', 'image_media_id' => $file->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/storefront/media/menu-item/'.$item.'/'.$file->id)->assertOk();
        $this->get('/storefront/media/menu-item/'.$item.'/999999')->assertNotFound();
        DB::table('website_menu_items')->where('id', $item)->update(['status' => 'disabled']);
        $this->get('/storefront/media/menu-item/'.$item.'/'.$file->id)->assertNotFound();
        self::assertNotNull($en);
    }

    public function test_demo_store_creates_a_ready_storefront_once(): void
    {
        $this->artisan('cms:demo-store')->assertSuccessful();
        $en = DB::table('locales')->where('code', 'en')->value('id');
        self::assertSame(6, DB::table('categories')->whereNull('parent_id')->whereNull('system_key')->count());
        self::assertSame(count(CatalogDemoSeeder::CATEGORIES), DB::table('category_translations')->where('locale_id', $en)->count());
        // Every demo category can be saved in the strict editor right away: its SEO texts are complete in each language.
        self::assertSame(0, DB::table('category_translations')->where(fn ($q) => $q->where('seo_title', '')->orWhere('seo_description', '')->orWhere('meta_keywords', ''))->count());
        self::assertSame(37, DB::table('products')->where('sku', 'like', 'TECH-%')->count());
        // Every product and category has its bundled photo, and some products are on sale.
        self::assertSame(0, DB::table('products')->where('sku', 'like', 'TECH-%')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('product_images')->whereColumn('product_images.product_id', 'products.id'))->count());
        self::assertSame(0, DB::table('categories')->whereNull('system_key')->whereNull('image_media_id')->count());
        self::assertGreaterThan(0, DB::table('product_sales')->count());
        $this->artisan('cms:demo-store')->assertSuccessful();
        self::assertSame(37, DB::table('products')->where('sku', 'like', 'TECH-%')->count());
        // The built-in layouts get sample page texts once and keep what the merchant writes afterwards.
        $texts = fn (string $page) => DB::table('storefront_layout_translations')->join('storefront_layouts', 'storefront_layouts.id', '=', 'layout_id')->where('page_type', $page)->where('locale_id', $en)->first();
        self::assertNotSame('', $texts('default')->page_title);
        self::assertNotSame('', $texts('homepage')->meta_description);
        DB::table('storefront_layout_translations')->where('layout_id', $texts('homepage')->layout_id)->where('locale_id', $en)->update(['page_title' => 'Merchant title']);
        $this->artisan('cms:demo-store')->assertSuccessful();
        self::assertSame('Merchant title', $texts('homepage')->page_title);
        $names = DB::table('category_translations')->where('locale_id', $en)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        self::assertSame($names->unique()->count(), $names->count());
        Cache::flush();
        $page = $this->rendered();
        // Menus are built by hand from the categories: links are full category paths, six first-level entries.
        // The compact header opens the main menu from its search bar (the drawer).
        $identity = collect($page['regions'])->flatten(1)->flatMap(fn (array $grid): array => $grid['blocks'] ?? [])->firstWhere('template', 'identity');
        $mega = $identity['content']['drawer'][0];
        self::assertSame('Categories', $mega['name']);
        self::assertCount(6, $mega['children']);
        self::assertSame('/phones', $mega['children'][0]['url']);
        self::assertSame('/phones/smartphones', $mega['children'][0]['children'][0]['url']);
        // Product links are the category path plus the product slug.
        $items = array_column(collect($page['regions']['content'])->flatMap(fn (array $grid): array => $grid['blocks'])->firstWhere('title', 'New arrivals')['content']['items'], null, 'name');
        self::assertSame('/phones/smartphones/aster-x5', $items['Aster X5 128GB']['url']);
        foreach ($items as $item) {
            self::assertMatchesRegularExpression('#^/[a-z0-9-]+(/[a-z0-9-]+)+$#', $item['url']);
        }
        // The starter categories block shows the sample categories.
        self::assertGreaterThanOrEqual(5, count(collect($page['regions']['content'])->flatMap(fn ($g) => $g['blocks'])->firstWhere('template', 'category_carousel')['content']['items']));
    }

    public function test_demo_store_with_admin_creates_a_local_super_administrator_and_fills_the_whole_store(): void
    {
        Storage::fake('local');
        $this->artisan('cms:demo-store', ['--with-admin' => true, '--email' => 'demo@example.com'])->expectsOutputToContain('Password:')->assertSuccessful();
        $admin = UserRecord::where('email', 'demo@example.com')->sole();
        self::assertTrue($admin->hasVerifiedEmail());
        self::assertTrue($admin->roles()->where('is_super_admin', true)->exists());
        self::assertTrue((bool) $admin->staff_password);
        self::assertGreaterThan(0, DB::table('banners')->count());
        self::assertGreaterThan(0, DB::table('blog_posts')->count());
        self::assertGreaterThan(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('products')->where('sku', 'like', 'TECH-%')->whereNull('brand_id')->count());
        self::assertSame(['Deals of the week', 'Laptops for every need', 'Customer favourites', 'Our brands', 'From the blog'], DB::table('storefront_blocks')->whereIn('name', ['Deals of the week', 'Laptops for every need', 'Customer favourites', 'Our brands', 'From the blog'])->join('storefront_placements', 'storefront_placements.block_id', '=', 'storefront_blocks.id')->join('storefront_grids', 'storefront_grids.id', '=', 'storefront_placements.grid_id')->orderBy('storefront_grids.row')->pluck('name')->all());
        // Every homepage block shows content: banners, deals, laptops, favourites, brands and blog posts.
        Cache::flush();
        $all = collect($this->rendered()['regions']['content'])->flatMap(fn (array $grid): array => $grid['blocks']);
        $blocks = $all->keyBy('title');
        foreach (['Deals of the week', 'Laptops for every need', 'Customer favourites', 'Our brands', 'From the blog', 'New arrivals'] as $title) {
            self::assertNotEmpty($blocks[$title]['content']['items'] ?? [], $title);
        }
        // The hero shows its own group; the promotions row: two carousels and a single banner either side, a tall one between.
        foreach ($all->whereIn('template', ['banner_carousel', 'banner_single']) as $banners) {
            self::assertNotEmpty($banners['content']['items'] ?? []);
        }
        self::assertSame(['banner_carousel', 'banner_single', 'banner_single', 'banner_carousel', 'banner_single'], DB::table('storefront_blocks')->where('name', 'like', 'Promotions%')
            ->join('storefront_placements', 'storefront_placements.block_id', '=', 'storefront_blocks.id')->join('storefront_grids', 'storefront_grids.id', '=', 'storefront_placements.grid_id')
            ->orderBy('storefront_grids.position')->orderBy('storefront_placements.position')->pluck('template')->all());
        self::assertSame(1, DB::table('storefront_grids')->whereIn('id', DB::table('storefront_placements')->whereIn('block_id', DB::table('storefront_blocks')->where('name', 'like', 'Promotions%')->select('id'))->select('grid_id'))->distinct()->count('row'));
        self::assertTrue($all->firstWhere('content.display.fill_height', true)['template'] === 'banner_single');
        // The Black Friday landing: its hero first, the deals on a dark band, the page's intro and FAQs last; linked from the menus.
        $landing = DB::table('storefront_layouts')->where('page_id', DB::table('page_translations')->where('slug', 'black-friday')->value('page_id'))->value('id');
        self::assertSame(['Black Friday hero', 'Black Friday categories', 'Black Friday deals'], DB::table('storefront_blocks')->where('storefront_blocks.layout_id', $landing)
            ->join('storefront_placements', 'storefront_placements.block_id', '=', 'storefront_blocks.id')->join('storefront_grids', 'storefront_grids.id', '=', 'storefront_placements.grid_id')
            ->orderBy('storefront_grids.row')->orderBy('storefront_grids.position')->limit(3)->pluck('name')->all());
        self::assertSame('main_content', DB::table('storefront_blocks')->where('storefront_blocks.layout_id', $landing)->join('storefront_placements', 'storefront_placements.block_id', '=', 'storefront_blocks.id')
            ->join('storefront_grids', 'storefront_grids.id', '=', 'storefront_placements.grid_id')->orderByDesc('storefront_grids.row')->value('type'));
        self::assertFalse((bool) DB::table('storefront_placements')->where('layout_id', $landing)->whereIn('block_id', DB::table('storefront_blocks')->where('type', 'main_content')->select('id'))->value('enabled'));
        self::assertSame(2, DB::table('website_menu_items')->where('url', '/black-friday')->count());
        $this->get('/black-friday')->assertOk();
        // Rerunning keeps the account (and its password) and creates nothing twice.
        $this->artisan('cms:demo-store', ['--with-admin' => true, '--email' => 'demo@example.com'])->expectsOutputToContain('already exists')->assertSuccessful();
        self::assertSame(1, UserRecord::where('email', 'demo@example.com')->count());
        // A known demo login never reaches a real store, even with --force.
        app()->detectEnvironment(fn (): string => 'production');
        $this->artisan('cms:demo-store', ['--with-admin' => true, '--force' => true, '--email' => 'other@example.com'])->assertFailed();
        self::assertFalse(UserRecord::where('email', 'other@example.com')->exists());
    }

    public function test_demo_store_reuses_existing_categories_instead_of_duplicating_names(): void
    {
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $mine = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $mine->translations()->create(['locale_id' => $en, 'name' => 'Ultrabooks', 'slug' => 'my-ultrabooks']);
        $this->artisan('cms:demo-store')->assertSuccessful();
        $names = DB::table('category_translations')->where('locale_id', $en)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        self::assertSame($names->unique()->count(), $names->count());
        // Demo products that belong to Ultrabooks now sit in the merchant's own category.
        self::assertGreaterThanOrEqual(2, DB::table('products')->where('sku', 'like', 'TECH-%')->where('category_id', $mine->id)->count());
    }

    public function test_image_menu_block_reuses_live_menu_items_and_all_templates(): void
    {
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $menu = DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => 'other', 'source' => 'manual', 'style' => 'standard', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_menu_translations')->insert(['menu_id' => $menu, 'locale_id' => $en, 'name' => 'Explore']);
        $key = 'images/visual-menu.png';
        Storage::disk('local')->put($key, 'image');
        $media = MediaFileRecord::create(['disk' => 'local', 'storage_key' => $key, 'mime_type' => 'image/png', 'byte_size' => 5, 'width' => 100, 'height' => 100]);
        $add = function (string $name, int $position, string $status = 'active', ?int $parent = null, string $url = '/offers') use ($menu, $en, $media): int {
            $id = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'parent_id' => $parent, 'status' => $status, 'position' => $position, 'kind' => 'url', 'url' => $url, 'new_window' => true, 'image_media_id' => $media->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_menu_item_translations')->insert(['item_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        };
        $add('Second', 2);
        $first = $add('First', 1);
        $add('Disabled', 0, 'disabled');
        $add('Unsafe', 0, url: 'javascript:alert(1)');
        $add('Child', 1, parent: $first);
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'visual_menu');
        $p['block']['selections'] = ['menu' => [(string) $menu]];
        foreach (['menu_tiles_carousel', 'menu_circles_carousel', 'menu_cards_carousel'] as $template) {
            $p['block']['template'] = $template;
            $layout = $this->layout();
            $layout['placements'] = [$p];
            $this->save($layout);
            $block = $this->rendered()['regions']['content'][0]['blocks'][0];
            self::assertSame($template, $block['template']);
            self::assertSame(['First', 'Second'], array_column($block['content']['items'], 'name'));
            self::assertSame('/storefront/media/menu-item/'.$first.'/'.$media->id, $block['content']['items'][0]['image']['url']);
            self::assertTrue($block['content']['items'][0]['new_window']);
            self::assertArrayNotHasKey('children', $block['content']['items'][0]);
        }
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['limit'] = 1;
        $this->save($layout);
        self::assertCount(1, $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items']);
        DB::table('website_menu_items')->where('id', $first)->update(['status' => 'disabled']);
        self::assertSame('Second', $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'][0]['name']);
        DB::table('website_menus')->where('id', $menu)->update(['status' => 'disabled']);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        Storage::disk('local')->delete($key);
    }

    public function test_link_directory_block_shows_only_link_group_menus(): void
    {
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $menu = function (string $name, string $style) use ($en): int {
            $id = DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => 'other', 'source' => 'manual', 'style' => $style, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_menu_translations')->insert(['menu_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        };
        $item = function (int $menu, ?int $parent, string $name, string $url) use ($en): int {
            $id = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'parent_id' => $parent, 'status' => 'active', 'position' => 10, 'kind' => 'url', 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_menu_item_translations')->insert(['item_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        };
        $links = $menu('Directory', 'link_groups');
        $books = $item($links, null, 'Books', '/books');
        $item($links, $books, 'E-books', '/e-books');
        $item($links, $books, 'E-readers', '/e-readers');
        $standard = $menu('Standard', 'standard');
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'link_directory', settings: ['columns' => 4]);
        $p['block']['selections'] = ['menu' => [(string) $standard]];
        $layout['placements'] = [$p];
        // Only link-groups menus can be chosen.
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        $p['block']['selections'] = ['menu' => [(string) $links]];
        $layout['placements'] = [$p];
        $this->save($layout);
        $content = $this->rendered()['regions']['content'][0]['blocks'][0]['content'];
        self::assertSame(['Books'], array_column($content['groups'], 'name'));
        self::assertSame(['E-books', 'E-readers'], array_column($content['groups'][0]['children'], 'name'));
        self::assertSame(4, $content['display']['columns']);
        // The editor offers each kind of menu to its own block.
        $this->get('/admin/layouts/homepage')->assertInertia(fn (Assert $page) => $page->where('options.link_menus', fn ($o) => collect($o)->pluck('name')->all() === ['Directory'])->where('options.website_menus', fn ($o) => collect($o)->pluck('name')->all() === ['Standard']));
    }

    public function test_footer_block_renders_link_groups_text_lines_and_a_bottom_line_on_every_page(): void
    {
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $menu = DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => 'footer', 'source' => 'manual', 'style' => 'link_groups', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_menu_translations')->insert(['menu_id' => $menu, 'locale_id' => $en, 'name' => 'Footer']);
        $item = function (?int $parent, string $name, string $kind, ?string $url) use ($menu, $en): int {
            $id = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'parent_id' => $parent, 'status' => 'active', 'position' => 10, 'kind' => $kind, 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_menu_item_translations')->insert(['item_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        };
        $contact = $item(null, 'Contact us', 'heading', null);
        $item($contact, '44 Main street', 'heading', null);
        $item($contact, 'demo@example.com', 'url', 'mailto:demo@example.com');
        $item($contact, '+1 917 555 5555', 'url', 'tel:+19175555555');
        $item(null, 'Empty heading', 'heading', null);
        $shared = $this->layout('default');
        $footer = collect($shared['placements'])->firstWhere('block.type', 'footer');
        self::assertNotNull($footer);
        // Nothing is shown until the footer has content.
        self::assertArrayNotHasKey('footer', $this->rendered()['regions']);
        $shared['placements'] = array_map(function (array $p) use ($footer, $menu): array {
            if ($p['id'] === $footer['id']) {
                $p['block']['selections'] = ['menu' => [(string) $menu]];
                $p['block']['settings'] = ['columns' => 3, 'mobile_style' => 'accordion', 'show_top_border' => true, 'hidden_paths' => '/checkout'];
                $p['block']['translations']['en']['html'] = '<p>© Demo</p><script>x()</script>';
            }

            return $p;
        }, $shared['placements']);
        $this->save($shared);
        $content = $this->rendered()['regions']['footer'][0]['blocks'][0]['content'];
        self::assertSame(['Contact us'], array_column($content['groups'], 'name'));
        // Text lines and contact links are kept; an empty heading is not.
        self::assertSame(['44 Main street', 'demo@example.com', '+1 917 555 5555'], array_column($content['groups'][0]['children'], 'name'));
        self::assertSame(['mailto:demo@example.com', 'tel:+19175555555'], [$content['groups'][0]['children'][1]['url'], $content['groups'][0]['children'][2]['url']]);
        self::assertSame('<p>© Demo</p>', $content['html']);
        self::assertSame([3, 'accordion', true, true], [$content['display']['columns'], $content['display']['mobile_style'], $content['display']['show_top_border'], $content['display']['full_bleed']]);
        // It lives in the shared layout, so every page shows it, except where the block hides it.
        $on = fn (string $path) => json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null, $path)), true)['regions'];
        self::assertArrayHasKey('footer', $on('/'));
        self::assertArrayHasKey('footer', $on('/anything/else'));
        self::assertArrayNotHasKey('footer', $on('/checkout'));
    }

    public function test_footer_shows_social_links_apps_payment_icons_and_the_store_name(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('images/pay.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
        $file = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'images/pay.png', 'mime_type' => 'image/png', 'byte_size' => 70, 'width' => 1, 'height' => 1]);
        DB::table('company_settings')->where('id', 1)->update(['name' => 'Acme Tech']);
        $link = fn (array $values): int => DB::table('company_links')->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);
        $link(['kind' => 'app', 'preset' => 'app_store', 'name' => 'App Store', 'url' => 'https://apps.apple.com/app/acme', 'position' => 1]);
        $icon = DB::table('company_payment_icons')->insertGetId(['media_file_id' => $file->id, 'name' => 'Visa', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $socialIcon = $link(['kind' => 'social', 'preset' => 'facebook', 'name' => 'Facebook', 'url' => 'https://facebook.com/acme', 'media_file_id' => $file->id, 'position' => 1]);
        $shared = $this->layout('default');
        $footer = collect($shared['placements'])->firstWhere('block.type', 'footer');
        $shared['placements'] = array_map(function (array $p) use ($footer): array {
            if ($p['id'] === $footer['id']) {
                $p['block']['settings'] = ['columns' => 4, 'heading_size' => 14, 'text_size' => 13];
                $p['block']['translations']['en']['html'] = '<p>© {year} {store_name}</p>';
            }

            return $p;
        }, $shared['placements']);
        $this->save($shared);
        $content = $this->rendered()['regions']['footer'][0]['blocks'][0]['content'];
        // Only filled-in links appear; the store name and year come from the settings.
        self::assertSame(['facebook'], array_column($content['social'], 'network'));
        self::assertSame('/storefront/media/social-icon/'.$socialIcon.'/'.$file->id, $content['social'][0]['image']['url']);
        $this->get($content['social'][0]['image']['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storefront/media/social-icon/'.$socialIcon.'/999999')->assertNotFound();
        DB::table('company_links')->where('id', $socialIcon)->update(['media_file_id' => null]);
        $this->get($content['social'][0]['image']['url'])->assertNotFound();
        self::assertNull($this->rendered()['regions']['footer'][0]['blocks'][0]['content']['social'][0]['image']);
        self::assertSame([['network' => 'app_store', 'name' => 'App Store', 'url' => 'https://apps.apple.com/app/acme', 'image' => null]], $content['apps']);
        self::assertSame('<p>© '.now()->year.' Acme Tech</p>', $content['html']);
        self::assertSame(['Visa', '/storefront/media/payment-icon/'.$icon.'/'.$file->id], [$content['payments'][0]['name'], $content['payments'][0]['image']['url']]);
        self::assertSame([14, 13], [$content['display']['heading_size'], $content['display']['text_size']]);
        $this->get('/storefront/media/payment-icon/'.$icon.'/'.$file->id)->assertOk();
        $this->get('/storefront/media/payment-icon/'.$icon.'/999999')->assertNotFound();
        // Each part can be switched off in the block.
        $shared = $this->layout('default');
        $shared['placements'] = array_map(function (array $p): array {
            if ($p['block']['type'] === 'footer') {
                $p['block']['settings'] = ['show_social' => false, 'show_apps' => false, 'show_payments' => false];
            }

            return $p;
        }, $shared['placements']);
        $this->save($shared);
        $off = $this->rendered()['regions']['footer'][0]['blocks'][0]['content'];
        self::assertSame([[], [], []], [$off['social'], $off['apps'], $off['payments']]);
    }

    public function test_newsletter_block_registers_visitors_in_the_chosen_list_only(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $list = function (string $name, string $status = 'active') use ($en): int {
            $id = DB::table('newsletter_lists')->insertGetId(['status' => $status, 'show_at_checkout' => false, 'show_on_registration' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('newsletter_list_translations')->insert(['list_id' => $id, 'locale_id' => $en, 'name' => $name, 'description' => '']);

            return $id;
        };
        $weekly = $list('Weekly');
        $other = $list('Other');
        $off = $list('Paused', 'disabled');
        $layout = $this->layout();
        $block = fn (int $list) => (function () use ($layout, $list): array {
            $p = $this->placement($layout['grids'][0]['id'], 'newsletter', settings: ['terms_url' => '/terms', 'background_color' => '#dff0fd', 'full_width_background' => true]);
            $p['block']['selections'] = ['list' => [(string) $list]];

            return [...$this->layout(), 'placements' => [$p], 'form' => $p['block']['id']];
        })();
        // The editor offers the active lists.
        $this->get('/admin/layouts/homepage')->assertInertia(fn (Assert $page) => $page->where('options.newsletter_lists', fn ($o) => collect($o)->pluck('name')->sort()->values()->all() === ['Other', 'Weekly']));
        $saved = $block($weekly);
        $form = $saved['form'];
        unset($saved['form']);
        $this->save($saved);
        $content = $this->rendered()['regions']['content'][0]['blocks'][0]['content'];
        self::assertSame([$form, false, '/terms', '#dff0fd', true, 24, 24], [$content['form'], $content['confirm'], $content['terms_url'], $content['display']['background_color'], $content['display']['full_width_background'], $content['display']['padding_top'], $content['display']['padding_bottom']]);
        // Signing up joins the block's list, unconfirmed, and answers the same way for known addresses.
        $this->postJson('/newsletter/subscribe', ['email' => 'visitor@example.test', 'form' => $form])->assertOk()->assertJson(['status' => 'ok', 'confirm' => false]);
        $this->postJson('/newsletter/subscribe', ['email' => 'VISITOR@example.test', 'form' => $form])->assertOk()->assertJson(['status' => 'ok']);
        $subscriber = DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', ['visitor@example.test'])->sole();
        self::assertSame('storefront', $subscriber->source);
        self::assertSame([$weekly], DB::table('newsletter_list_subscribers')->where('subscriber_id', $subscriber->id)->pluck('list_id')->map(intval(...))->all());
        self::assertNull(DB::table('newsletter_list_subscribers')->where('subscriber_id', $subscriber->id)->value('confirmed_at'));
        // Invalid addresses, bots, unknown blocks and other blocks' lists are refused.
        $this->postJson('/newsletter/subscribe', ['email' => 'not-an-email', 'form' => $form])->assertStatus(422);
        $this->postJson('/newsletter/subscribe', ['email' => 'bot@example.test', 'form' => $form, 'website' => 'http://spam'])->assertOk();
        self::assertFalse(DB::table('newsletter_subscribers')->where('email', 'bot@example.test')->exists());
        $this->postJson('/newsletter/subscribe', ['email' => 'x@example.test', 'form' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/newsletter/subscribe', ['email' => 'x@example.test', 'form' => 'not-a-uuid'])->assertStatus(422);
        // A disabled list shows no form and accepts nobody.
        DB::table('newsletter_lists')->where('id', $weekly)->update(['status' => 'disabled']);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        $this->postJson('/newsletter/subscribe', ['email' => 'late@example.test', 'form' => $form])->assertNotFound();
        self::assertNotNull($other);
        self::assertNotNull($off);
    }

    public function test_newsletter_sign_up_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/newsletter/subscribe', ['email' => 'a@example.test', 'form' => (string) Str::uuid()])->assertNotFound();
        }
        $this->postJson('/newsletter/subscribe', ['email' => 'a@example.test', 'form' => (string) Str::uuid()])->assertStatus(429);
    }

    public function test_the_header_logo_is_preloaded_and_cached_by_the_browser(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('images/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
        $file = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'images/logo.png', 'mime_type' => 'image/png', 'byte_size' => 70, 'width' => 1, 'height' => 1]);
        $this->get('/')->assertDontSee('rel="preload"', false);
        DB::table('company_settings')->where('id', 1)->update(['logo_media_id' => $file->id]);
        $url = '/storefront/media/company/1/'.$file->id;
        $this->get('/')->assertSee('<link rel="preload" as="image" href="'.$url.'" fetchpriority="high">', false);
        // With a mobile logo each one is preloaded only where the header uses it.
        Storage::disk('local')->put('images/logo-small.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
        $small = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'images/logo-small.png', 'mime_type' => 'image/png', 'byte_size' => 70, 'width' => 1, 'height' => 1]);
        DB::table('company_settings')->where('id', 1)->update(['logo_mobile_media_id' => $small->id]);
        $this->get('/')->assertSee('href="'.$url.'" fetchpriority="high" media="(min-width: 768px)"', false)->assertSee('media="(max-width: 767px)"', false);
        // The administration does not preload the storefront logo, and the logo file is cached for a year.
        $this->get('/admin')->assertDontSee('rel="preload"', false);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
    }

    public function test_the_first_banner_can_be_preloaded_and_others_are_not(): void
    {
        $banner = fn (string $url) => ['settings' => [], 'products' => [], 'devices' => ['pc' => ['layout' => [], 'content' => ['main_image' => null, 'background_image' => $url]], 'tablet' => ['layout' => [], 'content' => ['main_image' => '/storefront/media/banner/1/11', 'background_image' => null]], 'phone' => ['layout' => [], 'content' => ['main_image' => 'https://evil.test/x.png', 'background_image' => null]]]];
        $page = fn (string|bool $preload) => ['regions' => ['content' => [['children' => [], 'blocks' => [
            ['template' => 'product_carousel', 'content' => ['display' => ['preload' => true], 'items' => [['banner' => $banner('/storefront/media/banner/9/99')]]]],
            ['template' => 'banner_carousel', 'content' => ['display' => ['preload' => $preload], 'items' => [['banner' => $banner('/storefront/media/banner/1/10')], ['banner' => $banner('/storefront/media/banner/2/20')]]]],
        ]]]]];
        $links = app(BannerPreload::class);
        self::assertSame([], $links->links($page('none')));
        // "First": only the first banner of the banner block, per device, and only our own media URLs.
        $first = [
            ['href' => '/storefront/media/banner/1/11', 'media' => '(min-width: 768px) and (max-width: 1023px)'],
            ['href' => '/storefront/media/banner/1/10', 'media' => '(min-width: 1024px)'],
        ];
        self::assertSame($first, $links->links($page('first')));
        // "All": every banner of that block.
        self::assertSame([...$first, ['href' => '/storefront/media/banner/2/20', 'media' => '(min-width: 1024px)']], $links->links($page('all')));
        // The block setting is validated, none by default; the old on/off value still means none or first.
        $banners = app(StorefrontRegistry::class)->type('banners');
        self::assertSame('none', $banners->validate(['source' => 'latest', 'limit' => 3])['preload']);
        self::assertSame(['first', 'none', 'all'], [$banners->validate(['source' => 'latest', 'limit' => 3, 'preload' => true])['preload'], $banners->validate(['source' => 'latest', 'limit' => 3, 'preload' => false])['preload'], $banners->validate(['source' => 'latest', 'limit' => 3, 'preload' => 'all'])['preload']]);
        $this->expectException(ValidationException::class);
        $banners->validate(['source' => 'latest', 'limit' => 3, 'preload' => 'some']);
    }

    public function test_the_storefront_ignores_the_administration_dark_theme(): void
    {
        $this->get('/')->assertOk()->assertDontSee('cms-theme', false);
        $this->get('/admin')->assertOk()->assertSee('cms-theme', false);
    }

    private function banner(string $title, string $date, ?int $group = null, int $position = 1): int
    {
        $id = DB::table('banners')->insertGetId(['status' => 'active', 'created_on' => $date, 'group_id' => $group, 'position' => $position, 'url' => 'https://example.test', 'new_window' => true]);
        DB::table('banner_contents')->insert(['banner_id' => $id, 'device' => 'pc', 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'title' => $title]);

        return $id;
    }

    public function test_banner_manual_latest_group_scheduling_and_live_changes(): void
    {
        $group = DB::table('banner_groups')->insertGetId(['name' => 'Campaign']);
        $a = $this->banner('First', '2026-10-01', $group, 2);
        $b = $this->banner('Second', '2026-10-02', $group);
        $other = $this->banner('Other', '2026-10-03');
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'banners', settings: ['source' => 'manual', 'limit' => 8]);
        $p['block']['selections'] = ['items' => [(string) $a, (string) $b], 'group' => [(string) $group]];
        $layout['placements'] = [$p];
        $this->save($layout);
        $items = fn () => $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'] ?? [];
        self::assertSame([(string) $a, (string) $b], array_column($items(), 'id'));
        self::assertTrue($items()[0]['banner']['settings']['new_window']);
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['source'] = 'latest';
        $this->save($layout);
        self::assertSame([(string) $other, (string) $b, (string) $a], array_column($items(), 'id'));
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['source'] = 'group';
        $this->save($layout);
        self::assertSame([(string) $b, (string) $a], array_column($items(), 'id'));
        DB::table('banners')->where('id', $a)->update(['available_from' => now()->addHour()]);
        DB::table('banners')->where('id', $b)->update(['available_until' => now()]);
        self::assertSame([], $items());
        DB::table('banners')->where('id', $a)->update(['available_from' => now()]);
        self::assertSame([(string) $a], array_column($items(), 'id'));
        DB::table('banners')->where('id', $a)->update(['status' => 'disabled']);
        self::assertSame([], $items());
    }

    public function test_banner_design_device_overrides_media_and_product_objects_are_live_and_batched(): void
    {
        $id = $this->banner('Kraft <i>collection</i><script>bad()</script>', '2026-10-01');
        DB::table('banners')->where('id', $id)->update(['color_scheme' => 'dark', 'title_weight' => 'bold', 'title_tag' => 'h2', 'button_style' => 'outline', 'tablet_custom' => true, 'phone_custom' => true]);
        DB::table('banner_layouts')->insert(['banner_id' => $id, 'device' => 'pc', ...array_replace(BannerRules::LAYOUT_DEFAULTS, ['halign' => 'left', 'title_size' => 46, 'button_enabled' => true, 'button_color' => '#f3d49b', 'mask_type' => 'gradient'])]);
        DB::table('banner_layouts')->insert(['banner_id' => $id, 'device' => 'tablet', 'title_size' => 32]);
        DB::table('banner_layouts')->insert(['banner_id' => $id, 'device' => 'phone', 'title_size' => 28, 'paddings' => '20px']);
        $image = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'test/banner.png', 'original_name' => 'test.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 1200, 'height' => 450]);
        Storage::disk('local')->put('test/banner.png', 'imagebytes');
        $video = DB::table('media_videos')->insertGetId(['disk' => 'local', 'storage_key' => 'test/banner.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 10]);
        Storage::disk('local')->put('test/banner.mp4', 'videobytes');
        DB::table('banner_contents')->where('banner_id', $id)->update(['background_image_media_id' => $image->id, 'background_video_id' => $video, 'button_text' => 'Shop collection']);
        DB::table('banner_contents')->insert(['banner_id' => $id, 'device' => 'phone', 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'title' => 'Mobile title']);
        $layout = $this->layout();
        $layout['placements'] = [$this->placement($layout['grids'][0]['id'], 'banners', settings: ['source' => 'latest', 'limit' => 8])];
        $this->save($layout);
        $design = fn ($locale = 'en') => $this->rendered($locale)['regions']['content'][0]['blocks'][0]['content']['items'][0]['banner'];
        $banner = $design();
        self::assertSame('dark', $banner['settings']['color_scheme']);
        self::assertSame('h2', $banner['settings']['title_tag']);
        self::assertArrayNotHasKey('created_by', $banner['settings']);
        self::assertArrayNotHasKey('status', $banner['settings']);
        self::assertSame(46, $banner['devices']['pc']['layout']['title_size']);
        self::assertSame(32, $banner['devices']['tablet']['layout']['title_size']);
        self::assertSame('left', $banner['devices']['tablet']['layout']['halign']);
        self::assertSame('gradient', $banner['devices']['phone']['layout']['mask_type']);
        self::assertSame('Mobile title', $banner['devices']['phone']['content']['title']);
        self::assertSame('Kraft <i>collection</i>', $banner['devices']['tablet']['content']['title']);
        self::assertSame('Shop collection', $design('el')['devices']['phone']['content']['button_text']);
        $url = '/storefront/media/banner/'.$id.'/'.$image->id;
        self::assertSame($url, $banner['devices']['phone']['content']['background_image']);
        $this->get($url)->assertOk();
        $videoUrl = '/storefront/media/banner-video/'.$id.'/'.$video;
        self::assertSame($videoUrl, $banner['devices']['pc']['content']['background_video']);
        $this->get($videoUrl)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get('/storefront/media/banner-video/999999/'.$video)->assertNotFound();
        $p = $this->product();
        $hidden = $this->product();
        $hidden->update(['status' => 'hidden']);
        DB::table('banner_products')->insert([['banner_id' => $id, 'product_id' => $hidden->id, 'position' => 1], ['banner_id' => $id, 'product_id' => $p->id, 'position' => 2]]);
        DB::table('banners')->where('id', $id)->update(['object_type' => 'products']);
        self::assertSame([(string) $p->id], array_column($design()['products'], 'id'));
        DB::table('banners')->where('id', $id)->update(['object_type' => 'image']);
        DB::enableQueryLog();
        $this->rendered();
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 7; $i++) {
            $this->banner('Offer '.$i, '2026-10-02');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->rendered();
        self::assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
        Storage::disk('local')->delete('test/banner.png');
        DB::table('banners')->where('id', $id)->update(['created_on' => '2026-10-03']);
        self::assertNull($design()['devices']['pc']['content']['background_image']);
        DB::table('banners')->where('id', $id)->update(['status' => 'disabled']);
        $this->get($url)->assertNotFound();
        $this->get($videoUrl)->assertNotFound();
    }

    public function test_duplicate_configurations_are_independent_and_unknown_records_fail_closed(): void
    {
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id']);
        $copy = $p;
        $copy['id'] = (string) Str::uuid();
        $copy['block']['id'] = (string) Str::uuid();
        $copy['position'] = 2;
        $copy['block']['translations']['en']['title'] = 'Independent';
        $layout['placements'] = [$p, $copy];
        $this->save($layout);
        $layout = $this->layout();
        $layout['placements'][0]['block']['translations']['en']['title'] = 'Changed';
        $this->save($layout);
        self::assertSame(['Changed', 'Independent'], array_column($this->rendered()['regions']['content'][0]['blocks'], 'title'));
        DB::table('storefront_blocks')->where('id', $p['block']['id'])->update(['type' => 'removed_extension']);
        Cache::flush();
        self::assertSame(['Independent'], array_column($this->rendered()['regions']['content'][0]['blocks'], 'title'));
        DB::table('storefront_grids')->where('id', $p['grid_id'])->delete();
        Cache::flush();
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
    }

    public function test_settings_saved_before_a_type_changed_never_hide_a_block(): void
    {
        $this->actingAs($this->staff(super: true));
        $p = $this->product();
        // A key the type no longer declares is dropped; invalid values entered by staff are still refused.
        $valid = app(StorefrontRegistry::class)->type('products')->validate(['source' => 'newest', 'limit' => 8, 'removed_setting' => true]);
        self::assertArrayNotHasKey('removed_setting', $valid);
        $layout = $this->layout();
        $block = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'manual', 'limit' => 8]);
        $block['block']['selections'] = ['items' => [(string) $p->id]];
        $block['block']['settings']['removed_setting'] = false;
        $layout['placements'] = [$block];
        // Saving a layout whose blocks still carry an old setting works, and cleans it up.
        $this->save($layout);
        self::assertArrayNotHasKey('removed_setting', json_decode(DB::table('storefront_blocks')->where('id', $block['block']['id'])->value('settings'), true));

        // Stored settings from an older version (an unknown key, a value the type no longer accepts): the block still shows,
        // the rejected value falling back to its default.
        DB::table('storefront_blocks')->where('id', $block['block']['id'])->update(['settings' => json_encode(['source' => 'manual', 'limit' => 8, 'per_row' => 99, 'navigation' => 'retired', 'removed_setting' => true])]);
        DB::table('storefront_layouts')->where('page_type', 'homepage')->increment('lock_version');
        $content = $this->rendered()['regions']['content'][0]['blocks'][0]['content'];
        self::assertSame((string) $p->id, $content['items'][0]['id']);
        self::assertSame([5, 'inside'], [$content['display']['per_row'], $content['display']['navigation']]);
    }

    public function test_bestsellers_count_only_paid_non_void_orders(): void
    {
        app(PaymentSettings::class)->ensure();
        $p = $this->product();
        $order = app(OrderAdministration::class)->create(null, $this->orderInput((string) $p->id));
        $layout = $this->layout();
        $block = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'bestsellers', 'limit' => 8]);
        $layout['placements'] = [$block];
        $this->save($layout);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => '100', 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame((string) $p->id, $this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'][0]['id']);
        DB::table('orders')->where('id', $order)->update(['status_id' => $this->orderStatus('cancelled')]);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
    }

    public function test_bestseller_rules_use_paid_date_thresholds_and_category_subtrees_without_changing_reports(): void
    {
        app(PaymentSettings::class)->ensure();
        $root = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $child = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $root->id]);
        $product = $this->product($child);
        $order = app(OrderAdministration::class)->create(null, $this->orderInput((string) $product->id, ['items' => [['product_id' => (string) $product->id, 'quantity' => 3]]]));
        DB::table('orders')->where('id', $order)->update(['created_at' => now()->subDays(200)]);
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => DB::table('orders')->where('id', $order)->value('total'), 'currency_code' => 'EUR', 'completed_at' => now()->subDays(30), 'created_at' => now(), 'updated_at' => now()]);
        $layout = $this->layout();
        $layout['placements'] = [$this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'bestsellers', 'limit' => 8])];
        $this->save($layout);
        $ids = fn () => array_column($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'] ?? [], 'id');
        $configure = function (array $fields): void {
            $settings = app(GeneralSettings::class);
            $settings->save((string) $this->actor->id, ['version' => $settings->all()['version'], 'out_of_stock_action' => 'N', ...$fields]);
        };
        self::assertSame([(string) $product->id], $ids());
        $configure(['bestsellers_days' => 30, 'bestsellers_min_units' => 3]);
        self::assertSame([(string) $product->id], $ids()); // Inclusive rolling boundary, payment date rather than placement date.
        $this->travel(1)->seconds();
        self::assertSame([], $ids());
        $configure(['bestsellers_days' => 90, 'bestsellers_min_units' => 4]);
        self::assertSame([], $ids());
        $configure(['bestsellers_min_units' => 3, 'bestsellers_min_orders' => 2]);
        self::assertSame([], $ids()); // Three units in one order still count as one order.
        $configure(['bestsellers_min_orders' => 1, 'bestsellers_excluded_categories' => [(string) $root->id]]);
        self::assertSame([], $ids());
        self::assertSame('3', (string) app(ReportOrders::class)->productUnitTotals()->first()->units);
        $configure(['bestsellers_excluded_categories' => []]);
        self::assertSame([(string) $product->id], $ids());
    }

    public function test_automatic_product_blocks_can_be_limited_to_categories_and_newest_uses_the_days_setting(): void
    {
        app(PaymentSettings::class)->ensure();
        $root = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        // Products live in leaf categories: the electronics tree holds phones and laptops, the other one stands alone.
        $phones = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $root->id]);
        $laptops = CategoryRecord::create(['position' => 2, 'status' => 'active', 'parent_id' => $root->id]);
        $other = CategoryRecord::create(['position' => 2, 'status' => 'active']);
        $inPhones = $this->product($phones);
        $inRoot = $this->product($laptops);
        $inOther = $this->product($other);
        foreach ([[$inPhones, 1], [$inRoot, 3], [$inOther, 5]] as [$product, $quantity]) {
            $order = app(OrderAdministration::class)->create(null, $this->orderInput((string) $product->id, ['items' => [['product_id' => (string) $product->id, 'quantity' => $quantity]]]));
            DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => DB::table('orders')->where('id', $order)->value('total'), 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('products')->where('id', $inRoot->id)->update(['created_at' => now()->subDays(100)]);
        DB::table('products')->where('id', $inOther->id)->update(['created_at' => now()->subDays(10)]);
        $ids = function (string $source, array $categories = []): array {
            $layout = $this->layout();
            $block = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => $source, 'limit' => 8]);
            $block['block']['selections'] = $categories === [] ? [] : ['filter_categories' => array_map(fn ($c) => (string) $c->id, $categories)];
            $layout['placements'] = [$block];
            $this->save($layout);

            return array_column($this->rendered()['regions']['content'][0]['blocks'][0]['content']['items'] ?? [], 'id');
        };
        $expected = fn (array $products) => array_map(fn ($p) => (string) $p->id, $products);

        // Best sellers rank all-time units sold; a category stands for its subcategories too; none means every category.
        self::assertSame($expected([$inOther, $inRoot, $inPhones]), $ids('bestsellers'));
        self::assertSame($expected([$inRoot, $inPhones]), $ids('bestsellers', [$root]));
        self::assertSame($expected([$inPhones]), $ids('bestsellers', [$phones]));
        self::assertSame($expected([$inOther, $inPhones]), $ids('bestsellers', [$phones, $other]));

        // Newest keeps products created within the configured number of days (default 90).
        self::assertSame(90, app(GeneralSettings::class)->all()['new_products_days']);
        self::assertSame($expected([$inPhones, $inOther]), $ids('newest'));
        self::assertSame($expected([$inPhones]), $ids('newest', [$root]));
        DB::table('general_settings')->where('id', 1)->update(['new_products_days' => 120]);
        app()->forgetInstance(GeneralSettings::class);
        Cache::flush();
        self::assertSame($expected([$inPhones, $inOther, $inRoot]), $ids('newest'));
        self::assertSame($expected([$inPhones, $inRoot]), $ids('newest', [$root]));
    }

    public function test_recently_viewed_product_blocks_filter_guest_history_before_applying_limits(): void
    {
        $root = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $child = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $root->id]);
        $other = CategoryRecord::create(['position' => 2, 'status' => 'active']);
        $first = $this->product($child);
        $second = $this->product($child);
        $outside = $this->product($other);
        $hidden = $this->product($child);
        $hidden->forceFill(['status' => 'disabled'])->save();
        $restricted = $this->product($child);
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_customer_groups')->insert(['product_id' => $restricted->id, 'group_id' => $group]);
        // Recently viewed has no new-product age restriction and follows viewing, not creation, order.
        $first->forceFill(['created_at' => now()->subDays(200)])->save();
        $history = [];
        foreach ([$hidden, $restricted, $outside, $first, $second] as $n => $product) {
            $history[$product->id] = now()->subMinutes($n)->format('Y-m-d H:i:s.uP');
        }
        $ids = function (array $categories = [], string $template = 'product_carousel', array $entries = []) use ($history): array {
            $this->actingAs($this->actor);
            $layout = $this->layout();
            $block = $this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'recently_viewed', 'limit' => 2]);
            $block['block']['template'] = $template;
            $block['block']['selections'] = ['filter_categories' => $categories];
            $layout['placements'] = [$block];
            $this->save($layout);
            Auth::logout();
            $this->flushSession();
            $items = [];
            $this->withSession([RecentlyViewedProducts::SESSION => $entries ?: $history])->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$items): void {
                $regions = $page->toArray()['props']['storefront']['regions'];
                $items = $regions['content'][0]['blocks'][0]['content']['items'] ?? [];
            });

            return array_column($items, 'id');
        };
        self::assertSame([(string) $outside->id, (string) $first->id], $ids());
        self::assertSame([(string) $first->id, (string) $second->id], $ids([(string) $root->id]));
        self::assertSame([(string) $first->id], $ids([(string) $child->id], 'product_single'));
        self::assertSame([], $ids([(string) $root->id], entries: [$outside->id => now()->format('Y-m-d H:i:s.uP')]));
    }

    public function test_recently_viewed_blocks_use_only_the_signed_in_visitors_persisted_history(): void
    {
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $first = $this->product($category);
        $second = $this->product($category);
        $guestOnly = $this->product($category);
        $layout = $this->layout();
        $layout['placements'] = [$this->placement($layout['grids'][0]['id'], 'products', settings: ['source' => 'recently_viewed', 'limit' => 12])];
        $this->save($layout);
        DB::table('recently_viewed_products')->insert([
            ['user_id' => $this->actor->id, 'product_id' => $first->id, 'viewed_at' => now()],
            ['user_id' => $this->actor->id, 'product_id' => $second->id, 'viewed_at' => now()->subMinute()],
        ]);
        $this->withSession([RecentlyViewedProducts::SESSION => [$guestOnly->id => now()->format('Y-m-d H:i:s.uP')]])->get('/')->assertOk()->assertInertia(function (Assert $page) use ($first, $second): void {
            self::assertSame([(string) $first->id, (string) $second->id], array_column($page->toArray()['props']['storefront']['regions']['content'][0]['blocks'][0]['content']['items'], 'id'));
        });
        $other = $this->staff();
        $this->actingAs($other)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('storefront.regions.content'));
    }

    public function test_public_media_checks_owner_eligibility_and_missing_files(): void
    {
        $p = $this->product();
        $media = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'test/product.png', 'original_name' => 'test.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 1, 'height' => 1]);
        Storage::disk('local')->put('test/product.png', 'imagebytes');
        DB::table('product_images')->insert(['product_id' => $p->id, 'media_file_id' => $media->id, 'position' => 1, 'is_primary' => true]);
        $url = '/storefront/media/product/'.$p->id.'/'.$media->id;
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storefront/media/product/999999/'.$media->id)->assertNotFound();
        $p->update(['status' => 'hidden']);
        $this->get($url)->assertNotFound();
        $p->update(['status' => 'active']);
        Storage::disk('local')->delete('test/product.png');
        $this->get($url)->assertNotFound();
    }

    public function test_restricted_products_categories_and_banners_do_not_leak_to_guests(): void
    {
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        $product = $this->product();
        DB::table('product_customer_groups')->insert(['product_id' => $product->id, 'group_id' => $group]);
        $layout = $this->layout();
        $p = $this->placement($layout['grids'][0]['id'], 'products');
        $layout['placements'] = [$p];
        $this->save($layout);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        DB::table('product_customer_groups')->delete();
        DB::table('category_customer_groups')->insert(['category_id' => $product->category_id, 'group_id' => $group]);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
        $banner = $this->banner('Private offer', '2026-10-01');
        DB::table('banner_customer_groups')->insert(['banner_id' => $banner, 'group_id' => $group]);
        $layout = $this->layout();
        $layout['placements'] = [$this->placement($layout['grids'][0]['id'], 'banners')];
        $this->save($layout);
        self::assertArrayNotHasKey('content', $this->rendered()['regions']);
    }

    public function test_permission_enforcement_and_unknown_pages(): void
    {
        $this->actingAs($this->staff(['admin.access', 'layouts.view']));
        $this->get('/admin/layouts')->assertOk();
        $this->put('/admin/layouts/homepage', $this->layout())->assertForbidden();
        $this->get('/admin/layouts/delivery')->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->staff());
        $this->get('/admin/layouts')->assertForbidden();
    }

    public function test_header_styles_share_language_and_icon_order_settings(): void
    {
        $definition = app(StorefrontRegistry::class)->type('identity')->definition();
        self::assertSame('classic', $definition['defaults']['header_style']);
        // The picker defaults on for compact/fashion; classic keeps its existing presentation.
        self::assertTrue($definition['defaults']['show_language_switcher']);
        self::assertTrue($definition['defaults']['show_mobile_account_links']);
        self::assertSame(['language', 'search', 'account', 'call', 'favorites', 'cart'], $definition['defaults']['icon_order']);
        self::assertSame(['show_logo', 'show_search', 'search_suggestions', 'show_mini_cart', 'background_color', 'text_color', 'icon_color', 'hidden_paths'], array_values(array_column(array_filter($definition['fields'], fn (array $field): bool => ($field['tab'] ?? null) === 'identity_options'), 'key')));
        $shared = $this->layout('default');
        $identity = collect($shared['placements'])->firstWhere('block.type', 'identity');
        // A header saved before the style existed is the classic one, with exactly the data it had.
        $classic = $this->rendered()['regions']['header'][0]['blocks'][0]['content'];
        self::assertSame('classic', $classic['display']['header_style']);
        self::assertArrayNotHasKey('drawer', $classic);
        self::assertSame(['en', 'el'], array_column($classic['languages'], 'code'));
        foreach (['show_language_switcher', 'icon_order'] as $key) {
            self::assertArrayNotHasKey('when', collect($definition['fields'])->firstWhere('key', $key));
        }

        $classicLayout = $this->layout('default');
        foreach ($classicLayout['placements'] as &$p) {
            if ($p['block']['type'] === 'identity') {
                $p['block']['settings'] = [...$p['block']['settings'], 'show_language_switcher' => false, 'icon_order' => ['cart', 'favorites', 'call', 'account', 'search', 'language']];
            }
        }
        unset($p);
        $this->save($classicLayout);
        $classic = $this->rendered()['regions']['header'][0]['blocks'][0]['content'];
        self::assertSame([], $classic['languages']);
        self::assertSame(['cart', 'favorites', 'call', 'account', 'search', 'language'], $classic['display']['icon_order']);
        $shared = $this->layout('default');
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $menu = DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => 'header', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_menu_translations')->insert(['menu_id' => $menu, 'locale_id' => $en, 'name' => 'Drawer']);
        $tech = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'status' => 'active', 'position' => 1, 'kind' => 'url', 'url' => '/tech', 'created_at' => now(), 'updated_at' => now()]);
        $phones = DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'parent_id' => $tech, 'status' => 'active', 'position' => 1, 'kind' => 'url', 'url' => '/tech/phones', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_menu_item_translations')->insert([['item_id' => $tech, 'locale_id' => $en, 'name' => 'Technology'], ['item_id' => $phones, 'locale_id' => $en, 'name' => 'Phones']]);
        $compact = $shared;
        $compact['placements'] = array_map(function (array $p) use ($identity, $menu): array {
            if ($p['id'] === $identity['id']) {
                $p['block']['settings'] = [...$p['block']['settings'], 'header_style' => 'compact', 'show_language_switcher' => true, 'icon_order' => ['cart', 'account', 'favorites', 'language', 'call']];
                $p['block']['selections'] = ['drawer_menu' => [(string) $menu]];
            }

            return $p;
        }, $shared['placements']);
        $this->save($compact);
        $content = $this->rendered()['regions']['header'][0]['blocks'][0]['content'];
        self::assertSame('compact', $content['display']['header_style']);
        self::assertSame(['Technology', '/tech', 'Phones'], [$content['drawer'][0]['name'], $content['drawer'][0]['url'], $content['drawer'][0]['children'][0]['name']]);
        self::assertSame(['el', 'en'], collect($content['languages'])->pluck('code')->sort()->values()->all());
        // The same block settings drive the icons in both styles.
        self::assertSame([true, true], [$content['display']['show_cart'], $content['display']['show_account']]);
        self::assertSame(['cart', 'account', 'favorites', 'language', 'call', 'search'], $content['display']['icon_order']);
        $fashion = $this->layout('default');
        foreach ($fashion['placements'] as &$p) {
            if ($p['block']['type'] === 'identity') {
                $p['block']['settings'] = [...$p['block']['settings'], 'header_style' => 'fashion', 'show_mobile_account_links' => false, 'show_logo' => false, 'show_account' => false, 'show_favorites' => false, 'show_cart' => false, 'show_search' => false, 'show_language_switcher' => false];
            }
        }
        unset($p);
        $this->save($fashion);
        $content = $this->rendered()['regions']['header'][0]['blocks'][0]['content'];
        self::assertSame('fashion', $content['display']['header_style']);
        self::assertSame('Technology', $content['drawer'][0]['name']);
        self::assertSame([], $content['languages']);
        foreach (['show_mobile_account_links', 'show_logo', 'show_search', 'show_account', 'show_favorites', 'show_cart'] as $key) {
            self::assertFalse($content['display'][$key]);
        }
        self::assertSame(['menu'], app(StorefrontRegistry::class)->type('menu')->definition()['templates']);
        // An order must list every icon once.
        foreach ([['cart', 'cart', 'account', 'favorites', 'language'], ['cart', 'account'], ['cart', 'account', 'favorites', 'language', 'call', 'extra'], 'cart'] as $order) {
            $wrong = $this->layout('default');
            $wrong['placements'] = array_map(function (array $p) use ($identity, $order): array {
                if ($p['id'] === $identity['id']) {
                    $p['block']['settings']['icon_order'] = $order;
                }

                return $p;
            }, $wrong['placements']);
            $this->put('/admin/layouts/default', $wrong)->assertSessionHasErrors();
        }

        // An unknown style is refused.
        $bad = $compact;
        $bad['version'] = $this->layout('default')['version'];
        $bad['placements'] = array_map(function (array $p) use ($identity): array {
            if ($p['id'] === $identity['id']) {
                $p['block']['settings']['header_style'] = 'mega';
            }

            return $p;
        }, $this->layout('default')['placements']);
        $this->put('/admin/layouts/default', $bad)->assertSessionHasErrors();
    }

    public function test_saved_fashion_menu_moves_to_identity_without_losing_its_selection(): void
    {
        $shared = $this->layout('default');
        $identity = collect($shared['placements'])->firstWhere('block.type', 'identity');
        $menu = collect($shared['placements'])->firstWhere('block.type', 'menu');
        DB::table('storefront_blocks')->where('id', $menu['block']['id'])->update(['template' => 'menu_fashion', 'settings' => json_encode(['show_language_switcher' => true])]);
        DB::table('storefront_placements')->where('id', $menu['id'])->update(['enabled' => true]);
        $menuId = DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => 'header', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('storefront_block_selections')->insert(['block_id' => $menu['block']['id'], 'field' => 'main', 'position' => 1, 'menu_id' => $menuId]);
        $migration = require database_path('migrations/2026_11_15_000400_move_fashion_navigation_to_identity.php');
        $migration->up();
        $moved = $this->layout('default');
        $header = collect($moved['placements'])->firstWhere('block.type', 'identity');
        $navigation = collect($moved['placements'])->firstWhere('block.type', 'menu');
        self::assertSame($identity['block']['id'], $header['block']['id']);
        self::assertSame('fashion', $header['block']['settings']['header_style']);
        self::assertTrue($header['block']['settings']['show_language_switcher']);
        self::assertSame([(string) $menuId], $header['block']['selections']['drawer_menu']);
        self::assertSame([(string) $menuId], $navigation['block']['selections']['main']);
        self::assertSame('menu', $navigation['block']['template']);
        self::assertFalse($navigation['enabled']);
        self::assertGreaterThan($shared['version'], $moved['version']);
        $migration->up();
        self::assertSame($moved, $this->layout('default'));
    }

    public function test_fashion_mobile_links_are_localized_editable_and_validate_urls(): void
    {
        DB::table('locales')->where('code', 'fr')->update(['is_active' => true]);
        $shared = $this->layout('default');
        foreach ($shared['placements'] as &$p) {
            if ($p['block']['type'] !== 'identity') {
                continue;
            }
            self::assertSame(['/account', '/cart', '/wishlist'], array_column($p['block']['translations']['fr']['mobile_links']['items'], 'url'));
            $defaults = $p['block']['translations']['en']['mobile_links'];
            self::assertSame(['/account', '/cart', '/wishlist'], array_column($defaults['items'], 'url'));
            $p['block']['settings']['header_style'] = 'fashion';
            $p['block']['translations']['en']['mobile_links'] = ['title' => 'Help', 'items' => [
                ['label' => 'Size guide', 'url' => '/sizes', 'new_window' => false],
                ['label' => 'Contact', 'url' => 'https://example.com/contact', 'new_window' => true],
            ]];
            $p['block']['translations']['el']['mobile_links'] = ['title' => 'Βοήθεια', 'items' => [
                ['label' => 'Επικοινωνία', 'url' => '/contact', 'new_window' => false],
            ]];
        }
        unset($p);
        $this->save($shared);
        $read = $this->layout('default');
        $index = array_search('identity', array_column(array_column($read['placements'], 'block'), 'type'), true);
        $en = $read['placements'][$index]['block']['translations']['en']['mobile_links'];
        self::assertSame(['Size guide', 'Contact'], array_column($en['items'], 'label'));
        self::assertSame($en, $this->rendered()['regions']['header'][0]['blocks'][0]['content']['mobile_links']);
        self::assertSame('Βοήθεια', $this->rendered('el')['regions']['header'][0]['blocks'][0]['content']['mobile_links']['title']);
        foreach (['javascript:alert(1)', '//evil.test', '/bad path'] as $url) {
            $bad = $this->layout('default');
            $bad['placements'][$index]['block']['translations']['en']['mobile_links']['items'][0]['url'] = $url;
            $this->put('/admin/layouts/default', $bad)->assertSessionHasErrors('placements.'.$index.'.block.translations.en.mobile_links.items.0.url');
        }
        $empty = $this->layout('default');
        $empty['placements'][$index]['block']['translations']['en']['mobile_links']['items'] = [];
        $this->save($empty);
        self::assertSame([], $this->rendered()['regions']['header'][0]['blocks'][0]['content']['mobile_links']['items']);
    }

    public function test_mobile_arrow_visibility_is_configurable_for_every_carousel_block(): void
    {
        $registry = app(StorefrontRegistry::class);
        foreach (['banners', 'products', 'categories', 'blog_posts', 'coupons', 'offers', 'visual_menu', 'brands', 'brand_products'] as $name) {
            $type = $registry->type($name);
            $definition = $type->definition();
            $field = collect($definition['fields'])->firstWhere('key', 'hide_arrows_mobile');
            self::assertSame('boolean', $field['kind'], $name);
            self::assertTrue($field['carousel_only'], $name);
            self::assertFalse($type->validate([])['hide_arrows_mobile'], $name);
            self::assertFalse($type->validate(['hide_arrows_mobile' => '0'])['hide_arrows_mobile'], $name);
            $settings = $type->validate(['hide_arrows_mobile' => true]);
            $out = app(BlockContent::class)->display(['b' => ['settings' => $settings, 'translations' => []]], ['b' => ['items' => []]], [], 'en');
            self::assertTrue($out['b']['display']['hide_arrows_mobile'], $name);
        }
        $layout = $this->layout();
        $grid = $this->grid();
        $placement = $this->placement($grid['id'], 'products');
        $placement['block']['settings']['hide_arrows_mobile'] = true;
        $placement['block']['selections']['items'] = [(string) $this->product()->id];
        $placement['block']['settings']['source'] = 'manual';
        $layout['grids'] = [$grid];
        $layout['placements'] = [$placement];
        $this->save($layout);
        self::assertTrue($this->layout()['placements'][0]['block']['settings']['hide_arrows_mobile']);
        self::assertTrue($this->rendered()['regions']['content'][0]['blocks'][0]['content']['display']['hide_arrows_mobile']);
        $layout = $this->layout();
        $layout['placements'][0]['block']['settings']['hide_arrows_mobile'] = 'invalid';
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
    }
}
