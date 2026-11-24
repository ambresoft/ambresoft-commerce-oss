<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class LayoutSettingsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function localeId(string $code): int
    {
        return (int) DB::table('locales')->where('code', $code)->value('id');
    }

    /** @param array<string, mixed> $texts */
    private function payload(array $texts = [], array $extra = []): array
    {
        $blank = ['name' => '', 'page_title' => '', 'meta_description' => '', 'meta_keywords' => ''];

        return ['version' => 1, 'translations' => ['en' => [...$blank, ...$texts]], 'custom_html' => '', 'page_id' => null, 'position' => 0, ...$extra];
    }

    private function staticPage(string $name = 'Delivery'): int
    {
        $id = (int) DB::table('pages')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        DB::table('page_translations')->insert(['page_id' => $id, 'locale_id' => $this->localeId('en'), 'name' => $name, 'slug' => Str::slug($name)]);

        return $id;
    }

    private function createLayout(array $texts = ['name' => 'Delivery'], array $extra = []): string
    {
        $this->post('/admin/layouts', $this->payload($texts, $extra))->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('storefront_layouts')->where('page_type', 'like', 'custom.%')->latest('id')->value('page_type');
    }

    public function test_every_layout_stores_its_page_settings_per_language_and_the_storefront_uses_them(): void
    {
        $this->put('/admin/layouts/default/settings', $this->payload(['page_title' => 'Shop name', 'meta_description' => 'Everything we sell', 'meta_keywords' => 'shop, store'], ['custom_html' => '<script>window.dataLayer = [];</script>']))->assertSessionHasNoErrors()->assertRedirect('/admin/layouts/default');
        $this->get('/admin/layouts/default')->assertInertia(fn (Assert $p) => $p->where('pageSettings.version', 2)->where('pageSettings.translations.en.page_title', 'Shop name')->where('pageSettings.custom', false)->where('tabs.0.key', 'default'));
        // The homepage has no texts of its own, so the Default layout's apply; the head HTML is the layout's own.
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('seo.title', 'Shop name'))
            ->assertSee('<title>Shop name</title>', false)->assertSee('<meta name="description" content="Everything we sell" data-inertia="seo-description">', false)->assertSee('<meta name="keywords" content="shop, store" data-inertia="seo-keywords">', false)
            ->assertDontSee('window.dataLayer', false);
        $this->put('/admin/layouts/homepage/settings', $this->payload(['page_title' => 'Welcome'], ['custom_html' => '<meta name="verify" content="abc">']))->assertSessionHasNoErrors();
        $this->get('/')->assertSee('<title>Welcome</title>', false)->assertSee('<meta name="description" content="Everything we sell" data-inertia="seo-description">', false)->assertSee('<meta name="verify" content="abc">', false);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'storefront_layout_translation']);
    }

    public function test_pages_are_indexable_by_default_and_a_layout_can_turn_indexing_off(): void
    {
        $this->get('/')->assertOk()->assertDontSee('name="robots"', false)->assertHeaderMissing('X-Robots-Tag');
        $this->put('/admin/layouts/homepage/settings', $this->payload(['page_title' => 'Hidden'], ['indexable' => false]))->assertSessionHasNoErrors();
        $this->get('/')->assertSee('<meta name="robots" content="noindex, nofollow" data-inertia="seo-robots">', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        self::assertFalse(app(LayoutSettings::class)->seo('homepage', 'en')['indexable']);
        self::assertTrue(app(LayoutSettings::class)->seo('homepage', 'en', ['indexable' => true])['indexable']);
        $this->put('/admin/layouts/homepage/settings', $this->payload([], ['version' => 2]))->assertSessionHasNoErrors();
        $this->get('/')->assertDontSee('name="robots"', false);
        $key = $this->createLayout(['name' => 'Private'], ['indexable' => false]);
        $this->get('/admin/layouts/'.$key)->assertInertia(fn (Assert $p) => $p->where('pageSettings.indexable', false));
    }

    public function test_a_page_with_its_own_seo_overrides_the_layout_settings(): void
    {
        $this->put('/admin/layouts/homepage/settings', $this->payload(['page_title' => 'Layout title', 'meta_description' => 'Layout description', 'meta_keywords' => 'layout']))->assertSessionHasNoErrors();
        $seo = app(LayoutSettings::class)->seo('homepage', 'en', ['title' => 'Page title', 'description' => '  ', 'keywords' => null]);
        self::assertSame(['Page title', 'Layout description', 'layout'], [$seo['title'], $seo['description'], $seo['keywords']]);
        self::assertSame('', app(LayoutSettings::class)->seo('custom.unknown', 'en')['custom_html']);
    }

    public function test_the_copy_choices_spread_keywords_and_head_html_to_the_other_layouts(): void
    {
        $custom = $this->createLayout();
        $this->put('/admin/layouts/default/settings', $this->payload(['meta_keywords' => 'one, two'], ['custom_html' => '<script>a()</script>', 'copy_meta_keywords' => true, 'copy_custom_html' => true]))->assertSessionHasNoErrors();
        foreach (['homepage', $custom] as $page) {
            $this->get('/admin/layouts/'.$page)->assertInertia(fn (Assert $p) => $p->where('pageSettings.translations.en.meta_keywords', 'one, two')->where('pageSettings.custom_html', '<script>a()</script>')->where('pageSettings.version', 2));
        }
        // Without the choices nothing is copied.
        $this->put('/admin/layouts/default/settings', $this->payload(['meta_keywords' => 'three'], ['version' => 2]))->assertSessionHasNoErrors();
        $this->get('/admin/layouts/homepage')->assertInertia(fn (Assert $p) => $p->where('pageSettings.translations.en.meta_keywords', 'one, two'));
    }

    public function test_layouts_are_added_linked_to_a_static_page_and_arranged_like_the_homepage(): void
    {
        $page = $this->staticPage();
        $key = $this->createLayout(['name' => 'Delivery layout'], ['page_id' => $page, 'position' => 5]);
        self::assertSame($page, (int) DB::table('storefront_layouts')->where('page_type', $key)->value('page_id'));
        $this->get('/admin/layouts/'.$key)->assertOk()->assertInertia(fn (Assert $p) => $p->where('layout.page_type', $key)->where('pageDefinition.custom', true)->where('pageDefinition.regions', ['content'])->where('pageKind', 'homepage')
            ->where('tabs', fn ($tabs) => collect($tabs)->firstWhere('key', $key) === ['key' => $key, 'label' => 'Delivery layout', 'translate' => false, 'page_id' => (string) $page, 'custom' => true])->where('pageOptions.0.layout', $key)->has('sharedLayout.placements'));
        // Blocks follow the homepage rules: content blocks yes, shared-area blocks no.
        $layout = app(LayoutStore::class)->read($key);
        // Linking the page added its main content block in row 1; staff blocks go around it.
        $grid = ['id' => (string) Str::uuid(), 'parent_id' => null, 'region' => 'content', 'width' => 16, 'row' => 2, 'position' => 1];
        $html = app(StorefrontRegistry::class)->type('html')->definition();
        $block = fn (string $type, array $definition) => ['id' => (string) Str::uuid(), 'type' => $type, 'name' => 'Intro', 'template' => $definition['templates'][0], 'css_class' => '', 'settings' => $definition['defaults'], 'selections' => [], 'translations' => ['en' => ['title' => '', 'html' => '<p>Delivery information</p>']]];
        $placement = fn (array $b) => ['id' => (string) Str::uuid(), 'grid_id' => $grid['id'], 'position' => 1, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true, 'block' => $b];
        $this->put('/admin/layouts/'.$key, [...$layout, 'grids' => [...$layout['grids'], $grid], 'placements' => [...$layout['placements'], $placement($block('identity', app(StorefrontRegistry::class)->type('identity')->definition()))]])->assertSessionHasErrors();
        // The page's main content block cannot be dropped while the page is linked.
        $this->put('/admin/layouts/'.$key, [...$layout, 'grids' => [$grid], 'placements' => [$placement($block('html', $html))]])->assertSessionHasErrors();
        $this->put('/admin/layouts/'.$key, [...$layout, 'grids' => [...$layout['grids'], $grid], 'placements' => [...$layout['placements'], $placement($block('html', $html))]])->assertSessionHasNoErrors();
        $rendered = json_decode(json_encode(app(LayoutRenderer::class)->page($key, 'en', null)), true);
        self::assertSame('<p>Delivery information</p>', $rendered['regions']['content'][0]['blocks'][0]['content']['html']);
        self::assertArrayHasKey('header', $rendered['regions']);
    }

    public function test_layout_names_pages_and_head_html_are_validated(): void
    {
        $page = $this->staticPage();
        $this->post('/admin/layouts', $this->payload(['name' => '  ']))->assertSessionHasErrors('translations.en.name');
        $this->createLayout(['name' => 'Delivery'], ['page_id' => $page]);
        $this->post('/admin/layouts', $this->payload(['name' => 'delivery']))->assertSessionHasErrors('translations.en.name');
        $this->post('/admin/layouts', $this->payload(['name' => 'Other'], ['page_id' => $page]))->assertSessionHasErrors('page_id');
        $this->post('/admin/layouts', $this->payload(['name' => 'Ghost'], ['page_id' => 99999]))->assertSessionHasErrors('page_id');
        $this->post('/admin/layouts', $this->payload(['name' => 'Bad'], ['custom_html' => '<script>x()</script></head><body>']))->assertSessionHasErrors('custom_html');
        $this->post('/admin/layouts', $this->payload(['name' => 'Long', 'page_title' => str_repeat('a', 201)]))->assertSessionHasErrors('translations.en.page_title');
        $this->post('/admin/layouts', ['translations' => ['xx' => ['name' => 'Unknown language']], 'custom_html' => '', 'version' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('storefront_layouts', count(app(StorefrontRegistry::class)->pages()) + 1);
    }

    public function test_settings_edits_are_versioned_and_built_in_layouts_cannot_be_moved_or_deleted(): void
    {
        $key = $this->createLayout(['name' => 'Delivery']);
        $this->put('/admin/layouts/'.$key.'/settings', $this->payload(['name' => 'Delivery', 'page_title' => 'A'], ['version' => 7]))->assertSessionHasErrors('version');
        $this->put('/admin/layouts/'.$key.'/settings', $this->payload(['name' => 'Delivery', 'page_title' => 'A'], ['position' => 3]))->assertSessionHasNoErrors();
        self::assertSame(3, (int) DB::table('storefront_layouts')->where('page_type', $key)->value('position'));
        // Built-in layouts ignore the position and link and are never deleted.
        $this->put('/admin/layouts/homepage/settings', $this->payload([], ['position' => 9, 'page_id' => $this->staticPage('Other')]))->assertSessionHasNoErrors();
        self::assertSame([0, null], [(int) DB::table('storefront_layouts')->where('page_type', 'homepage')->value('position'), DB::table('storefront_layouts')->where('page_type', 'homepage')->value('page_id')]);
        $this->delete('/admin/layouts/homepage', ['version' => 2])->assertNotFound();
        $this->delete('/admin/layouts/'.$key, ['version' => 1])->assertSessionHasErrors('version');
        $this->delete('/admin/layouts/'.$key, ['version' => 2])->assertSessionHasNoErrors()->assertRedirect('/admin/layouts/homepage');
        $this->assertDatabaseMissing('storefront_layouts', ['page_type' => $key]);
        $this->get('/admin/layouts/custom.missing')->assertNotFound();
    }

    public function test_deleting_the_linked_page_keeps_the_layout_unlinked(): void
    {
        $page = $this->staticPage();
        $key = $this->createLayout(['name' => 'Delivery'], ['page_id' => $page]);
        DB::table('pages')->where('id', $page)->delete();
        self::assertNull(DB::table('storefront_layouts')->where('page_type', $key)->value('page_id'));
    }

    public function test_only_staff_who_manage_layouts_can_change_settings(): void
    {
        $key = $this->createLayout();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'layouts.view']));
        $this->get('/admin/layouts/'.$key)->assertOk();
        $this->post('/admin/layouts', $this->payload(['name' => 'Nope']))->assertForbidden();
        $this->put('/admin/layouts/'.$key.'/settings', $this->payload(['name' => 'Delivery']))->assertForbidden();
        $this->delete('/admin/layouts/'.$key, ['version' => 1])->assertForbidden();
        $this->assertDatabaseHas('storefront_layouts', ['page_type' => $key]);
    }

    /** The main content blocks a layout holds. */
    private function mainBlocks(string $layout): int
    {
        return DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')->join('storefront_layouts as l', 'l.id', '=', 'p.layout_id')
            ->where('l.page_type', $layout)->where('b.type', 'main_content')->count();
    }

    public function test_a_linked_static_page_is_served_in_its_layout_through_the_main_content_block(): void
    {
        $page = $this->staticPage('Privacy');
        DB::table('page_translations')->where('page_id', $page)->update(['content' => '<p>We keep little.</p><script>x()</script>', 'seo_title' => 'Privacy at the shop']);
        // Not linked: not on the storefront.
        $this->get('/privacy')->assertNotFound();

        $layout = $this->createLayout(['name' => 'Privacy layout'], ['page_id' => $page]);
        self::assertSame(1, $this->mainBlocks($layout));
        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Storefront/Page')->where('seo.title', 'Privacy at the shop')
            ->where('storefront.regions.content.0.blocks.0.template', 'main_content')->where('storefront.regions.content.0.blocks.0.content.page.name', 'Privacy')
            ->where('storefront.regions.content.0.blocks.0.content.page.content', '<p>We keep little.</p>'));
        $this->get('/admin/pages/'.$page.'/edit')->assertInertia(fn (Assert $p) => $p->where('page.preview_url', '/privacy?preview=1'));

        // Disabled or out of its period: hidden from visitors, still open to staff previewing it (never indexed).
        DB::table('pages')->where('id', $page)->update(['status' => 'disabled']);
        auth()->logout();
        $this->get('/privacy')->assertNotFound();
        $this->get('/privacy?preview=1')->assertNotFound();
        $this->actingAs($this->actor)->get('/privacy?preview=1')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        DB::table('pages')->where('id', $page)->update(['status' => 'hidden', 'available_from' => now()->addDay()]);
        auth()->logout();
        $this->get('/privacy')->assertNotFound();
        DB::table('pages')->where('id', $page)->update(['available_from' => null]);
        $this->get('/privacy')->assertOk();
        // Limited to a customer group: guests do not see it.
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('page_customer_groups')->insert(['page_id' => $page, 'group_id' => $group]);
        $this->get('/privacy')->assertNotFound();

        // Unlinking removes the block (staff could not delete it themselves); linking again brings it back once.
        $this->actingAs($this->actor);
        $version = (int) DB::table('storefront_layouts')->where('page_type', $layout)->value('settings_version');
        $this->put('/admin/layouts/'.$layout.'/settings', $this->payload(['name' => 'Privacy layout'], ['version' => $version, 'page_id' => null]))->assertSessionHasNoErrors();
        self::assertSame(0, $this->mainBlocks($layout));
        $this->put('/admin/layouts/'.$layout.'/settings', $this->payload(['name' => 'Privacy layout'], ['version' => $version + 1, 'page_id' => $page]))->assertSessionHasNoErrors();
        $this->put('/admin/layouts/'.$layout.'/settings', $this->payload(['name' => 'Privacy layout'], ['version' => $version + 2, 'page_id' => $page]))->assertSessionHasNoErrors();
        self::assertSame(1, $this->mainBlocks($layout));
    }
}
