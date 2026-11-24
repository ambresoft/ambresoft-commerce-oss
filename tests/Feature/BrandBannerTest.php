<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveBrandData;
use App\Catalog\Infrastructure\Brands;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\SharedMediaDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class BrandBannerTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=';

    private const HEADER = 'storefront.regions.content.0.blocks.0.content.brand_page.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actingAs($this->actor = $this->staff(super: true));
    }

    private function payload(): array
    {
        return ['code' => 'apple', 'status' => 'active', 'position' => 0, 'banner_link' => '/offers', 'translations' => ['en' => ['name' => 'Apple', 'description' => '', 'image_alt' => 'Apple logo', 'banner_alt' => 'Summer offers'], 'el' => ['name' => 'Apple', 'description' => '', 'image_alt' => 'Λογότυπο Apple', 'banner_alt' => 'Καλοκαιρινές προσφορές']]];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('banner.png', base64_decode(self::PNG));
    }

    private function brand(): object
    {
        $this->post('/admin/brands', [...$this->payload(), 'image' => $this->image(), 'banner_image' => $this->image()])->assertSessionHasNoErrors();

        return DB::table('brands')->where('code', 'apple')->sole();
    }

    public function test_banner_is_independent_of_the_logo_localized_and_published_with_its_link(): void
    {
        $brand = $this->brand();
        $this->assertNotSame($brand->image_media_id, $brand->banner_image_media_id);
        $url = '/storefront/media/brand/'.$brand->id.'/'.$brand->banner_image_media_id;
        $this->get('/admin/brands/'.$brand->id.'/edit')->assertInertia(fn (Assert $page) => $page->has('brand.banner_image')->where('brand.banner_link', '/offers')->where('brand.translations.el.banner_alt', 'Καλοκαιρινές προσφορές'));
        $this->get('/apple')->assertInertia(fn (Assert $page) => $page->where(self::HEADER.'banner.url', $url)->where(self::HEADER.'banner_link', '/offers')->where(self::HEADER.'banner_alt', 'Summer offers')->where(self::HEADER.'image.url', '/storefront/media/brand/'.$brand->id.'/'.$brand->image_media_id));
        $this->get('/locale/el');
        $this->get('/apple')->assertInertia(fn (Assert $page) => $page->where(self::HEADER.'banner_alt', 'Καλοκαιρινές προσφορές'));
        $this->get('/admin/brands/'.$brand->id.'/banner-image')->assertOk();
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storefront/media/brand/99999/'.$brand->banner_image_media_id)->assertNotFound();
        $this->assertTrue(app(SharedMediaDeletion::class)->referenced((string) $brand->banner_image_media_id));
        try {
            app(SharedMediaDeletion::class)->assertUnreferenced(MediaFileRecord::findOrFail($brand->banner_image_media_id));
            self::fail('A referenced banner was removable.');
        } catch (ValidationException) {
        }
        $audit = DB::table('audit_logs')->where('entity_type', 'brand')->where('entity_id', (string) $brand->id)->get();
        $this->assertStringContainsString('banner_image_media_id', $audit->toJson());
        $this->assertStringNotContainsString('/offers', $audit->toJson());
    }

    public function test_older_saves_preserve_banner_fields_and_removing_it_preserves_the_logo(): void
    {
        $brand = $this->brand();
        $old = $this->payload();
        unset($old['banner_link'], $old['translations']['en']['banner_alt'], $old['translations']['el']['banner_alt']);
        app(Brands::class)->save((string) $this->actor->id, (string) $brand->id, new SaveBrandData([...$old, 'version' => 1]));
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'banner_image_media_id' => $brand->banner_image_media_id, 'banner_link' => '/offers']);
        $this->assertDatabaseHas('brand_translations', ['brand_id' => $brand->id, 'banner_alt' => 'Summer offers']);
        $this->post('/admin/brands/'.$brand->id, [...$old, 'version' => 2, 'remove_banner_image' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'image_media_id' => $brand->image_media_id, 'banner_image_media_id' => null]);
        $this->get('/apple')->assertInertia(fn (Assert $page) => $page->where(self::HEADER.'banner', null)->where(self::HEADER.'banner_link', null));
        $this->get('/storefront/media/brand/'.$brand->id.'/'.$brand->banner_image_media_id)->assertNotFound();
        $this->get('/admin/brands/'.$brand->id.'/banner-image')->assertNotFound();
        $this->assertFalse(app(SharedMediaDeletion::class)->referenced((string) $brand->banner_image_media_id));
    }

    public function test_validation_versions_and_failed_dual_uploads_do_not_mutate_brand_or_leave_files(): void
    {
        $brand = $this->brand();
        foreach (['javascript:alert(1)', '//example.com', 'https://user:secret@example.com'] as $link) {
            $this->post('/admin/brands/'.$brand->id, [...$this->payload(), 'version' => 1, 'banner_link' => $link])->assertSessionHasErrors('banner_link');
        }
        $bad = $this->payload();
        $bad['translations']['en']['banner_alt'] = str_repeat('x', 201);
        $this->post('/admin/brands/'.$brand->id, [...$bad, 'version' => 1])->assertSessionHasErrors('translations.en.banner_alt');
        $before = Storage::disk('local')->allFiles();
        $this->post('/admin/brands/'.$brand->id, [...$this->payload(), 'version' => 1, 'image' => $this->image(), 'banner_image' => UploadedFile::fake()->createWithContent('bad.svg', '<svg onload="alert(1)"/>')])->assertSessionHasErrors('banner_image');
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('media_files', 2);
        $this->post('/admin/brands/'.$brand->id, [...$this->payload(), 'version' => 99, 'banner_image' => $this->image()])->assertSessionHasErrors('version');
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'lock_version' => 1, 'banner_image_media_id' => $brand->banner_image_media_id]);
    }

    public function test_banner_permissions_disabled_brand_and_empty_link_and_alt_fallback(): void
    {
        $brand = $this->brand();
        $data = $this->payload();
        $data['translations']['en']['banner_alt'] = '';
        $data['translations']['el']['banner_alt'] = '';
        $this->post('/admin/brands/'.$brand->id, [...$data, 'version' => 1, 'banner_link' => ''])->assertSessionHasNoErrors();
        $this->get('/apple')->assertInertia(fn (Assert $page) => $page->where(self::HEADER.'banner_alt', 'Apple')->where(self::HEADER.'banner_link', null));
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'brands.view']));
        $this->post('/admin/brands/'.$brand->id, [...$data, 'version' => 2, 'remove_banner_image' => true])->assertForbidden();
        $this->get('/admin/brands/'.$brand->id.'/banner-image')->assertOk();
        $this->flushSession();
        $this->actingAs($this->staff());
        $this->get('/admin/brands/'.$brand->id.'/banner-image')->assertForbidden();
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'disabled']);
        $this->get('/storefront/media/brand/'.$brand->id.'/'.$brand->banner_image_media_id)->assertNotFound();
    }

    public function test_additive_migration_round_trip_preserves_existing_brand_logo_and_texts(): void
    {
        $brand = $this->brand();
        $migration = require database_path('migrations/2026_11_13_000200_add_brand_banners.php');
        $migration->down();
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'image_media_id' => $brand->image_media_id]);
        $migration->up();
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'image_media_id' => $brand->image_media_id, 'banner_image_media_id' => null]);
        $this->assertDatabaseHas('brand_translations', ['brand_id' => $brand->id, 'name' => 'Apple', 'banner_alt' => '']);
    }
}
