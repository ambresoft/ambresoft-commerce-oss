<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\RemoteImageDownload;
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

final class ProductImportTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'products.view', 'products.import', 'products.create', 'products.update', 'products.status']);
        $this->actingAs($this->actor);
        Storage::fake('local');
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Leaf', 'slug' => 'leaf']);
    }

    private function product(string $sku = 'SKU-1'): ProductRecord
    {
        $p = ProductRecord::create(['sku' => $sku, 'type' => 'physical', 'status' => 'disabled', 'price' => '10', 'quantity' => 7, 'category_id' => $this->category->id, 'mpn' => 'KEEP']);
        $p->translations()->create(['locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Original', 'slug' => strtolower($sku)]);

        return $p;
    }

    private function preview(string $csv): string
    {
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $r = $this->post('/admin/products/import/preview', ['file' => UploadedFile::fake()->createWithContent('products.csv', $csv), 'delimiter' => ',']);
        $r->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** @return array<string,mixed> */
    private function report(string $token): array
    {
        return Cache::get('product-import:'.$token)['report'];
    }

    /** @return array<string,mixed> */
    private function confirm(string $token): array
    {
        $this->post('/admin/products/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();

        return $this->report($token);
    }

    public function test_creates_products_and_matches_existing_sku_with_read_only_preview_and_partial_updates(): void
    {
        $product = $this->product();
        $id = $this->category->id;
        $token = $this->preview("sku,type,status,price,category_id,name.en\nSKU-NEW,physical,disabled,12.50,$id,New item\nsku-1,,,20,,\n");
        self::assertSame(0, $this->report($token)['errors'], json_encode($this->report($token)['issues']));
        self::assertSame(1, $this->report($token)['created']);
        self::assertSame(1, $this->report($token)['updated']);
        self::assertSame('10.000000', $product->fresh()->price);
        self::assertFalse(ProductRecord::where('sku', 'SKU-NEW')->exists());
        self::assertSame('completed', $this->confirm($token)['status']);
        $product->refresh();
        self::assertSame('20.000000', $product->price);
        self::assertSame('SKU-1', $product->sku);
        self::assertSame(7, $product->quantity);
        self::assertSame('KEEP', $product->mpn);
        self::assertSame('Original', $product->translations()->sole()->name);
        self::assertSame('New item', ProductRecord::where('sku', 'SKU-NEW')->sole()->translations()->sole()->name);
        $token = $this->preview("sku,quantity,mpn\nSKU-1,0,__CLEAR__\n");
        self::assertSame(0, $this->report($token)['errors'], json_encode($this->report($token)['issues']));
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame(0, $product->fresh()->quantity);
        self::assertNull($product->fresh()->mpn);
        self::assertTrue(DB::table('product_price_history')->where('product_id', $product->id)->exists());
        $this->post('/admin/products/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
    }

    public function test_reports_missing_required_new_fields_duplicate_skus_inactive_languages_and_bad_values(): void
    {
        $this->product();
        foreach (["sku,price\nNEW,2\n", "sku,price\nSKU-1,-1\n", "sku,price\nSKU-1,1\nsku-1,2\n", "sku,name.fr\nSKU-1,Bonjour\n", "sku,unknown\nSKU-1,x\n", "sku,sku\nSKU-1,SKU-1\n", "price\n3\n", "sku,image_url\nSKU-1,http://127.0.0.1/p.png\n", "sku,returnable\nSKU-1,maybe\n", "sku,price\nSKU-1,__CLEAR__\n"] as $csv) {
            $token = $this->preview($csv);
            self::assertGreaterThan(0, $this->report($token)['errors'], $csv);
            $this->post('/admin/products/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
        }
        self::assertSame('10.000000', ProductRecord::where('sku', 'SKU-1')->sole()->price);
    }

    public function test_stale_previews_fail_and_confirmation_rolls_back_every_row_on_product_rule_failure(): void
    {
        $p = $this->product();
        $token = $this->preview("sku,price\nSKU-1,20\n");
        $p->increment('lock_version');
        self::assertSame('failed', $this->confirm($token)['status']);
        self::assertSame('10.000000', $p->fresh()->price);
        $category = $this->category->id;
        $token = $this->preview("sku,type,status,price,category_id,name.en\nSKU-1,,,21,,\nDIGITAL,digital,active,12,$category,Digital item\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        self::assertSame('10.000000', $p->fresh()->price);
        self::assertFalse(ProductRecord::where('sku', 'DIGITAL')->exists());
    }

    public function test_permissions_ownership_template_cancellation_and_trashed_skus(): void
    {
        $p = $this->product();
        $this->get('/admin/products/import')->assertInertia(fn (Assert $p) => $p->component('Admin/Products/Import')->has('columns'));
        $this->get('/admin/products/import/template')->assertOk()->assertDownload('product-import-template.csv');
        $token = $this->preview("sku,price\nSKU-1,2\n");
        $this->actingAs($this->staff(['admin.access', 'products.import']));
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $this->get('/admin/products/import?token='.$token)->assertNotFound();
        $this->post('/admin/products/import/'.$token.'/confirm', ['confirmed' => true])->assertNotFound();
        $denied = $this->preview("sku,price\nSKU-1,3\n");
        self::assertGreaterThan(0, $this->report($denied)['errors']);
        $this->actingAs($this->actor);
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $path = Cache::get('product-import:'.$token)['path'];
        $this->delete('/admin/products/import/'.$token)->assertRedirect('/admin/products/import');
        Storage::disk('local')->assertMissing($path);
        $p->delete();
        self::assertGreaterThan(0, $this->report($this->preview("sku,price\nSKU-1,5\n"))['errors']);
        $this->actingAs($this->staff(['admin.access', 'products.update']));
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $this->get('/admin/products/import')->assertForbidden();
        $this->post('/admin/products/import/preview')->assertForbidden();
    }

    public function test_remote_primary_image_is_downloaded_only_on_confirmation_and_bad_download_rolls_back(): void
    {
        $this->product();
        app()->bind(RemoteImageDownload::class, fn () => new class(app(PrivateImageStorage::class)) extends RemoteImageDownload
        {
            protected function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $url = 'https://images.example.test/product.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=');
        Http::fake([$url => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $token = $this->preview("sku,image_url\nSKU-1,$url\n");
        self::assertSame(0, $this->report($token)['errors'], json_encode($this->report($token)['issues']));
        Http::assertNothingSent();
        self::assertSame('completed', $this->confirm($token)['status']);
        $image = DB::table('media_files')->join('product_images', 'media_files.id', '=', 'product_images.media_file_id')->first();
        self::assertTrue((bool) $image->is_primary);
        Storage::disk('local')->assertExists($image->storage_key);
        Http::fake([$url => Http::response('no image', 200)]);
        $token = $this->preview("sku,price,image_url\nSKU-1,999,$url\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        self::assertSame('10.000000', ProductRecord::where('sku', 'SKU-1')->sole()->price);
        self::assertSame(1, DB::table('product_images')->count());
    }
}
