<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveBrandData;
use App\Catalog\Infrastructure\Brands;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class DataTransferExportTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actingAs($this->staff(['admin.access', 'products.view', 'categories.view', 'brands.view', 'brands.manage', 'regions.view']));
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => $this->localeId(), 'name' => 'Leaf', 'slug' => 'leaf']);
    }

    private function localeId(string $code = 'en'): int
    {
        return (int) DB::table('locales')->where('code', $code)->value('id');
    }

    private function product(string $sku, string $name, string $status = 'disabled'): ProductRecord
    {
        $product = ProductRecord::create(['sku' => $sku, 'type' => 'physical', 'status' => $status, 'price' => '10.500000', 'quantity' => 7, 'category_id' => $this->category->id]);
        $product->translations()->create(['locale_id' => $this->localeId(), 'name' => $name, 'slug' => strtolower($sku)]);

        return $product;
    }

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($content, 3))));
    }

    public function test_picker_lists_the_import_columns_and_the_scope(): void
    {
        $this->get('/admin/data-transfer/export/products?status=active&ids[]=5&bogus=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/DataTransfer/Export')->where('entity', 'products')->where('backHref', '/admin/products')
            ->where('columns', fn ($columns) => collect($columns)->contains('sku') && collect($columns)->contains('name.en') && ! collect($columns)->contains('image_url'))
            ->where('scope.filters.status', 'active')->where('scope.ids', ['5'])->missing('scope.filters.bogus'));
        $this->get('/admin/data-transfer/export/unknown')->assertNotFound();
    }

    public function test_only_the_selected_fields_are_exported_for_every_product(): void
    {
        $this->product('SKU-1', 'First');
        $this->product('SKU-2', '=HYPERLINK("x")');

        $response = $this->get('/admin/data-transfer/export/products/download?columns[]=name.en&columns[]=sku&delimiter=,');

        $response->assertOk();
        self::assertSame([['sku', 'name.en'], ['SKU-1', 'First'], ['SKU-2', '\'=HYPERLINK("x")']], $this->csv($response->streamedContent()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.exported']);
    }

    public function test_filters_and_explicit_selection_limit_the_exported_records(): void
    {
        $this->product('SKU-1', 'First', 'active');
        $second = $this->product('SKU-2', 'Second');
        $this->product('SKU-3', 'Third');

        $filtered = $this->get('/admin/data-transfer/export/products/download?columns[]=sku&delimiter=;&status=active');
        self::assertSame([['sku'], ['SKU-1']], array_map(fn (array $row): array => str_getcsv($row[0], ';', '"', ''), $this->csv($filtered->streamedContent())));
        $selected = $this->get('/admin/data-transfer/export/products/download?columns[]=sku&delimiter=,&ids[]='.$second->id);
        self::assertSame([['sku'], ['SKU-2']], $this->csv($selected->streamedContent()));
    }

    public function test_numbers_are_trimmed_and_booleans_use_the_import_vocabulary(): void
    {
        $this->product('SKU-1', 'First');

        $rows = $this->csv($this->get('/admin/data-transfer/export/products/download?columns[]=price&columns[]=returnable&columns[]=quantity&delimiter=,')->streamedContent());

        self::assertSame(['10.5', 'false', '7'], [$rows[1][0], $rows[1][1], $rows[1][2]]);
    }

    public function test_every_column_of_every_entity_can_be_exported(): void
    {
        $this->product('SKU-1', 'First');
        app(Brands::class)->save((string) auth()->id(), null, new SaveBrandData(['code' => 'brand-one', 'status' => 'active', 'position' => 3, 'translations' => ['en' => ['name' => 'Brand', 'description' => '', 'image_alt' => ''], 'el' => ['name' => 'Μάρκα', 'description' => '', 'image_alt' => '']]]));
        DB::table('shipping_countries')->insertOrIgnore(['country_code' => 'GR']);
        DB::table('regions')->insert(['id' => 1, 'country_code' => 'GR', 'code' => 'A1', 'status' => 'active']);
        DB::table('region_translations')->insert(['region_id' => 1, 'locale_id' => $this->localeId(), 'name' => 'Attica']);

        foreach (['products' => 'SKU-1', 'categories' => 'Leaf', 'brands' => 'brand-one', 'regions' => 'Attica'] as $entity => $needle) {
            $columns = $this->get('/admin/data-transfer/export/'.$entity)->viewData('page')['props']['columns'];
            $rows = $this->csv($this->get('/admin/data-transfer/export/'.$entity.'/download?delimiter=,&'.http_build_query(['columns' => $columns]))->streamedContent());
            self::assertSame($columns, $rows[0], $entity);
            self::assertCount(2, $rows, $entity);
            self::assertStringContainsString($needle, implode(',', $rows[1]), $entity);
        }
    }

    public function test_invalid_requests_and_missing_capabilities_are_rejected(): void
    {
        $this->get('/admin/data-transfer/export/products/download?delimiter=,')->assertSessionHasErrors('columns');
        $this->get('/admin/data-transfer/export/products/download?columns[]=password&delimiter=,')->assertSessionHasErrors('columns.0');
        $this->get('/admin/data-transfer/export/products/download?columns[]=sku&delimiter=|')->assertSessionHasErrors('delimiter');
        $this->actingAs($limited = $this->staff(['admin.access', 'categories.view']));
        $this->withSession(['password_hash_web' => $limited->getAuthPassword()]);
        $this->get('/admin/data-transfer/export/products')->assertForbidden();
        $this->get('/admin/data-transfer/export/products/download?columns[]=sku&delimiter=,')->assertForbidden();
        $this->get('/admin/data-transfer/export/categories')->assertOk();
    }
}
