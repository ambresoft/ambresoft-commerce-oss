<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\ReelAdministration;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\LayoutStore;
use App\Storefront\Infrastructure\StorefrontReels;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ReelsTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=';

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 1));
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['status' => 'active', 'translations' => ['en' => ['name' => 'Store reel'], 'el' => ['name' => 'Βίντεο καταστήματος']], 'products' => []], $changes);
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('reel.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\x00", 64));
    }

    private function cover(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG));
    }

    private function reel(array $changes = []): string
    {
        return app(ReelAdministration::class)->save((string) $this->actor->id, null, $this->payload($changes), $this->video(), $this->cover());
    }

    private function block(string $source = 'manual', array $ids = []): array
    {
        $definition = app(StorefrontRegistry::class)->type('reels')->definition();

        return ['id' => (string) Str::uuid(), 'type' => 'reels', 'name' => 'Videos', 'template' => 'reel_carousel', 'css_class' => '', 'settings' => [...$definition['defaults'], 'source' => $source], 'selections' => ['items' => $ids], 'translations' => ['en' => ['title' => 'Videos', 'html' => '']]];
    }

    private function items(string $source, array $ids = []): array
    {
        return app(BlockContent::class)->resolve('reels', ['block' => $this->block($source, $ids)], ['locale' => 'en', 'customer' => null])['block']['items'] ?? [];
    }

    public function test_admin_upload_links_products_validates_and_conflicts(): void
    {
        $product = $this->orderProduct('25.50');
        $this->post('/admin/reels', [...$this->payload(['products' => [(string) $product->id]]), 'video' => $this->video(), 'cover' => $this->cover()])->assertSessionHasNoErrors()->assertRedirect();
        $id = (string) DB::table('reels')->sole()->id;
        $this->assertDatabaseHas('reel_products', ['reel_id' => $id, 'product_id' => $product->id, 'position' => 1]);
        $this->get('/admin/reels/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->where('item.products.0', (string) $product->id));
        $this->post('/admin/reels/'.$id, [...$this->payload(), 'version' => 1])->assertSessionHasNoErrors();
        $this->post('/admin/reels/'.$id, [...$this->payload(), 'version' => 1])->assertSessionHasErrors('version');
        $this->post('/admin/reels', [...$this->payload(), 'video' => UploadedFile::fake()->createWithContent('bad.mp4', '<html>bad</html>'), 'cover' => $this->cover()])->assertSessionHasErrors('video');
        $this->post('/admin/reels', [...$this->payload(['products' => [$product->id, $product->id]]), 'video' => $this->video(), 'cover' => $this->cover()])->assertSessionHasErrors('products.0');
        $this->post('/admin/reels', [...$this->payload(['translations' => ['en' => ['name' => ''], 'xx' => ['name' => 'bad']]]), 'video' => $this->video(), 'cover' => $this->cover()])->assertSessionHasErrors(['translations', 'translations.en.name']);
        self::assertSame(1, DB::table('reels')->count());
        self::assertSame(1, DB::table('media_videos')->count());
        $this->get('/admin/reels')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
    }

    public function test_multipart_upload_without_product_selections_is_accepted(): void
    {
        $payload = $this->payload();
        unset($payload['products']);
        $this->post('/admin/reels', [...$payload, 'video' => $this->video(), 'cover' => $this->cover()])->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame(1, DB::table('reels')->count());
        self::assertSame(0, DB::table('reel_products')->count());
    }

    public function test_failure_after_staging_rolls_back_database_and_disk(): void
    {
        DB::unprepared("CREATE FUNCTION reject_test_reel() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test rollback''; END;'");
        DB::statement('CREATE TRIGGER reject_test_reel BEFORE INSERT ON reels FOR EACH ROW EXECUTE FUNCTION reject_test_reel()');
        try {
            $this->reel();
            self::fail('The database must reject this insert.');
        } catch (QueryException) {
            self::assertSame([], Storage::disk('local')->allFiles());
            self::assertSame(0, DB::table('media_videos')->count());
            self::assertSame(0, DB::table('media_files')->count());
        }
    }

    public function test_manual_layout_selection_order_single_template_and_deletion(): void
    {
        $a = $this->reel();
        $b = $this->reel();
        $layout = app(LayoutStore::class)->read('homepage');
        $grid = (string) Str::uuid();
        $layout['grids'] = [['id' => $grid, 'parent_id' => null, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]];
        $layout['placements'] = [['id' => (string) Str::uuid(), 'grid_id' => $grid, 'position' => 1, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true, 'block' => $this->block('manual', [$b, $a])]];
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasNoErrors();
        self::assertSame([$b, $a], app(LayoutStore::class)->read('homepage')['placements'][0]['block']['selections']['items']);
        self::assertSame([$b, $a], array_column($this->items('manual', [$b, $a]), 'id'));
        $block = [...$this->block('manual', [$b, $a]), 'template' => 'reel_single'];
        self::assertCount(1, app(StorefrontReels::class)->resolve(['b' => $block], ['locale' => 'en', 'customer' => null])['b']['items']);
        $this->delete('/admin/reels/'.$b, ['version' => 1])->assertSessionHasNoErrors();
        self::assertSame([$a], app(LayoutStore::class)->read('homepage')['placements'][0]['block']['selections']['items']);
    }

    public function test_newest_and_most_viewed_use_the_setting_and_live_eligibility(): void
    {
        $old = $this->reel();
        DB::table('reels')->where('id', $old)->update(['created_at' => now()->subDays(91)]);
        $new = $this->reel();
        $disabled = $this->reel(['status' => 'disabled']);
        DB::table('reel_views')->insert([
            ['reel_id' => $old, 'viewer_key' => str_repeat('a', 64), 'view_bucket' => 1, 'viewed_at' => now()->subDay()],
            ['reel_id' => $new, 'viewer_key' => str_repeat('b', 64), 'view_bucket' => 1, 'viewed_at' => now()->subDays(91)],
        ]);
        self::assertSame([$new], array_column($this->items('newest'), 'id'));
        self::assertSame([$old, $new], array_column($this->items('most_viewed'), 'id'));
        DB::table('general_settings')->where('id', 1)->update(['reels_days' => 120]);
        self::assertCount(2, $this->items('newest'));
        DB::table('reels')->where('id', $old)->update(['status' => 'disabled']);
        self::assertSame([$new], array_column($this->items('manual', [$old, $new, $disabled]), 'id'));
    }

    public function test_public_detail_filters_products_and_media_are_owned_range_capable_and_closed_with_store(): void
    {
        $visible = $this->orderProduct('12.50');
        $hidden = $this->orderProduct('25');
        $id = $this->reel(['products' => [$visible->id, $hidden->id]]);
        DB::table('products')->where('id', $hidden->id)->update(['status' => 'disabled']);
        $this->app['auth']->forgetGuards();
        $detail = $this->getJson('/storefront/reels/'.$id)->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', (string) $visible->id)->json();
        self::assertArrayNotHasKey('viewer_key', $detail);
        $this->get($detail['video'], ['Range' => 'bytes=0-11'])->assertStatus(206)->assertHeader('Content-Type', 'video/mp4');
        $other = $this->reel();
        $this->get(str_replace('/'.$id.'/video/', '/'.$other.'/video/', $detail['video']))->assertNotFound();
        DB::table('reels')->where('id', $id)->update(['status' => 'disabled']);
        $this->get($detail['video'])->assertNotFound();
        $this->get($detail['image']['url'])->assertNotFound();
        $this->getJson('/storefront/reels/'.$id)->assertNotFound();
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => false]);
        $this->getJson('/storefront/reels/'.$other)->assertNotFound();
    }

    public function test_registered_view_is_session_authenticated_deduplicated_and_history_is_private(): void
    {
        $id = $this->reel();
        $viewer = UserRecord::create(['name' => 'Reel customer', 'email' => 'reel@example.test', 'password' => 'customer passphrase']);
        $this->actingAs($viewer);
        $token = $this->getJson('/storefront/reels/'.$id)->assertOk()->json('view_token');
        $this->postJson('/storefront/reels/'.$id.'/views', ['token' => $token])->assertUnprocessable();
        $this->travel(4)->seconds();
        $payload = ['token' => $token, 'user_id' => $this->actor->id];
        $this->postJson('/storefront/reels/'.$id.'/views', $payload)->assertOk();
        $this->postJson('/storefront/reels/'.$id.'/views', $payload)->assertOk();
        self::assertSame(1, DB::table('reel_views')->count());
        $this->assertDatabaseHas('reel_views', ['reel_id' => $id, 'user_id' => $viewer->id]);
        self::assertSame(0, DB::table('audit_logs')->where('entity_type', 'reel_view')->count());
        $this->travel(31)->minutes();
        $token = $this->getJson('/storefront/reels/'.$id)->json('view_token');
        $this->travel(4)->seconds();
        $this->postJson('/storefront/reels/'.$id.'/views', ['token' => $token])->assertOk();
        self::assertSame(2, DB::table('reel_views')->count());
        $reader = $this->staff(['admin.access', 'reels.view']);
        $this->actingAs($reader)->get('/admin/reels/'.$id.'/history')->assertForbidden();
        $this->withSession(['password_hash_web' => $this->actor->password])->actingAs($this->actor)->get('/admin/reels/'.$id.'/history')->assertOk()->assertInertia(fn (Assert $p) => $p->where('stats.total', 2)->where('stats.registered', 1)->where('rows.data.0.email', $viewer->email)->missing('rows.data.0.viewer_key'));
        $this->actingAs($reader)->postJson('/storefront/reels/'.$id.'/views', ['token' => $token])->assertUnprocessable();
    }

    public function test_guest_view_cannot_replay_a_different_reel_token_or_count_disabled_video(): void
    {
        $id = $this->reel();
        $other = $this->reel();
        auth()->logout();
        $this->withCredentials();
        $token = $this->getJson('/storefront/reels/'.$id)->json('view_token');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->travel(4)->seconds();
        $this->postJson('/storefront/reels/'.$other.'/views', ['token' => $token])->assertUnprocessable();
        $this->postJson('/storefront/reels/'.$id.'/views', ['token' => 'forged'])->assertUnprocessable();
        $reply = $this->postJson('/storefront/reels/'.$id.'/views', ['token' => $token]);
        $reply->assertOk();
        $this->assertDatabaseHas('reel_views', ['reel_id' => $id, 'user_id' => null]);
        DB::table('reels')->where('id', $id)->update(['status' => 'disabled']);
        $this->postJson('/storefront/reels/'.$id.'/views', ['token' => $token])->assertNotFound();
    }

    public function test_permissions_cover_direct_operations_and_private_media(): void
    {
        $id = $this->reel();
        $staff = $this->staff(['admin.access']);
        $this->actingAs($staff);
        foreach (['/admin/reels', '/admin/reels/create', '/admin/reels/'.$id.'/edit', '/admin/reels/'.$id.'/cover', '/admin/reels/'.$id.'/video'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/reels', $this->payload())->assertForbidden();
        $this->delete('/admin/reels/'.$id, ['version' => 1])->assertForbidden();
        try {
            app(ReelAdministration::class)->status((string) $staff->id, $id, 1, 'disabled');
            self::fail('Direct writes must authorize.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('reels', ['id' => $id, 'status' => 'active']);
        }
        $media = MediaFileRecord::findOrFail(DB::table('reels')->where('id', $id)->value('cover_media_id'));
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $media->id));
        $this->expectException(ValidationException::class);
        app(SharedMediaDeletion::class)->assertUnreferenced($media);
    }
}
