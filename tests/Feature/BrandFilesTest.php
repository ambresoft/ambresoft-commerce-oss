<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\Jobs\CleanupProductFile;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class BrandFilesTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function pdf(string $name = 'size-guide.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    /** @return array<string,mixed> */
    private function fields(string $code = 'nike'): array
    {
        $texts = [];
        foreach (DB::table('locales')->where('is_active', true)->pluck('code') as $locale) {
            $texts[$locale] = ['name' => strtoupper($code), 'description' => '', 'image_alt' => '', 'banner_alt' => ''];
        }

        return ['code' => $code, 'status' => 'active', 'position' => 0, 'translations' => $texts];
    }

    private function brand(string $code = 'nike'): string
    {
        $this->post('/admin/brands', [...$this->fields($code), 'file_uploads' => [$this->pdf()]])->assertSessionHasNoErrors();

        return (string) DB::table('brands')->where('code', $code)->value('id');
    }

    /** @param array<string,mixed> $changes */
    private function save(string $id, array $changes = []): TestResponse
    {
        $row = DB::table('brands')->where('id', $id)->first();

        return $this->post('/admin/brands/'.$id, [...$this->fields($row->code), 'version' => $row->lock_version, ...$changes]);
    }

    public function test_upload_rename_replace_remove_and_cleanup_follow_brand_ownership(): void
    {
        $id = $this->brand();
        $file = DB::table('brand_files')->where('brand_id', $id)->sole();
        $private = DB::table('private_product_files')->where('id', $file->private_file_id)->sole();
        self::assertSame('size-guide.pdf', $file->display_name);
        self::assertSame('size-guide.pdf', app(BrandQueries::class)->editor($id)['files'][0]['original_name']);
        $this->get('/admin/brands/'.$id.'/files/'.$file->id)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        (new CleanupProductFile((string) $private->id))->handle();
        Storage::disk('local')->assertExists($private->storage_key);
        $this->save($id, ['files_submitted' => true, 'files' => [['id' => $file->id, 'display_name' => 'Shoe size guide']]])->assertSessionHasNoErrors();
        self::assertSame('Shoe size guide', DB::table('brand_files')->where('brand_id', $id)->value('display_name'));
        $this->save($id)->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('brand_files')->where('brand_id', $id)->count());
        $file = DB::table('brand_files')->where('brand_id', $id)->sole();
        $this->save($id, ['files' => [['id' => $file->id, 'display_name' => 'Shoe size guide', 'replacement' => 0]], 'file_uploads' => [$this->pdf('updated.pdf')]])->assertSessionHasNoErrors();
        $replacement = DB::table('brand_files')->where('brand_id', $id)->sole();
        self::assertNotSame($private->id, $replacement->private_file_id);
        (new CleanupProductFile((string) $private->id))->handle();
        Storage::disk('local')->assertMissing($private->storage_key);
        $this->save($id, ['files_submitted' => true])->assertSessionHasNoErrors();
        self::assertSame(0, DB::table('brand_files')->where('brand_id', $id)->count());
        (new CleanupProductFile((string) $replacement->private_file_id))->handle();
        self::assertFalse(DB::table('private_product_files')->where('id', $replacement->private_file_id)->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'brand_file')->exists());
    }

    public function test_invalid_files_foreign_associations_stale_edits_and_permissions_are_rejected(): void
    {
        $first = $this->brand();
        $other = $this->brand('other');
        $foreign = DB::table('brand_files')->where('brand_id', $other)->sole();
        $this->save($first, ['files' => [['id' => $foreign->id, 'display_name' => 'Stolen']]])->assertSessionHasErrors('files');
        self::assertSame(1, DB::table('brand_files')->where('brand_id', $first)->count());
        $this->save($first, ['file_uploads' => [UploadedFile::fake()->createWithContent('fake.pdf', '<html>unsafe</html>')]])->assertSessionHasErrors('file_uploads');
        $this->save($first, ['version' => 0, 'file_uploads' => [$this->pdf()]])->assertSessionHasErrors('version');
        $this->get('/admin/brands/'.$first.'/files/'.$foreign->id)->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'brands.view']));
        $this->save($first, ['file_uploads' => [$this->pdf()]])->assertForbidden();
    }

    public function test_brand_documents_are_listed_on_products_and_served_only_for_their_current_visible_brand(): void
    {
        $brand = $this->brand();
        $file = DB::table('brand_files')->where('brand_id', $brand)->sole();
        $category = CategoryRecord::create(['status' => 'active', 'position' => 1]);
        $locale = DB::table('locales')->where('is_default', true)->value('id');
        $category->translations()->create(['locale_id' => $locale, 'name' => 'Shoes', 'slug' => 'shoes']);
        $product = ProductRecord::create(['brand_id' => $brand, 'category_id' => $category->id, 'sku' => 'NIKE-DEMO', 'status' => 'active', 'price' => '80', 'quantity' => 5]);
        $product->translations()->create(['locale_id' => $locale, 'name' => 'Demo shoes', 'slug' => 'demo-shoes']);
        auth()->logout();
        $this->get('/shoes/demo-shoes')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertInertia(function (Assert $page) use ($file): void {
            $regions = $page->toArray()['props']['storefront']['regions'];
            $blocks = collect($regions['content'])->flatMap(fn ($grid) => $grid['blocks']);
            $data = $blocks->firstWhere('template', 'main_content')['content']['product'];
            self::assertSame((string) $file->id, $data['brand_documents'][0]['id']);
            self::assertSame([], $data['documents']);
        });
        $url = '/storefront/products/'.$product->id.'/brand-documents/'.$file->id;
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        $product->update(['brand_id' => null]);
        $this->get($url)->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
        $product->update(['brand_id' => $brand, 'status' => 'hidden']);
        $this->get($url)->assertNotFound();
        $product->update(['status' => 'active']);
        DB::table('brands')->where('id', $brand)->update(['status' => 'disabled']);
        $this->get($url)->assertNotFound();
    }

    public function test_brand_deletion_schedules_unreferenced_files_for_cleanup(): void
    {
        $id = $this->brand();
        $file = DB::table('brand_files')->where('brand_id', $id)->sole();
        $version = DB::table('brands')->where('id', $id)->value('lock_version');
        $this->delete('/admin/brands/'.$id, ['version' => $version])->assertSessionHasNoErrors();
        self::assertFalse(DB::table('brand_files')->where('brand_id', $id)->exists());
        (new CleanupProductFile((string) $file->private_file_id))->handle();
        self::assertFalse(DB::table('private_product_files')->where('id', $file->private_file_id)->exists());
    }
}
