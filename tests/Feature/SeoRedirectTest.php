<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Website\Domain\Service\RedirectRules;
use App\Website\Infrastructure\RedirectAdministration;
use App\Website\Infrastructure\RedirectMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class SeoRedirectTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config(['app.url' => 'http://shop.test']);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
    }

    /** @param array<string, mixed> $input */
    private function rule(array $input, ?string $id = null, ?int $version = null): string
    {
        return app(RedirectAdministration::class)->save((string) $this->actor->id, $id, $version, [...['status_code' => 301, 'enabled' => true], ...$input]);
    }

    /** @param array<string, mixed> $input
     * @return array<string, list<string>> */
    private function errors(array $input, ?string $id = null, ?int $version = null): array
    {
        try {
            $this->rule($input, $id, $version);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('The rule was accepted.');
    }

    public function test_sources_are_stored_normalized_and_unique_as_matched(): void
    {
        $id = $this->rule(['source_path' => '/Old-Page/', 'destination' => '/New//Page/']);
        $row = DB::table('seo_redirects')->where('id', $id)->first();
        self::assertSame(['/old-page', '/New/Page', 301, true], [$row->source_path, $row->destination, (int) $row->status_code, (bool) $row->enabled]);
        // The same path written another way is the same rule.
        foreach (['/old-page', '/OLD-PAGE', '//old-page//', '/old%2Dpage', 'http://shop.test/old-page/'] as $same) {
            self::assertArrayHasKey('source_path', $this->errors(['source_path' => $same, 'destination' => '/elsewhere']), $same);
        }
        // Editing a rule may keep its own source.
        $this->rule(['source_path' => '/OLD-page', 'destination' => '/newer', 'status_code' => 302], $id, 1);
        self::assertSame(['/old-page', '/newer', 302], [DB::table('seo_redirects')->value('source_path'), DB::table('seo_redirects')->value('destination'), (int) DB::table('seo_redirects')->value('status_code')]);
        // The database keeps the same guarantees.
        $this->expectException(QueryException::class);
        DB::table('seo_redirects')->insert(['source_path' => '/Mixed/', 'destination' => '/x', 'status_code' => 301]);
    }

    public function test_destinations_must_be_internal_paths_or_safe_external_addresses(): void
    {
        $this->rule(['source_path' => '/partner', 'destination' => 'https://partner.example.com/landing?ref=shop']);
        $this->rule(['source_path' => '/own-site', 'destination' => 'http://shop.test/phones/']);
        self::assertSame(['https://partner.example.com/landing?ref=shop', '/phones'], DB::table('seo_redirects')->orderBy('id')->pluck('destination')->all());
        foreach (['', 'javascript:alert(1)', '//evil.example', 'https://user:pw@evil.example', 'http://127.0.0.1/x', 'ftp://example.com', 'https:\\\\evil.example', 'not a url', '/a/../b'] as $bad) {
            self::assertArrayHasKey('destination', $this->errors(['source_path' => '/bad-'.md5($bad), 'destination' => $bad]), $bad);
        }
    }

    public function test_malformed_and_reserved_sources_and_invalid_types_are_refused(): void
    {
        foreach (['', 'no-slash', '/', '/admin', '/admin/products', '/login', '/cart', '/checkout', '/account', '/robots.txt', '/storefront/media/x', '/old?x=1', '/a/../b', '/a%2Fb', "/a\x01", 'https://other.example/old'] as $bad) {
            self::assertArrayHasKey('source_path', $this->errors(['source_path' => $bad, 'destination' => '/x']), $bad);
        }
        self::assertArrayHasKey('status_code', $this->errors(['source_path' => '/old', 'destination' => '/new', 'status_code' => 307]));
        self::assertSame(0, DB::table('seo_redirects')->count());
    }

    public function test_self_redirects_and_loops_through_enabled_rules_are_refused(): void
    {
        self::assertArrayHasKey('destination', $this->errors(['source_path' => '/same', 'destination' => '/Same/?utm=x']));
        self::assertArrayHasKey('destination', $this->errors(['source_path' => '/same', 'destination' => 'http://shop.test/same']));
        $a = $this->rule(['source_path' => '/a', 'destination' => '/b']);
        $this->rule(['source_path' => '/b', 'destination' => '/c']);
        // /c -> /a would close /a -> /b -> /c -> /a.
        self::assertSame([__('seo_rules.loop')], $this->errors(['source_path' => '/c', 'destination' => '/a'])['destination']);
        // A chain without a loop is allowed.
        $this->rule(['source_path' => '/c', 'destination' => '/d']);
        // Repointing /a at /c keeps a plain chain (/a -> /c -> /d, /b -> /c); /c back at /b would loop (/b -> /c -> /b).
        $this->rule(['source_path' => '/a', 'destination' => '/c'], $a, 1);
        $c = (string) DB::table('seo_redirects')->where('source_path', '/c')->value('id');
        self::assertArrayHasKey('destination', $this->errors(['source_path' => '/c', 'destination' => '/B/'], $c, 1));
        // A disabled rule takes no part: the loop is only refused once it would be enabled.
        $this->rule(['source_path' => '/d', 'destination' => '/a', 'enabled' => false]);
        $d = (string) DB::table('seo_redirects')->where('source_path', '/d')->value('id');
        self::assertArrayHasKey('destination', $this->errors(['source_path' => '/d', 'destination' => '/a', 'enabled' => true], $d, 1));
        try {
            app(RedirectAdministration::class)->setEnabled((string) $this->actor->id, $d, 1, true);
            self::fail('A loop was enabled.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('destination', $e->errors());
        }
        self::assertFalse((bool) DB::table('seo_redirects')->where('id', $d)->value('enabled'));
    }

    public function test_changes_need_the_capability_and_a_current_version(): void
    {
        $id = $this->rule(['source_path' => '/old', 'destination' => '/new']);
        self::assertArrayHasKey('version', $this->errors(['source_path' => '/old', 'destination' => '/newer'], $id, 7));
        $viewer = $this->staff(['admin.access', 'seo-rules.view']);
        $this->expectException(AuthorizationException::class);
        app(RedirectAdministration::class)->save((string) $viewer->id, null, null, ['source_path' => '/x', 'destination' => '/y']);
    }

    public function test_staff_list_create_edit_toggle_and_delete_redirects(): void
    {
        $this->actingAs($this->actor);
        $navigation = json_encode($this->get('/admin/seo-rules')->assertOk()->viewData('page')['props']['navigation']);
        self::assertStringContainsString('admin\\/seo-rules', $navigation);
        $this->get('/admin/seo-rules')->assertInertia(fn (Assert $p) => $p->component('Admin/SeoRules/Index')->where('rules.total', 0));

        $this->post('/admin/seo-rules', ['source_path' => '/Old-Phones/', 'destination' => '/phones', 'status_code' => '301', 'enabled' => true])->assertSessionHasNoErrors()->assertSessionHas('status', __('seo_rules.saved'));
        $id = (string) DB::table('seo_redirects')->value('id');
        $this->get('/admin/seo-rules/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/SeoRules/Form')
            ->where('rule.source_path', '/old-phones')->where('rule.destination', '/phones')->where('rule.status_code', 301)->where('rule.enabled', true)->where('rule.chain_to', null));
        $this->put('/admin/seo-rules/'.$id, ['version' => 1, 'source_path' => '/old-phones', 'destination' => 'https://partner.example.com/phones', 'status_code' => '302', 'enabled' => true])->assertSessionHasNoErrors();
        self::assertSame(['https://partner.example.com/phones', 302], [DB::table('seo_redirects')->value('destination'), (int) DB::table('seo_redirects')->value('status_code')]);

        // Disabling keeps the rule.
        $this->patch('/admin/seo-rules/'.$id.'/status', ['version' => 2, 'enabled' => false])->assertSessionHasNoErrors();
        self::assertFalse((bool) DB::table('seo_redirects')->where('id', $id)->value('enabled'));
        $this->patch('/admin/seo-rules/'.$id.'/status', ['version' => 2, 'enabled' => true])->assertSessionHasErrors('version');

        // Search, filters and pagination.
        foreach (range(1, 30) as $n) {
            $this->rule(['source_path' => '/legacy-'.$n, 'destination' => '/new-'.$n]);
        }
        $this->get('/admin/seo-rules')->assertInertia(fn (Assert $p) => $p->where('rules.total', 31)->where('rules.last_page', 2)->has('rules.data', 25));
        $this->get('/admin/seo-rules?search=PARTNER')->assertInertia(fn (Assert $p) => $p->where('rules.total', 1)->where('rules.data.0.external', true));
        $this->get('/admin/seo-rules?status=disabled')->assertInertia(fn (Assert $p) => $p->where('rules.total', 1));
        $this->get('/admin/seo-rules?code=302')->assertInertia(fn (Assert $p) => $p->where('rules.total', 1));
        $this->get('/admin/seo-rules?status=bogus')->assertSessionHasErrors('status');

        // Delete removes it for good.
        $this->delete('/admin/seo-rules/'.$id, ['version' => 3])->assertRedirect('/admin/seo-rules')->assertSessionHas('status', __('seo_rules.deleted'));
        self::assertFalse(DB::table('seo_redirects')->where('id', $id)->exists());
    }

    public function test_the_screen_shows_validation_messages_and_chain_hints(): void
    {
        $this->actingAs($this->actor);
        $this->post('/admin/seo-rules', ['source_path' => '/admin/x', 'destination' => 'javascript:alert(1)', 'status_code' => '307'])
            ->assertSessionHasErrors(['source_path' => __('seo_rules.source_reserved'), 'destination' => __('seo_rules.destination_invalid'), 'status_code' => __('seo_rules.code_invalid')]);
        $this->post('/admin/seo-rules', ['source_path' => '/same', 'destination' => '/same'])->assertSessionHasErrors(['destination' => __('seo_rules.self_redirect')]);
        $this->rule(['source_path' => '/a', 'destination' => '/b']);
        $this->rule(['source_path' => '/b', 'destination' => '/c']);
        $this->post('/admin/seo-rules', ['source_path' => '/c', 'destination' => '/a'])->assertSessionHasErrors(['destination' => __('seo_rules.loop')]);
        $this->post('/admin/seo-rules', ['source_path' => '/A/', 'destination' => '/z'])->assertSessionHasErrors(['source_path' => __('seo_rules.source_taken')]);
        $a = (string) DB::table('seo_redirects')->where('source_path', '/a')->value('id');
        $b = (string) DB::table('seo_redirects')->where('source_path', '/b')->value('id');
        $this->get('/admin/seo-rules/'.$a.'/edit')->assertInertia(fn (Assert $p) => $p->where('rule.chain_to', '/c')->where('rule.incoming', []));
        $this->get('/admin/seo-rules/'.$b.'/edit')->assertInertia(fn (Assert $p) => $p->where('rule.chain_to', null)->where('rule.incoming', ['/a']));
    }

    public function test_viewing_and_changing_need_their_capabilities(): void
    {
        $id = $this->rule(['source_path' => '/old', 'destination' => '/new']);
        $this->actingAs($this->staff(['admin.access', 'seo-rules.view']));
        $this->get('/admin/seo-rules')->assertOk();
        $this->get('/admin/seo-rules/'.$id.'/edit')->assertOk();
        $this->get('/admin/seo-rules/create')->assertForbidden();
        $this->post('/admin/seo-rules', ['source_path' => '/x', 'destination' => '/y'])->assertForbidden();
        $this->put('/admin/seo-rules/'.$id, ['version' => 1, 'source_path' => '/old', 'destination' => '/z'])->assertForbidden();
        $this->patch('/admin/seo-rules/'.$id.'/status', ['version' => 1, 'enabled' => false])->assertForbidden();
        $this->delete('/admin/seo-rules/'.$id, ['version' => 1])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/seo-rules')->assertForbidden();
        self::assertSame(['/new', true], [DB::table('seo_redirects')->value('destination'), (bool) DB::table('seo_redirects')->value('enabled')]);
    }

    public function test_storefront_requests_follow_enabled_rules_with_their_status_and_query(): void
    {
        $this->rule(['source_path' => '/old-phones', 'destination' => '/phones']);
        $this->rule(['source_path' => '/summer-sale.html', 'destination' => '/offers?season=summer#top', 'status_code' => 302]);
        $this->rule(['source_path' => '/partner', 'destination' => 'https://partner.example.com/landing?ref=shop']);
        // 301 to an internal path; letter case, trailing slashes and encoding do not matter. No such page exists (it would be 404).
        $this->get('/old-phones')->assertStatus(301)->assertRedirect('/phones');
        $this->get('/Old-Phones/')->assertStatus(301)->assertRedirect('/phones');
        $this->get('/old%2Dphones')->assertStatus(301);
        // 302; the visitor's query is kept, the destination's own parameters win, and its fragment stays last.
        $this->get('/summer-sale.html?utm_source=mail&season=winter')->assertStatus(302)->assertRedirect('/offers?utm_source=mail&season=summer#top');
        $this->get('/partner?utm_source=x')->assertStatus(301)->assertRedirect('https://partner.example.com/landing?utm_source=x&ref=shop');
        // HEAD as GET; other methods are never redirected.
        $this->call('HEAD', '/old-phones')->assertStatus(301);
        self::assertFalse($this->post('/old-phones')->isRedirection());
        // A rule wins over a page that still answers at its old address.
        $this->get('/sitemap')->assertOk();
        $this->rule(['source_path' => '/sitemap', 'destination' => '/brands']);
        $this->get('/sitemap')->assertStatus(301)->assertRedirect('/brands');
        // Untouched addresses behave as before.
        $this->get('/no-such-page')->assertNotFound();
    }

    public function test_disabled_rules_do_nothing_and_changes_apply_at_once(): void
    {
        $id = $this->rule(['source_path' => '/old', 'destination' => '/new']);
        $this->get('/old')->assertStatus(301);
        app(RedirectAdministration::class)->setEnabled((string) $this->actor->id, $id, 1, false);
        $this->get('/old')->assertNotFound();
        self::assertTrue(DB::table('seo_redirects')->where('id', $id)->exists());
        app(RedirectAdministration::class)->setEnabled((string) $this->actor->id, $id, 2, true);
        $this->get('/old')->assertStatus(301)->assertRedirect('/new');
        app(RedirectAdministration::class)->delete((string) $this->actor->id, $id, 3);
        $this->get('/old')->assertNotFound();
    }

    public function test_chains_take_one_hop_and_loops_never_redirect(): void
    {
        $this->rule(['source_path' => '/a', 'destination' => '/b', 'status_code' => 302]);
        $this->rule(['source_path' => '/b', 'destination' => '/c?x=1']);
        $this->rule(['source_path' => '/c', 'destination' => '/final']);
        // Straight to the end of the chain, with the first rule's status.
        $this->get('/a')->assertStatus(302)->assertRedirect('/final');
        // Rows written around the service (an import, a database edit) that loop are ignored instead of looping.
        DB::table('seo_redirects')->insert([['source_path' => '/loop-1', 'destination' => '/loop-2', 'status_code' => 301], ['source_path' => '/loop-2', 'destination' => '/Loop-1/', 'status_code' => 301]]);
        app(RedirectMap::class)->forget();
        $this->get('/loop-1')->assertNotFound();
        $this->get('/loop-2')->assertNotFound();
    }

    public function test_reserved_paths_assets_and_endpoints_are_never_redirected(): void
    {
        // Rows that bypass validation still cannot take over the store's own addresses.
        foreach (['/admin', '/admin/categories', '/login', '/register', '/cart', '/checkout', '/account', '/storefront/media/x', '/build/app.js', '/robots.txt', '/up', '/search/suggest'] as $n => $path) {
            DB::table('seo_redirects')->insert(['source_path' => $path, 'destination' => '/hijacked-'.$n, 'status_code' => 301]);
        }
        app(RedirectMap::class)->forget();
        $this->get('/login')->assertOk();
        $this->get('/cart')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/admin/categories')->assertRedirect('/admin/login');
        foreach (['/admin', '/register', '/checkout', '/account', '/storefront/media/x', '/build/app.js', '/robots.txt', '/search/suggest'] as $path) {
            self::assertStringNotContainsString('hijacked', (string) $this->get($path)->headers->get('Location'), $path);
        }
    }

    public function test_every_route_prefix_is_either_reserved_or_a_storefront_page_that_may_be_redirected(): void
    {
        // Pages a merchant may move with a redirect; anything else a route answers must be reserved.
        $pages = ['', 'bestsellers', 'new', 'offers', 'brands', 'sitemap', '{fallbackPlaceholder}'];
        $unreviewed = [];
        foreach (app('router')->getRoutes() as $route) {
            $first = explode('/', $route->uri())[0];
            if (! in_array($first, $pages, true) && ! in_array($first, RedirectRules::RESERVED, true)) {
                $unreviewed[$first] = true;
            }
        }
        self::assertSame([], array_keys($unreviewed), 'New route prefixes must be added to RedirectRules::RESERVED (or reviewed as redirectable pages).');
    }

    public function test_greek_addresses_match_however_they_are_written_and_are_sent_encoded(): void
    {
        $this->rule(['source_path' => '/Κινητά-Τηλέφωνα', 'destination' => '/κατηγορία/νέα']);
        // The same letters with decomposed accents are the same rule.
        self::assertArrayHasKey('source_path', $this->errors(['source_path' => '/'."κινητα\u{0301}-τηλε\u{0301}φωνα", 'destination' => '/x']));
        $location = $this->get('/'.rawurlencode('κινητά-τηλέφωνα'))->assertStatus(301)->headers->get('Location');
        self::assertStringEndsWith('/'.rawurlencode('κατηγορία').'/'.rawurlencode('νέα'), (string) $location);
        self::assertSame(1, preg_match('/^[\x21-\x7e]+$/', (string) $location));
        $this->rule(['source_path' => '/greek-partner', 'destination' => 'https://παράδειγμα.gr/σελίδα']);
        self::assertSame('https://xn--hxajbheg2az3al.gr/'.rawurlencode('σελίδα'), $this->get('/greek-partner')->headers->get('Location'));
    }
}
