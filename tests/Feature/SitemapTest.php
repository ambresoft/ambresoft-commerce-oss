<?php

namespace Tests\Feature;

use Ambresoft\Sitemap\Domain\Service\SitemapXml;
use Ambresoft\Sitemap\Infrastructure\Jobs\GenerateDraft;
use Ambresoft\Sitemap\Infrastructure\Jobs\RefreshSection;
use Ambresoft\Sitemap\Infrastructure\Sitemap;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\AuthorizationServiceProvider;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Application\Contract\AddonStatus;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Application\Command\IndexableUrl;
use App\Storefront\Application\Contract\StorefrontUrls;
use App\Website\Infrastructure\RedirectMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\Fixtures\MutableSitemapUrls;
use Tests\TestCase;

final class SitemapTest extends TestCase
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
        app(AddonManager::class)->installBundled((string) $this->actor->id, 'ambresoft/sitemap');
        app(AddonManager::class)->enable((string) $this->actor->id, 'ambresoft/sitemap');
        app('router')->getRoutes()->refreshNameLookups();
        // As on the following HTTP request, finalize gates after the newly enabled provider contributed its abilities.
        (new AuthorizationServiceProvider(app()))->boot(app(AccessChecker::class));
    }

    public function test_draft_is_separate_from_publication(): void
    {
        config(['app.url' => 'https://store.example']);
        $service = app(Sitemap::class);
        $service->refreshDraft((string) $this->actor->id, 1);
        self::assertSame(1, $service->overview()['sections']['homepage']['draft']);
        $this->get('/sitemap.xml')->assertNotFound();
        $service->publish((string) $this->actor->id, 2);
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('https://store.example/', false);
    }

    private function service(): Sitemap
    {
        return app(Sitemap::class);
    }

    private function actor(): string
    {
        return (string) $this->actor->id;
    }

    private function version(): int
    {
        return (int) DB::table('ambresoft_sitemap_state')->value('draft_version');
    }

    private function live(): ?string
    {
        return DB::table('ambresoft_sitemap_state')->value('published_version');
    }

    private function feed(): MutableSitemapUrls
    {
        $feed = new MutableSitemapUrls;
        $feed->entries['homepage'] = [new IndexableUrl('homepage', '1', 'https://store.example/', '2026-01-01T00:00:00Z')];
        app()->instance(StorefrontUrls::class, $feed);

        return $feed;
    }

    public function test_overrides_survive_refresh_missing_sources_and_restoration_without_changing_live_xml(): void
    {
        $feed = $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $first = $this->service()->publish($this->actor(), 2);
        $xml = $this->service()->file(null, 'sitemap.xml')['xml'];
        $row = DB::table('ambresoft_sitemap_draft_urls')->first();
        $this->service()->saveDraft($this->actor(), ['version' => $this->version(), 'entries' => [['id' => $row->id, 'include' => false, 'url' => 'https://store.example/']]]);
        $feed->entries['homepage'] = [];
        $this->service()->refreshDraft($this->actor(), $this->version());
        $missing = DB::table('ambresoft_sitemap_draft_urls')->first();
        self::assertFalse($missing->available);
        self::assertFalse($missing->inclusion_override);
        self::assertSame('https://store.example/', $missing->url_override);
        self::assertSame($xml, $this->service()->file(null, 'sitemap.xml')['xml']);
        $feed->entries['homepage'] = [new IndexableUrl('homepage', '1', 'https://store.example/', '2026-02-01T00:00:00Z')];
        $this->service()->refreshDraft($this->actor(), $this->version());
        self::assertSame($first, $this->live());
        $this->service()->publish($this->actor(), $this->version());
        self::assertStringNotContainsString('<loc>', $this->service()->file(null, 'sitemap.xml')['xml']);
    }

    public function test_refresh_adds_sources_detects_duplicates_and_rejects_unsafe_urls(): void
    {
        $feed = $this->feed();
        $feed->entries['pages'] = [
            new IndexableUrl('pages', '10', 'https://store.example/about', null),
            new IndexableUrl('pages', '11', 'https://store.example/about', null),
            new IndexableUrl('pages', '12', 'https://elsewhere.example/foreign', null),
            new IndexableUrl('pages', '13', 'https://store.example/cart', null),
        ];
        $this->service()->refreshDraft($this->actor(), 1);
        self::assertSame(5, DB::table('ambresoft_sitemap_draft_urls')->count());
        self::assertSame(1, DB::table('ambresoft_sitemap_draft_urls')->where('warning', 'duplicate')->count());
        self::assertSame(2, DB::table('ambresoft_sitemap_draft_urls')->where('warning', 'invalid')->count());
        $this->service()->publish($this->actor(), $this->version());
        $xml = $this->service()->file(null, 'sitemap.xml')['xml'];
        self::assertSame(2, substr_count($xml, '<loc>'));
        self::assertSame(1, substr_count($xml, '/about</loc>'));
    }

    public function test_invalid_override_and_stale_version_roll_back_the_entire_save(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $row = DB::table('ambresoft_sitemap_draft_urls')->first();
        foreach (['https://other.example/', 'https://store.example/cart', 'https://store.example/?sort=price', 'https://store.example/unknown'] as $url) {
            $this->put('/admin/sitemap/draft', ['version' => 2, 'entries' => [['id' => $row->id, 'include' => false, 'url' => $url]]])->assertSessionHasErrors('entries.0.url');
            self::assertNull(DB::table('ambresoft_sitemap_draft_urls')->value('inclusion_override'));
        }
        $this->put('/admin/sitemap/draft', ['version' => 1, 'entries' => [['id' => $row->id, 'include' => false, 'url' => null]]])->assertSessionHasErrors('version');
        self::assertSame(2, $this->version());
    }

    public function test_settings_save_is_versioned_and_never_changes_publication(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $first = $this->service()->publish($this->actor(), 2);
        $settings = array_map(fn ($section) => ['enabled' => false, 'frequency' => 'never'], $this->service()->overview()['sections']);
        $this->put('/admin/sitemap/settings', ['version' => 3, 'sections' => $settings])->assertSessionHasNoErrors();
        self::assertSame($first, $this->live());
        self::assertSame('never', DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->value('frequency'));
        $this->get('/admin/sitemap')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Addons/Sitemap')->has('navigation')->has('breadcrumbs', 2)->where('breadcrumbs.1.label', __('cms-sitemap::ui.title'))->where('sections.homepage.enabled', false)->where('sections.homepage.published', 1)->where('pending', true));
        $this->post('/admin/sitemap/publish', ['version' => 4])->assertSessionHasErrors('confirmed');
        self::assertSame($first, $this->live());
        $this->post('/admin/sitemap/publish', ['version' => 4, 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertStringNotContainsString('<loc>', $this->service()->file(null, 'sitemap.xml')['xml']);
        $settings['homepage']['frequency'] = 'minute';
        $this->put('/admin/sitemap/settings', ['version' => 5, 'sections' => $settings])->assertSessionHasErrors('sections.homepage.frequency');
    }

    public function test_permissions_are_separate_for_view_draft_management_and_publication(): void
    {
        $viewer = $this->staff(['admin.access', 'ambresoft.sitemap.view']);
        $this->actingAs($viewer)->get('/admin/sitemap')->assertOk();
        $this->put('/admin/sitemap/settings', ['version' => 1])->assertForbidden();
        $this->post('/admin/sitemap/generate', ['version' => 1])->assertForbidden();
        $this->put('/admin/sitemap/draft', ['version' => 1])->assertForbidden();
        $this->post('/admin/sitemap/publish', ['version' => 1, 'confirmed' => true])->assertForbidden();
        self::assertNull($this->live());
        $ordinary = $this->staff(['admin.access']);
        $this->flushSession();
        $this->actingAs($ordinary)->get('/admin/sitemap')->assertForbidden();
        Auth::logout();
        $this->get('/admin/sitemap')->assertRedirect('/admin/login');
    }

    public function test_module_icon_lifecycle_and_disabled_routes_preserve_snapshot_data(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $id = $this->service()->publish($this->actor(), 2);
        $manifest = json_decode(file_get_contents(base_path('modules/ambresoft/sitemap/composer.json')), true);
        self::assertSame('icon.svg', $manifest['extra']['cms-module']['icon']);
        self::assertFileEquals(base_path('modules/ambresoft/stripe-checkout/icon.svg'), base_path('modules/ambresoft/sitemap/icon.svg'));
        $manager = app(AddonManager::class);
        $manager->disable($this->actor(), 'ambresoft/sitemap');
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/admin/sitemap')->assertNotFound();
        self::assertSame($id, $this->live());
        $manager->uninstall($this->actor(), 'ambresoft/sitemap');
        $this->assertDatabaseHas('ambresoft_sitemap_versions', ['id' => $id]);
        $manager->installBundled($this->actor(), 'ambresoft/sitemap');
        $manager->enable($this->actor(), 'ambresoft/sitemap');
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_addon_settings_link_requires_an_enabled_sitemap_and_view_permission(): void
    {
        $link = fn (Assert $page) => $page->where('addons', fn ($addons) => collect($addons)->firstWhere('name', 'ambresoft/sitemap')['settings'] === [['name' => __('cms-sitemap::ui.title'), 'href' => '/admin/sitemap']]);
        $noLink = fn (Assert $page) => $page->where('addons', fn ($addons) => collect($addons)->firstWhere('name', 'ambresoft/sitemap')['settings'] === []);
        $this->get('/admin/addons')->assertOk()->assertInertia($link);
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'addons.manage', 'ambresoft.sitemap.view']))->get('/admin/addons')->assertOk()->assertInertia($link);
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'addons.manage']))->get('/admin/addons')->assertOk()->assertInertia($noLink);
        $this->flushSession();
        $this->actingAs($this->actor);
        app(AddonManager::class)->disable($this->actor(), 'ambresoft/sitemap');
        $this->get('/admin/addons')->assertOk()->assertInertia($noLink);
    }

    public function test_split_index_and_parts_are_complete_and_old_part_urls_are_immutable(): void
    {
        $feed = $this->feed();
        $feed->entries['pages'] = [new IndexableUrl('pages', '1', 'https://store.example/about', null), new IndexableUrl('pages', '2', 'https://store.example/contact', null)];
        app()->instance(SitemapXml::class, new SitemapXml(1));
        $this->service()->refreshDraft($this->actor(), 1);
        $version = $this->service()->publish($this->actor(), 2);
        Auth::logout();
        $root = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $doc = new \DOMDocument;
        self::assertTrue($doc->loadXML($root->getContent()));
        self::assertSame('sitemapindex', $doc->documentElement->localName);
        $parts = [];
        $urls = [];
        foreach ($doc->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc') as $loc) {
            $path = parse_url($loc->textContent, PHP_URL_PATH);
            $part = $this->get($path)->assertOk();
            $parts[$path] = $part->getContent();
            $xml = new \DOMDocument;
            self::assertTrue($xml->loadXML($part->getContent()));
            foreach ($xml->getElementsByTagName('loc') as $url) {
                $urls[] = $url->textContent;
            }
        }
        sort($urls);
        self::assertSame(['https://store.example/', 'https://store.example/about', 'https://store.example/contact'], $urls);
        self::assertCount(3, $parts);
        $feed->entries['pages'] = [];
        $this->service()->refreshDraft($this->actor(), $this->version());
        $this->service()->publish($this->actor(), $this->version());
        foreach ($parts as $path => $body) {
            self::assertSame($body, $this->get($path)->assertOk()->getContent());
        }
        self::assertNotSame($version, $this->live());
        $etag = $this->get('/sitemap.xml')->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get('/sitemap.xml')->assertStatus(304);
    }

    public function test_failed_publication_keeps_previous_complete_version_and_rolls_back_candidate(): void
    {
        $feed = $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $old = $this->service()->publish($this->actor(), 2);
        $xml = $this->service()->file(null, 'sitemap.xml')['xml'];
        $feed->entries['pages'] = [new IndexableUrl('pages', '1', 'https://store.example/'.str_repeat('a', 500), null)];
        $this->service()->refreshDraft($this->actor(), $this->version());
        app()->instance(SitemapXml::class, new SitemapXml(1, 300));
        try {
            $this->service()->publish($this->actor(), $this->version());
            self::fail('Oversized entry must abort publication');
        } catch (\InvalidArgumentException) {
            self::assertSame($old, $this->live());
            self::assertSame(1, DB::table('ambresoft_sitemap_versions')->count());
            self::assertSame($xml, $this->service()->file(null, 'sitemap.xml')['xml']);
        }
    }

    public function test_scheduled_refresh_uses_published_overrides_without_touching_a_manual_draft(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $old = $this->service()->publish($this->actor(), 2);
        $row = DB::table('ambresoft_sitemap_draft_urls')->first();
        $this->service()->saveDraft($this->actor(), ['version' => 3, 'entries' => [['id' => $row->id, 'include' => false, 'url' => null]]]);
        DB::table('ambresoft_sitemap_sections')->update(['frequency' => 'never']);
        DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->update(['frequency' => 'hour', 'next_refresh_at' => now()->subMinute()]);
        Queue::fake([RefreshSection::class]);
        $this->service()->dispatchDue();
        Queue::assertPushed(RefreshSection::class, 1);
        $job = Queue::pushed(RefreshSection::class)->first();
        $job->handle($this->service());
        self::assertNotSame($old, $this->live());
        self::assertSame(4, $this->version());
        self::assertFalse(DB::table('ambresoft_sitemap_draft_urls')->value('inclusion_override'));
        self::assertStringContainsString('<loc>https://store.example/</loc>', $this->service()->file(null, 'sitemap.xml')['xml']);
        $this->service()->publish($this->actor(), 4);
        DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->update(['next_refresh_at' => now()->subMinute()]);
        $this->service()->dispatchDue();
        $job = Queue::pushed(RefreshSection::class)->last();
        $job->handle($this->service());
        self::assertStringNotContainsString('<loc>', $this->service()->file(null, 'sitemap.xml')['xml']);
    }

    public function test_never_disables_automatic_work_but_manual_refresh_and_publish_still_work(): void
    {
        $this->feed();
        DB::table('ambresoft_sitemap_sections')->update(['frequency' => 'never']);
        Queue::fake([RefreshSection::class]);
        $this->service()->dispatchDue();
        Queue::assertNothingPushed();
        $this->service()->refreshDraft($this->actor(), 1);
        $this->service()->publish($this->actor(), 2);
        self::assertSame(1, $this->service()->overview()['published']['count']);
        self::assertNull($this->service()->overview()['sections']['homepage']['next_refresh_at']);
    }

    public function test_scheduled_provider_failure_keeps_old_snapshot_and_records_recovery_status(): void
    {
        $feed = $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $old = $this->service()->publish($this->actor(), 2);
        $previous = DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->value('last_refresh_at');
        DB::table('ambresoft_sitemap_sections')->update(['frequency' => 'never']);
        DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->update(['frequency' => 'hour', 'next_refresh_at' => now()->subMinute()]);
        Queue::fake([RefreshSection::class]);
        $this->service()->dispatchDue();
        $job = Queue::pushed(RefreshSection::class)->first();
        $feed->fail = true;
        try {
            $job->handle($this->service());
            self::fail('Provider failure was swallowed');
        } catch (\RuntimeException) {
            self::assertSame($old, $this->live());
            self::assertSame(1, DB::table('ambresoft_sitemap_versions')->count());
            $row = DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->first();
            self::assertSame('failed', $row->status);
            self::assertSame($previous, $row->last_refresh_at);
            self::assertNotEmpty($row->error);
        }
    }

    public function test_manual_queue_refresh_preserves_edits_and_reports_a_stale_job(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        Queue::fake([GenerateDraft::class]);
        $this->post('/admin/sitemap/generate', ['version' => 2])->assertSessionHasNoErrors();
        Queue::assertPushed(GenerateDraft::class, 1);
        $job = Queue::pushed(GenerateDraft::class)->first();
        $row = DB::table('ambresoft_sitemap_draft_urls')->first();
        $this->service()->saveDraft($this->actor(), ['version' => 3, 'entries' => [['id' => $row->id, 'include' => false, 'url' => null]]]);
        try {
            $job->handle($this->service(), app(AddonStatus::class));
            self::fail('Stale job applied');
        } catch (ValidationException) {
            self::assertFalse(DB::table('ambresoft_sitemap_draft_urls')->value('inclusion_override'));
            self::assertSame('failed', DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->value('status'));
        }
    }

    public function test_concurrent_generation_is_refused_without_modifying_the_draft(): void
    {
        $this->feed();
        config(['database.connections.sitemap_lock' => DB::connection()->getConfig()]);
        $other = DB::connection('sitemap_lock');
        $other->beginTransaction();
        $other->select("SELECT pg_advisory_xact_lock(hashtextextended('ambresoft:sitemap', 0))");
        try {
            $this->service()->refreshDraft($this->actor(), 1);
            self::fail('Concurrent operation was not refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sitemap', $e->errors());
            self::assertSame(0, DB::table('ambresoft_sitemap_draft_urls')->count());
            self::assertSame(1, $this->version());
        } finally {
            $other->rollBack();
            DB::purge('sitemap_lock');
        }
    }

    public function test_each_frequency_has_a_real_next_run_and_months_do_not_overflow(): void
    {
        $this->travelTo(now()->parse('2026-01-31T12:00:00Z'));
        $dates = ['hour' => '2026-01-31T13:00:00+00:00', 'day' => '2026-02-01T12:00:00+00:00', 'week' => '2026-02-07T12:00:00+00:00', 'month' => '2026-02-28T12:00:00+00:00', 'year' => '2027-01-31T12:00:00+00:00', 'never' => null];
        foreach ($dates as $frequency => $expected) {
            $settings = array_map(fn ($section) => ['enabled' => true, 'frequency' => $frequency], $this->service()->overview()['sections']);
            $this->service()->saveSettings($this->actor(), ['version' => $this->version(), 'sections' => $settings]);
            $date = DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->value('next_refresh_at');
            self::assertSame($expected, $date === null ? null : CarbonImmutable::parse($date)->utc()->toIso8601String());
        }
    }

    public function test_sitemap_routes_cannot_be_hijacked_by_old_redirect_rules(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $this->service()->publish($this->actor(), 2);
        DB::table('seo_redirects')->insert(['source_path' => '/sitemap.xml', 'destination' => '/elsewhere', 'status_code' => 301, 'enabled' => true]);
        app(RedirectMap::class)->forget();
        $this->get('/sitemap.xml')->assertOk()->assertHeaderMissing('Location');
        $this->post('/admin/seo-rules', ['source_path' => '/sitemap.xml', 'destination' => '/other', 'status_code' => 301, 'enabled' => true])->assertSessionHasErrors();
    }

    public function test_addon_schema_constraints_and_migration_rollback_are_real(): void
    {
        try {
            DB::transaction(fn () => DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->update(['frequency' => 'minute']));
            self::fail('Invalid frequency reached persistence');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('ambresoft_sitemap_state')->where('id', 1)->update(['published_version' => '00000000-0000-4000-8000-000000000000']));
            self::fail('A missing version was made public');
        } catch (QueryException $e) {
            self::assertSame('23503', $e->errorInfo[0]);
        }
        $migration = require base_path('modules/ambresoft/sitemap/database/migrations/2026_11_27_000100_ambresoft_sitemap_create_sitemap.php');
        $upgrade = require base_path('modules/ambresoft/sitemap/database/migrations/2026_11_27_000200_ambresoft_sitemap_retain_invalid_url_diagnostics.php');
        $upgrade->down();
        $migration->down();
        self::assertFalse(Schema::hasTable('ambresoft_sitemap_state'));
        $migration->up();
        $upgrade->up();
        self::assertSame(7, DB::table('ambresoft_sitemap_sections')->count());
        self::assertSame(1, $this->version());
    }

    public function test_url_override_uses_the_same_real_content_and_never_changes_its_slug(): void
    {
        config(['app.url' => 'https://store.example']);
        $locale = DB::table('locales')->where('code', 'en')->value('id');
        $category = CategoryRecord::create(['status' => 'active', 'position' => 1]);
        $category->translations()->create(['locale_id' => $locale, 'name' => 'Computers', 'slug' => 'computers']);
        $product = ProductRecord::create(['category_id' => $category->id, 'status' => 'active', 'sku' => 'MAP-REAL', 'price' => '10', 'quantity' => 5]);
        $product->translations()->create(['locale_id' => $locale, 'name' => 'Computer', 'slug' => 'computer']);
        $this->service()->refreshDraft($this->actor(), 1);
        $row = DB::table('ambresoft_sitemap_draft_urls')->where('section', 'products')->first();
        $this->put('/admin/sitemap/draft', ['version' => 2, 'entries' => [['id' => $row->id, 'include' => true, 'url' => 'https://store.example/computers/another-name']]])->assertSessionHasErrors('entries.0.url');
        $this->assertDatabaseHas('product_translations', ['product_id' => $product->id, 'slug' => 'computer']);
        // A real content-editor URL change can be followed by a valid override before rediscovering the whole catalog.
        DB::table('product_translations')->where('product_id', $product->id)->update(['slug' => 'renamed-computer']);
        $this->put('/admin/sitemap/draft', ['version' => 2, 'entries' => [['id' => $row->id, 'include' => true, 'url' => 'https://store.example/computers/renamed-computer']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_translations', ['product_id' => $product->id, 'slug' => 'renamed-computer']);
        $this->service()->publish($this->actor(), 3);
        self::assertStringContainsString('https://store.example/computers/renamed-computer', $this->service()->file(null, 'sitemap.xml')['xml']);
    }

    public function test_catalog_exclusions_respect_publication_and_cannot_be_forced_by_draft_inclusion(): void
    {
        config(['app.url' => 'https://store.example']);
        $locale = DB::table('locales')->where('code', 'en')->value('id');
        $category = CategoryRecord::create(['status' => 'active', 'position' => 1]);
        $category->translations()->create(['locale_id' => $locale, 'name' => 'Computers', 'slug' => 'computers']);
        $product = ProductRecord::create(['category_id' => $category->id, 'status' => 'active', 'sku' => 'MAP-EXCLUDE', 'price' => '10', 'quantity' => 5]);
        $product->translations()->create(['locale_id' => $locale, 'name' => 'Computer', 'slug' => 'computer']);
        $this->service()->refreshDraft($this->actor(), $this->version());
        $this->service()->publish($this->actor(), $this->version());
        $before = $this->service()->file(null, 'sitemap.xml')['xml'];
        self::assertStringContainsString('/computers/computer</loc>', $before);
        $rows = DB::table('ambresoft_sitemap_draft_urls')->whereIn('section', ['categories', 'products'])->get();
        $this->service()->saveDraft($this->actor(), ['version' => $this->version(), 'entries' => $rows->map(fn ($row) => ['id' => $row->id, 'include' => $row->section === 'products', 'url' => null])->all()]);
        $product->update(['exclude_from_sitemap' => true]);
        self::assertSame($before, $this->service()->file(null, 'sitemap.xml')['xml']);
        $this->post('/admin/sitemap/publish', ['version' => $this->version(), 'confirmed' => true])->assertSessionHasErrors('sitemap');
        self::assertSame($before, $this->service()->file(null, 'sitemap.xml')['xml']);
        $this->service()->refreshDraft($this->actor(), $this->version());
        $this->service()->publish($this->actor(), $this->version());
        self::assertStringNotContainsString('/computers/computer</loc>', $this->service()->file(null, 'sitemap.xml')['xml']);
        self::assertTrue(DB::table('ambresoft_sitemap_draft_urls')->where('section', 'products')->value('inclusion_override'));
        self::assertFalse(DB::table('ambresoft_sitemap_draft_urls')->where('section', 'categories')->value('inclusion_override'));
        $product->update(['exclude_from_sitemap' => false]);
        $this->service()->refreshDraft($this->actor(), $this->version());
        $this->service()->publish($this->actor(), $this->version());
        $xml = $this->service()->file(null, 'sitemap.xml')['xml'];
        self::assertStringContainsString('/computers/computer</loc>', $xml);
        self::assertStringNotContainsString('/computers</loc>', $xml);
    }

    public function test_switching_a_queued_section_to_never_cancels_it_without_blocking_manual_publish(): void
    {
        $this->feed();
        $this->service()->refreshDraft($this->actor(), 1);
        $id = $this->service()->publish($this->actor(), 2);
        DB::table('ambresoft_sitemap_sections')->update(['frequency' => 'never']);
        DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->update(['frequency' => 'hour', 'next_refresh_at' => now()->subMinute()]);
        Queue::fake([RefreshSection::class]);
        $this->service()->dispatchDue();
        $job = Queue::pushed(RefreshSection::class)->first();
        DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->update(['frequency' => 'never']);
        $job->handle($this->service());
        self::assertSame($id, $this->live());
        self::assertSame('idle', DB::table('ambresoft_sitemap_sections')->where('key', 'homepage')->value('status'));
        $this->service()->publish($this->actor(), $this->version());
        self::assertNotSame($id, $this->live());
    }

    public function test_paged_draft_save_preserves_rows_not_submitted_from_other_pages(): void
    {
        $feed = $this->feed();
        for ($n = 1; $n <= 51; $n++) {
            $feed->entries['pages'][] = new IndexableUrl('pages', (string) $n, 'https://store.example/page-'.$n, null);
        }
        $this->service()->refreshDraft($this->actor(), 1);
        $page = $this->service()->listing('pages', 1);
        self::assertCount(50, $page['items']);
        $outside = DB::table('ambresoft_sitemap_draft_urls')->where('section', 'pages')->where('source_id', '51')->first();
        $inside = DB::table('ambresoft_sitemap_draft_urls')->where('section', 'pages')->where('source_id', '1')->first();
        $this->service()->saveDraft($this->actor(), ['version' => 2, 'entries' => [['id' => $outside->id, 'include' => false, 'url' => null]]]);
        $this->service()->saveDraft($this->actor(), ['version' => 3, 'entries' => [['id' => $inside->id, 'include' => true, 'url' => null]]]);
        $this->assertDatabaseHas('ambresoft_sitemap_draft_urls', ['id' => $outside->id, 'inclusion_override' => false]);
        self::assertSame(52, DB::table('ambresoft_sitemap_draft_urls')->count());
    }
}
