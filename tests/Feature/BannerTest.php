<?php

namespace Tests\Feature;

use App\Customer\Application\Command\SaveCustomerGroupData;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\BannerAdministration;
use App\Media\Infrastructure\SharedMediaDeletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class BannerTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=';

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'names' => ['en' => 'Summer sale', 'el' => 'Καλοκαιρινές προσφορές'],
            'position' => 10, 'status' => 'active', 'created_on' => '2026-10-01',
            'color_scheme' => 'dark', 'object_type' => 'image', 'products_template' => 'grid', 'products_columns' => 4, 'products_rows' => 1,
            'object_valign' => 'center', 'object_halign' => 'right', 'background_halign' => 'center',
            'title_weight' => 'bold', 'title_tag' => 'h2', 'button_style' => 'outline', 'button_hover' => 'lift', 'button_transition' => '0.5', 'url' => '/sale', 'new_window' => '1',
            'title_color' => '#FFF', 'tablet_custom' => '1', 'phone_custom' => '0',
            'layouts' => [
                'pc' => ['valign' => 'center', 'halign' => 'left', 'paddings' => '10px 20px 20px 20px', 'full_width' => '0', 'title_size' => 32, 'title_shadow' => '1', 'description_size' => 16,
                    'background_type' => 'image', 'background_size' => 'cover', 'background_valign' => 'top', 'button_enabled' => '1', 'button_color' => '#e11d48',
                    'mask_type' => 'gradient', 'mask_position' => 'banner', 'mask_opacity' => 40, 'mask_direction' => 'auto', 'css_classes' => 'banner-button-bottom'],
                'tablet' => ['paddings' => '5%', 'title_size' => 24],
            ],
            'contents' => [
                'pc' => [
                    'en' => ['title' => 'Up to <i>50%</i> off<script>x</script>', 'description' => 'Bakery & pastry', 'button_text' => 'Shop now'],
                    'el' => ['title' => 'Έως <i>50%</i>', 'button_text' => 'Αγορά'],
                ],
                'tablet' => ['en' => ['title' => 'Up to 50%']],
            ],
        ], $overrides);
    }

    private function create(array $overrides = [], array $files = []): string
    {
        $this->post('/admin/banners', [...$this->payload($overrides), ...$files])->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('banners')->max('id');
    }

    public function test_a_banner_is_saved_with_settings_device_overrides_texts_and_projected_for_editing(): void
    {
        $id = $this->create();
        $banner = DB::table('banners')->where('id', $id)->first();
        self::assertSame(['dark', '#ffffff', 'h2', '/sale', true, true], [$banner->color_scheme, $banner->title_color, $banner->title_tag, $banner->url, $banner->new_window, $banner->tablet_custom]);
        self::assertSame(['lift', '0.5'], [$banner->button_hover, $banner->button_transition]);
        $pc = DB::table('banner_layouts')->where(['banner_id' => $id, 'device' => 'pc'])->first();
        self::assertSame(['10px 20px 20px 20px', 32, 'gradient', 'banner-button-bottom'], [$pc->paddings, $pc->title_size, $pc->mask_type, $pc->css_classes]);
        $tablet = DB::table('banner_layouts')->where(['banner_id' => $id, 'device' => 'tablet'])->first();
        self::assertSame(['5%', 24, null, null], [$tablet->paddings, $tablet->title_size, $tablet->valign, $tablet->mask_type]);
        $en = DB::table('locales')->where('code', 'en')->value('id');
        self::assertSame('Up to <i>50%</i> off', DB::table('banner_contents')->where(['banner_id' => $id, 'device' => 'pc', 'locale_id' => $en])->value('title'));
        self::assertNull(DB::table('banner_layouts')->where(['banner_id' => $id, 'device' => 'phone'])->first());

        $this->get('/admin/banners/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->component('Admin/Banners/Form')
            ->where('banner.button_hover', 'lift')->where('banner.button_transition', 0.5)->where('banner.names.el', 'Καλοκαιρινές προσφορές')->where('banner.layouts.tablet.paddings', '5%')->where('banner.layouts.tablet.valign', null)
            ->where('banner.contents.tablet.en.title', 'Up to 50%')->where('banner.contents.pc.el.button_text', 'Αγορά')->where('banner.state', 'live')
            ->where('layoutDefaults.paddings', '10px 20px 20px 20px')->has('cssPresets', 7));

        // Clearing every tablet override removes the row; PC keeps its complete values.
        $this->post('/admin/banners/'.$id, [...$this->payload(['layouts' => ['tablet' => ['paddings' => '', 'title_size' => '']]]), 'version' => 1])->assertSessionHasNoErrors();
        self::assertNull(DB::table('banner_layouts')->where(['banner_id' => $id, 'device' => 'tablet'])->first());
        // A stale editor cannot overwrite the newer version.
        $this->post('/admin/banners/'.$id, [...$this->payload(), 'version' => 1])->assertSessionHasErrors('version');
        self::assertSame(2, (int) DB::table('banners')->where('id', $id)->value('lock_version'));
    }

    public function test_invalid_values_are_refused_with_field_errors(): void
    {
        $this->post('/admin/banners', $this->payload(['names' => ['en' => '']]))->assertSessionHasErrors('names.en');
        $this->post('/admin/banners', $this->payload(['names' => ['fr' => 'Inactive']]))->assertSessionHasErrors('names');
        $this->post('/admin/banners', $this->payload(['layouts' => ['pc' => ['paddings' => '10em']]]))->assertSessionHasErrors('layouts.pc.paddings');
        $this->post('/admin/banners', $this->payload(['layouts' => ['tablet' => ['valign' => 'middle']]]))->assertSessionHasErrors('layouts.tablet.valign');
        $this->post('/admin/banners', $this->payload(['layouts' => ['pc' => ['css_classes' => 'a"><b']]]))->assertSessionHasErrors('layouts.pc.css_classes');
        $this->post('/admin/banners', $this->payload(['title_color' => 'red']))->assertSessionHasErrors('title_color');
        $this->post('/admin/banners', $this->payload(['url' => 'javascript:alert(1)']))->assertSessionHasErrors('url');
        $this->post('/admin/banners', $this->payload(['title_tag' => 'script']))->assertSessionHasErrors('title_tag');
        $this->post('/admin/banners', $this->payload(['products_columns' => 9]))->assertSessionHasErrors('products_columns');
        $this->post('/admin/banners', $this->payload(['button_hover' => 'explode']))->assertSessionHasErrors('button_hover');
        foreach (['2.1', '-0.1', 'abc', '0.25', '3'] as $bad) {
            $this->post('/admin/banners', $this->payload(['button_transition' => $bad]))->assertSessionHasErrors('button_transition');
        }
        $this->post('/admin/banners', $this->payload(['contents' => ['pc' => ['en' => ['youtube_video' => 'https://vimeo.com/1']]]]))->assertSessionHasErrors('contents.pc.en.youtube_video');
        $this->post('/admin/banners', $this->payload(['available' => '1', 'available_from' => '2026-10-10T10:00', 'available_until' => '2026-10-09T10:00']))->assertSessionHasErrors('available_until');
        $this->post('/admin/banners', $this->payload(['products' => [999999]]))->assertSessionHasErrors('products');
        $this->post('/admin/banners', $this->payload(['group_id' => 999999]))->assertSessionHasErrors('group_id');
        self::assertSame(0, DB::table('banners')->count());
        // PostgreSQL refuses malformed rows regardless of the application.
        $id = $this->create();
        $this->expectException(QueryException::class);
        DB::table('banner_layouts')->where(['banner_id' => $id, 'device' => 'pc'])->update(['paddings' => 'calc(1px)']);
    }

    public function test_youtube_products_customer_groups_and_availability_window(): void
    {
        $product = $this->product('Paper bag');
        $group = app(CustomerGroupAdministration::class)->create((string) $this->actor->id, new SaveCustomerGroupData(['en' => 'Wholesale'], [], 'active', false));
        $id = $this->create([
            'object_type' => 'youtube', 'youtube_autoplay' => '1',
            'contents' => ['pc' => ['en' => ['youtube_video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]],
            'products' => [$product], 'customer_groups' => [$group],
            'available' => '1', 'available_from' => now()->addDay()->format('Y-m-d\TH:i'), 'available_until' => '',
        ]);
        $en = DB::table('locales')->where('code', 'en')->value('id');
        self::assertSame('dQw4w9WgXcQ', DB::table('banner_contents')->where(['banner_id' => $id, 'device' => 'pc', 'locale_id' => $en])->value('youtube_video'));
        self::assertSame([(int) $product], DB::table('banner_products')->where('banner_id', $id)->pluck('product_id')->all());
        self::assertSame([(int) $group], DB::table('banner_customer_groups')->where('banner_id', $id)->pluck('group_id')->all());
        $this->get('/admin/banners?state=scheduled')->assertInertia(fn (Assert $p) => $p->where('banners.total', 1)->where('banners.data.0.state', 'scheduled'));
        $this->get('/admin/banners?state=live')->assertInertia(fn (Assert $p) => $p->where('banners.total', 0));
        $this->get('/admin/banners/'.$id.'/edit?product_search=paper')->assertInertia(fn (Assert $p) => $p->where('banner.products.0.name', 'Paper bag')->where('candidates.0.name', 'Paper bag'));
        // A trashed product can no longer be selected.
        DB::table('products')->where('id', $product)->update(['deleted_at' => now()]);
        $this->post('/admin/banners/'.$id, [...$this->payload(['products' => [$product]]), 'version' => 1])->assertSessionHasErrors('products');
    }

    public function test_a_black_friday_window_in_greek_time_shows_and_disappears_automatically(): void
    {
        // The editor sends the administrator's local times (Athens, UTC+2 in November) as ISO instants.
        $id = $this->create(['available' => '1', 'available_from' => '2026-11-27T00:00:00+02:00', 'available_until' => '2026-11-28T00:00:00+02:00']);
        $banner = DB::table('banners')->where('id', $id)->first();
        self::assertSame('2026-11-26 22:00:00', now()->parse($banner->available_from)->utc()->format('Y-m-d H:i:s'));
        $this->get('/admin/banners/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->where('banner.available_from', '2026-11-26T22:00:00Z')->where('banner.available_until', '2026-11-27T22:00:00Z'));
        $this->travelTo(now()->parse('2026-11-26T21:59:00Z'));
        self::assertSame('scheduled', $this->stateOf($id));
        $this->travelTo(now()->parse('2026-11-26T22:00:00Z'));
        self::assertSame('live', $this->stateOf($id));
        $this->travelTo(now()->parse('2026-11-27T21:59:59Z'));
        self::assertSame('live', $this->stateOf($id));
        $this->travelTo(now()->parse('2026-11-27T22:00:00Z'));
        self::assertSame('expired', $this->stateOf($id));
        // Still "active": nothing to switch off by hand, and nothing runs on a schedule.
        self::assertSame('active', DB::table('banners')->where('id', $id)->value('status'));
    }

    private function stateOf(string $id): string
    {
        $state = null;
        $this->get('/admin/banners?search=Summer')->assertInertia(function (Assert $p) use (&$state, $id): void {
            $rows = $p->toArray()['props']['banners']['data'];
            $state = collect($rows)->firstWhere('id', $id)['state'] ?? null;
        });

        return (string) $state;
    }

    public function test_images_and_mp4_videos_are_private_owned_and_validated(): void
    {
        Storage::fake('local');
        $video = UploadedFile::fake()->createWithContent('bg.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\x00", 64));
        $id = $this->create(['layouts' => ['pc' => ['background_type' => 'video']]], ['contents' => array_replace_recursive($this->payload()['contents'], ['pc' => ['en' => [
            'main_image' => UploadedFile::fake()->createWithContent('main.png', base64_decode(self::PNG)),
            'background_video' => $video,
        ]]])]);
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $row = DB::table('banner_contents')->where(['banner_id' => $id, 'device' => 'pc', 'locale_id' => $en])->first();
        self::assertNotNull($row->main_image_media_id);
        self::assertNotNull($row->background_video_id);
        $this->get('/admin/banners/'.$id.'/media/'.$row->main_image_media_id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/admin/banners/'.$id.'/videos/'.$row->background_video_id)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $row->main_image_media_id));
        // Another banner cannot read this banner's files through its own URL.
        $other = $this->create(['names' => ['en' => 'Other']]);
        $this->get('/admin/banners/'.$other.'/media/'.$row->main_image_media_id)->assertNotFound();
        $this->get('/admin/banners/'.$other.'/videos/'.$row->background_video_id)->assertNotFound();
        $this->get('/admin/banners/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->where('banner.contents.pc.en.main_image.id', (string) $row->main_image_media_id)->where('banner.contents.pc.en.background_video.id', (string) $row->background_video_id));
        $this->get('/admin/banners')->assertInertia(fn (Assert $p) => $p->where('banners.data.0.thumbnail', '/admin/banners/'.$id.'/media/'.$row->main_image_media_id));

        // Disguised files are refused; removal keeps the stored media for the retention policy.
        $this->post('/admin/banners/'.$id, [...$this->payload(), 'version' => 1, 'contents' => ['pc' => ['en' => ['background_video' => UploadedFile::fake()->createWithContent('x.mp4', 'not a video')]]]])->assertSessionHasErrors('contents.pc.en.background_video');
        $this->post('/admin/banners/'.$id, [...$this->payload(), 'version' => 1, 'contents' => ['pc' => ['en' => ['main_image' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')]]]])->assertSessionHasErrors('contents.pc.en.main_image');
        $this->post('/admin/banners/'.$id, array_replace_recursive([...$this->payload(), 'version' => 1], ['contents' => ['pc' => ['en' => ['remove_main_image' => '1']]]]))->assertSessionHasNoErrors();
        self::assertNull(DB::table('banner_contents')->where(['banner_id' => $id, 'device' => 'pc', 'locale_id' => $en])->value('main_image_media_id'));
        self::assertTrue(DB::table('media_files')->where('id', $row->main_image_media_id)->exists());
        self::assertSame(1, DB::table('media_videos')->count());
    }

    public function test_clone_bulk_actions_groups_listing_and_delete(): void
    {
        $first = $this->create();
        $this->post('/admin/banners/'.$first.'/clone')->assertRedirect();
        $copy = (string) DB::table('banners')->max('id');
        self::assertSame('disabled', DB::table('banners')->where('id', $copy)->value('status'));
        self::assertSame('[CLONE] Summer sale', DB::table('banner_translations')->where('banner_id', $copy)->join('locales', 'locales.id', '=', 'banner_translations.locale_id')->where('locales.code', 'en')->value('banner_translations.name'));
        self::assertSame(DB::table('banner_contents')->where('banner_id', $first)->count(), DB::table('banner_contents')->where('banner_id', $copy)->count());
        self::assertSame(DB::table('banner_layouts')->where('banner_id', $first)->count(), DB::table('banner_layouts')->where('banner_id', $copy)->count());

        $this->post('/admin/banner-groups', ['name' => 'Homepage'])->assertSessionHasNoErrors();
        $this->post('/admin/banner-groups', ['name' => 'homepage'])->assertSessionHasErrors('name');
        $group = (string) DB::table('banner_groups')->value('id');
        $this->post('/admin/banners/bulk', ['ids' => [$first, $copy], 'action' => 'group', 'group' => $group])->assertSessionHasNoErrors();
        $this->post('/admin/banners/bulk', ['ids' => [$copy], 'action' => 'activate'])->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('banners')->where('group_id', $group)->where('status', 'active')->count());
        $loose = $this->create(['names' => ['en' => 'Loose']]);
        $this->get('/admin/banners')->assertInertia(fn (Assert $p) => $p->where('banners.total', 3)->where('banners.data.0.group.name', 'Homepage')->where('banners.data.2.id', $loose)->where('groups.0.banners', 2));
        $this->get('/admin/banners?group=none')->assertInertia(fn (Assert $p) => $p->where('banners.total', 1));
        $this->get('/admin/banners?search=clone')->assertInertia(fn (Assert $p) => $p->where('banners.total', 1)->where('banners.data.0.id', $copy));
        $this->put('/admin/banner-groups/'.$group, ['name' => 'Home'])->assertSessionHasNoErrors();
        $this->delete('/admin/banner-groups/'.$group)->assertSessionHasNoErrors();
        self::assertSame(3, DB::table('banners')->whereNull('group_id')->count());
        $this->post('/admin/banners/bulk', ['ids' => [$copy, $loose], 'action' => 'delete'])->assertSessionHasNoErrors();
        $this->delete('/admin/banners/'.$first, ['version' => 99])->assertSessionHasErrors('version');
        $version = (int) DB::table('banners')->where('id', $first)->value('lock_version');
        $this->delete('/admin/banners/'.$first, ['version' => $version])->assertRedirect('/admin/banners');
        self::assertSame(0, DB::table('banners')->count());
        self::assertSame(0, DB::table('banner_contents')->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'banner')->where('action', 'banner.created')->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'banner_group')->exists());
    }

    public function test_sample_hero_banners_are_created_once_with_bundled_photo_backgrounds(): void
    {
        Storage::fake('local');
        $this->artisan('cms:banners-demo', ['actor' => (string) $this->actor->id])->assertSuccessful();
        $this->artisan('cms:banners-demo', ['actor' => (string) $this->actor->id])->assertSuccessful();
        self::assertSame(3, DB::table('banners')->where('status', 'active')->count());
        self::assertSame(1, DB::table('banner_groups')->count());
        self::assertSame(3, DB::table('banner_layouts')->where('device', 'pc')->count());
        self::assertSame(3, DB::table('banner_layouts')->where('device', 'phone')->count());
        $media = DB::table('media_files')->whereIn('id', DB::table('banner_contents')->pluck('background_image_media_id'))->get();
        self::assertNotEmpty($media);
        self::assertContains('2400x900', $media->map(fn ($m) => $m->width.'x'.$m->height)->all());
        self::assertContains('1080x1350', $media->map(fn ($m) => $m->width.'x'.$m->height)->all());
        $el = DB::table('locales')->where('code', 'el')->value('id');
        self::assertStringContainsString('Nimbus Air 13', (string) DB::table('banner_contents')->where('locale_id', $el)->orderBy('banner_id')->value('title'));
        $this->get('/admin/banners')->assertInertia(fn (Assert $p) => $p->where('banners.total', 3)->where('banners.data.0.state', 'live')->where('banners.data.0.group.name', 'Homepage hero (sample)'));
    }

    public function test_view_only_staff_read_and_others_are_refused(): void
    {
        $id = $this->create();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'banners.view']));
        $this->get('/admin/banners')->assertOk();
        $this->get('/admin/banners/'.$id.'/edit')->assertOk();
        $this->get('/admin/banners/create')->assertForbidden();
        $this->post('/admin/banners', $this->payload())->assertForbidden();
        $this->post('/admin/banners/'.$id.'/clone')->assertForbidden();
        $this->post('/admin/banners/bulk', ['ids' => [$id], 'action' => 'disable'])->assertForbidden();
        $this->post('/admin/banner-groups', ['name' => 'X'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/banners')->assertForbidden();
        // The service authorizes on its own too.
        $this->expectException(AuthorizationException::class);
        app(BannerAdministration::class)->bulk((string) $this->staff(['admin.access', 'banners.view'])->id, [$id], 'disable');
    }

    private function product(string $name): string
    {
        $id = DB::table('products')->insertGetId(['type' => 'physical', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_translations')->insert(['product_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return (string) $id;
    }
}
