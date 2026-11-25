<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveBrandData;
use App\Catalog\Infrastructure\Brands;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class BrandImportTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'brands.import', 'brands.manage', 'brands.view']);
        $this->actingAs($this->actor);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function brand(): string
    {
        $texts = [];
        foreach (DB::table('locales')->where('is_active', true)->pluck('code') as $code) {
            $texts[$code] = ['name' => 'Original '.$code, 'description' => 'Keep', 'image_alt' => 'Logo'];
        }

        return app(Brands::class)->save((string) $this->actor->id, null, new SaveBrandData(['code' => 'brand-one', 'status' => 'active', 'position' => 3, 'banner_link' => '/brands/brand-one', 'translations' => $texts]));
    }

    private function preview(string $csv): string
    {
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $r = $this->post('/admin/brands/import/preview', ['file' => UploadedFile::fake()->createWithContent('brands.csv', $csv), 'delimiter' => ',']);
        $r->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** @return array<string,mixed> */
    private function report(string $token): array
    {
        return Cache::get('brand-import:'.$token)['report'];
    }

    /** @return array<string,mixed> */
    private function confirm(string $token): array
    {
        $this->post('/admin/brands/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();

        return $this->report($token);
    }

    public function test_code_upsert_partial_updates_clear_and_required_brand_fields(): void
    {
        $id = $this->brand();
        $token = $this->preview("code,status,position,name.en,name.el,description.en\nnew-brand,disabled,0,New,Νέα,\nBRAND-ONE,,,,,__CLEAR__\n");
        self::assertSame(0, $this->report($token)['errors'], json_encode($this->report($token)['issues']));
        self::assertSame(1, $this->report($token)['created']);
        self::assertSame(1, $this->report($token)['updated']);
        self::assertSame(1, DB::table('brands')->count());
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame(2, DB::table('brands')->count());
        self::assertSame(3, DB::table('brands')->where('id', $id)->value('position'));
        self::assertSame('/brands/brand-one', DB::table('brands')->where('id', $id)->value('banner_link'));
        self::assertSame('Original el', DB::table('brand_translations')->where('brand_id', $id)->where('locale_id', DB::table('locales')->where('code', 'el')->value('id'))->value('name'));
        self::assertSame('', DB::table('brand_translations')->where('brand_id', $id)->where('locale_id', DB::table('locales')->where('code', 'en')->value('id'))->value('description'));
        $this->post('/admin/brands/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
        foreach (["code\nmissing-fields\n", "code,status\nbrand-one,unknown\n", "code,name.fr\nbrand-one,Bad\n", "code,position\nbrand-one,1\nBRAND-ONE,2\n", "code,name.el\nbrand-one,__CLEAR__\n", "code,attachment_url\nbrand-one,http://127.0.0.1/a.pdf\n", "code,attachment_url,attachment_name\nbrand-one,https://example.test/a.exe,a.exe\n"] as $csv) {
            self::assertGreaterThan(0, $this->report($this->preview($csv))['errors'], $csv);
        }
    }

    public function test_images_and_attachments_download_after_confirmation_preserve_and_remove_assets(): void
    {
        $id = $this->brand();
        $logo = 'https://93.184.215.14/logo.png';
        $banner = 'https://93.184.215.14/banner.png';
        $document = 'https://93.184.215.14/guide.txt';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=');
        Http::fake([$logo => Http::response($png), $banner => Http::response($png), $document => Http::response('Brand instructions')]);
        $token = $this->preview("code,image_url,banner_image_url,attachment_url,attachment_name\nbrand-one,$logo,$banner,$document,manual.txt\n");
        self::assertSame(0, $this->report($token)['errors'], json_encode($this->report($token)['issues']));
        Http::assertNothingSent();
        self::assertSame('completed', $this->confirm($token)['status']);
        Http::assertSentCount(3);
        $row = DB::table('brands')->where('id', $id)->first();
        self::assertNotNull($row->image_media_id);
        self::assertNotNull($row->banner_image_media_id);
        $file = DB::table('brand_files as b')->join('private_product_files as f', 'f.id', '=', 'b.private_file_id')->first(['b.display_name', 'f.storage_key', 'f.ready']);
        self::assertSame('manual.txt', $file->display_name);
        self::assertTrue((bool) $file->ready);
        Storage::disk('local')->assertExists($file->storage_key);
        $token = $this->preview("code,position,image_url,attachment_url\nbrand-one,4,,\n");
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame($row->image_media_id, DB::table('brands')->where('id', $id)->value('image_media_id'));
        self::assertSame(1, DB::table('brand_files')->count());
        $token = $this->preview("code,remove_image,remove_banner_image,remove_attachments\nbrand-one,true,true,true\n");
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertNull(DB::table('brands')->where('id', $id)->value('image_media_id'));
        self::assertSame(0, DB::table('brand_files')->count());
    }

    public function test_invalid_attachment_or_stale_preview_applies_no_updates(): void
    {
        $id = $this->brand();
        $token = $this->preview("code,position\nbrand-one,7\n");
        DB::table('brands')->where('id', $id)->increment('lock_version');
        self::assertSame('failed', $this->confirm($token)['status']);
        $url = 'https://93.184.215.14/bad.pdf';
        Http::fake([$url => Http::response('<html>Not PDF</html>')]);
        $token = $this->preview("code,position,attachment_url\nbrand-one,8,$url\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        self::assertSame(3, DB::table('brands')->where('id', $id)->value('position'));
        self::assertSame(0, DB::table('private_product_files')->count());
    }

    public function test_database_failure_rolls_back_prior_rows_and_removes_their_new_assets(): void
    {
        $id = $this->brand();
        $image = 'https://93.184.215.14/logo.png';
        $attachment = 'https://93.184.215.14/guide.txt';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=');
        Http::fake([$image => Http::response($png), $attachment => Http::response('Brand instructions')]);
        $token = $this->preview("code,status,position,name.en,name.el,image_url,attachment_url\nbrand-one,,8,,,$image,$attachment\nreject-brand,active,0,Rejected,Απόρριψη,,\n");
        self::assertSame(0, $this->report($token)['errors']);
        // Force a late persistence failure after the first row has stored both assets.
        DB::statement("ALTER TABLE brands ADD CONSTRAINT brand_import_test_reject CHECK (code <> 'reject-brand')");
        try {
            self::assertSame('failed', $this->confirm($token)['status']);
        } finally {
            DB::statement('ALTER TABLE brands DROP CONSTRAINT brand_import_test_reject');
        }
        self::assertSame(1, DB::table('brands')->count());
        self::assertSame(3, DB::table('brands')->where('id', $id)->value('position'));
        self::assertNull(DB::table('brands')->where('id', $id)->value('image_media_id'));
        self::assertSame(0, DB::table('media_files')->count());
        self::assertSame(0, DB::table('private_product_files')->count());
        self::assertSame([], Storage::disk('local')->allFiles('images'));
        self::assertSame([], Storage::disk('local')->allFiles('product-files'));
    }

    public function test_permissions_token_ownership_template_and_cancellation(): void
    {
        $this->brand();
        $this->get('/admin/brands/import')->assertInertia(fn (Assert $p) => $p->component('Admin/Brands/Import')->has('columns'));
        $this->get('/admin/brands/import/template')->assertOk()->assertDownload('brand-import-template.csv');
        $token = $this->preview("code,position\nbrand-one,9\n");
        $this->actingAs($this->staff(['admin.access', 'brands.import']));
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $this->get('/admin/brands/import?token='.$token)->assertNotFound();
        self::assertGreaterThan(0, $this->report($this->preview("code,position\nbrand-one,4\n"))['errors']);
        $this->actingAs($this->actor);
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $path = Cache::get('brand-import:'.$token)['path'];
        $this->delete('/admin/brands/import/'.$token)->assertRedirect('/admin/brands/import');
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($this->staff(['admin.access', 'brands.manage']));
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $this->get('/admin/brands/import')->assertForbidden();
        $this->post('/admin/brands/import/preview')->assertForbidden();
    }
}
