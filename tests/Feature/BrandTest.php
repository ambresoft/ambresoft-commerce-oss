<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveBrandData;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\BrandDemoSeeder;
use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\Brands;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Infrastructure\PaymentSettings;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\FirstPartyBlock;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class BrandTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Cache::flush();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function payload(string $code = 'apple'): array
    {
        return ['code' => $code, 'status' => 'active', 'position' => 0, 'translations' => ['en' => ['name' => ucfirst($code), 'description' => '<p>Brand <strong>description</strong></p>', 'image_alt' => ucfirst($code)], 'el' => ['name' => ucfirst($code), 'description' => '<p>Περιγραφή μάρκας</p>', 'image_alt' => 'Σήμα']]];
    }

    private function brand(string $code = 'apple'): string
    {
        $this->post('/admin/brands', $this->payload($code))->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('brands')->where('code', $code)->value('id');
    }

    private function block(string $type, array $settings = [], array $selections = [], int $position = 1): array
    {
        $definition = app(StorefrontRegistry::class)->type($type)->definition();

        return ['id' => (string) Str::uuid(), 'grid_id' => app(LayoutStore::class)->read('homepage')['grids'][0]['id'], 'position' => $position, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true, 'block' => ['id' => (string) Str::uuid(), 'type' => $type, 'template' => $definition['templates'][0], 'name' => 'Internal brand section', 'css_class' => '', 'settings' => array_replace($definition['defaults'], $settings), 'selections' => $selections, 'translations' => ['en' => ['title' => 'Popular', 'html' => '', 'link_text' => 'All products'], 'el' => ['title' => 'Δημοφιλή', 'html' => '', 'link_text' => 'Όλα']]]];
    }

    private function saveBlocks(array $blocks): void
    {
        $layout = app(LayoutStore::class)->read('homepage');
        $layout['placements'] = $blocks;
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasNoErrors()->assertRedirect();
    }

    private function sections(): array
    {
        return json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null)), true)['regions']['content'][0]['blocks'] ?? [];
    }

    private function assignedProduct(string $brand): ProductRecord
    {
        $p = $this->orderProduct('100', 100);
        $p->update(['brand_id' => $brand]);

        return $p;
    }

    private function sale(ProductRecord $product, int $quantity = 1, bool $paid = true): string
    {
        app(PaymentSettings::class)->ensure();
        $id = app(OrderAdministration::class)->create(null, $this->orderInput((string) $product->id, ['items' => [['product_id' => (string) $product->id, 'quantity' => $quantity]]]));
        if ($paid) {
            DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $id, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => DB::table('orders')->where('id', $id)->value('total'), 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_brand_catalog_crud_localization_upload_validation_and_permissions(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG));
        $data = $this->payload();
        $data['translations']['en']['description'] .= '<script>bad()</script><a href="javascript:1">link</a>';
        $this->post('/admin/brands', [...$data, 'image' => $file])->assertSessionHasNoErrors();
        $id = (string) DB::table('brands')->where('code', 'apple')->value('id');
        $this->get('/admin/brands/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Brands/Form')->where('brand.translations.el.name', 'Apple')->has('brand.image'));
        $this->get('/admin/brands?search=app&sort=name')->assertInertia(fn (Assert $p) => $p->has('brands.data', 1));
        $description = DB::table('brand_translations')->where('brand_id', $id)->value('description');
        $this->assertStringNotContainsString('<script', $description);
        $this->assertStringNotContainsString('javascript:', $description);
        $this->post('/admin/brands', $this->payload())->assertSessionHasErrors('code');
        $bad = $this->payload('other');
        unset($bad['translations']['el']);
        $this->post('/admin/brands', $bad)->assertSessionHasErrors('translations.el');
        $this->post('/admin/brands', [...$this->payload('unsafe'), 'image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"/>')])->assertSessionHasErrors();
        $this->post('/admin/brands/'.$id, [...$this->payload(), 'version' => 1, 'position' => 4])->assertSessionHasNoErrors();
        $this->patch('/admin/brands/'.$id.'/status', ['status' => 'disabled', 'version' => 1])->assertSessionHasErrors('version');
        $this->patch('/admin/brands/'.$id.'/status', ['status' => 'disabled', 'version' => 2])->assertSessionHasNoErrors();
        $this->get('/admin/brands/'.$id.'/image')->assertOk();
        $this->get('/storefront/media/brand/'.$id.'/'.DB::table('brands')->where('id', $id)->value('image_media_id'))->assertNotFound();
        $this->assertTrue(DB::table('audit_logs')->where('entity_type', 'brand')->exists());
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'brands.view']));
        $this->get('/admin/brands')->assertOk();
        $this->post('/admin/brands', $this->payload('blocked'))->assertForbidden();
        $this->patch('/admin/brands/'.$id.'/status', ['status' => 'active', 'version' => 3])->assertForbidden();
        $this->delete('/admin/brands/'.$id, ['version' => 3])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff());
        $this->get('/admin/brands')->assertForbidden();
    }

    public function test_product_editor_assignment_preservation_clone_and_brand_deletion_integrity(): void
    {
        $brand = $this->brand();
        $p = $this->orderProduct('100')->fresh();
        $fields = ['brand_id' => $brand, 'sku' => $p->sku, 'type' => 'physical', 'status' => 'active', 'price' => '100.00', 'category_id' => (string) $p->category_id, 'translations' => ['en' => ['name' => 'Branded product']]];
        app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->lock_version, $fields));
        $this->assertSame((int) $brand, $p->fresh()->brand_id);
        $this->get('/admin/products/'.$p->id.'/edit')->assertInertia(fn (Assert $v) => $v->where('product.brand_id', $brand)->has('options.brands', 1));
        $this->get('/admin/products?brand_id='.$brand)->assertInertia(fn (Assert $v) => $v->has('products.data', 1));
        unset($fields['brand_id']);
        app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->fresh()->lock_version, $fields));
        $this->assertSame((int) $brand, $p->fresh()->brand_id);
        $copy = app(ProductAdministration::class)->duplicate((string) $this->actor->id, (string) $p->id, $p->fresh()->lock_version);
        $this->assertDatabaseHas('products', ['id' => $copy, 'brand_id' => $brand]);
        app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->fresh()->lock_version, [...$fields, 'brand_id' => '']));
        self::assertNull($p->fresh()->brand_id);
        $this->delete('/admin/brands/'.$brand, ['version' => 1])->assertSessionHasErrors('brand');
        DB::table('products')->where('brand_id', $brand)->update(['brand_id' => null]);
        $this->delete('/admin/brands/'.$brand, ['version' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('brands', ['id' => $brand]);
        $this->assertDatabaseMissing('brand_translations', ['brand_id' => $brand]);
    }

    public function test_brand_translations_preserve_inactive_content_and_follow_configured_default(): void
    {
        $id = $this->brand();
        LocaleRecord::where('code', 'en')->update(['is_default' => false]);
        LocaleRecord::where('code', 'el')->update(['is_default' => true]);
        LocaleRecord::where('code', 'en')->update(['is_active' => false]);
        $data = $this->payload();
        unset($data['translations']['en']);
        $data['translations']['el']['name'] = 'Μήλο';
        app(Brands::class)->save((string) $this->actor->id, $id, new SaveBrandData([...$data, 'version' => 1]));
        self::assertSame('Apple', DB::table('brand_translations')->where('brand_id', $id)->where('locale_id', LocaleRecord::where('code', 'en')->value('id'))->value('name'));
        self::assertSame('Μήλο', app(BrandQueries::class)->named('fr')->where('b.id', $id)->first()->name);
        $this->get('/admin/brands/'.$id.'/edit')->assertInertia(fn (Assert $v) => $v->where('defaultLocale', 'el')->where('brand.translations.el.name', 'Μήλο'));
    }

    public function test_opt_in_brand_examples_are_idempotent_and_preserve_existing_assignments(): void
    {
        $existing = $this->brand('existing');
        $apple = $this->orderProduct('100')->fresh();
        $kept = $this->assignedProduct($existing);
        $unknown = $this->orderProduct('100')->fresh();
        DB::table('product_translations')->where('product_id', $apple->id)->update(['name' => 'iPhone 18 Pro']);
        DB::table('product_translations')->where('product_id', $kept->id)->update(['name' => 'Samsung Galaxy']);
        DB::table('product_translations')->where('product_id', $unknown->id)->update(['name' => 'Generic accessory']);
        $products = DB::table('products')->count();
        $orders = DB::table('orders')->count();
        self::assertSame(1, app(BrandDemoSeeder::class)->run((string) $this->actor->id));
        self::assertSame(0, app(BrandDemoSeeder::class)->run((string) $this->actor->id));
        $brand = DB::table('brands')->where('code', 'apple')->sole();
        self::assertSame((int) $brand->id, $apple->fresh()->brand_id);
        self::assertNotNull($brand->image_media_id);
        self::assertSame((int) $existing, $kept->fresh()->brand_id);
        self::assertNull($unknown->fresh()->brand_id);
        self::assertSame($products, DB::table('products')->count());
        self::assertSame($orders, DB::table('orders')->count());
    }

    public function test_brand_product_blocks_share_every_product_setting_and_enforce_product_ownership(): void
    {
        $apple = $this->brand();
        $other = $this->brand('other');
        $a = $this->assignedProduct($apple);
        $b = $this->assignedProduct($apple);
        $wrong = $this->assignedProduct($other);
        $product = app(StorefrontRegistry::class)->type('products')->definition();
        $branded = app(StorefrontRegistry::class)->type('brand_products')->definition();
        self::assertSame($product['fields'], array_values(array_filter(array_slice($branded['fields'], 1), fn ($field) => $field['key'] !== 'paid_ad')));
        self::assertSame([...$product['defaults'], 'paid_ad' => false], $branded['defaults']);
        $block = $this->block('brand_products', ['source' => 'manual', 'show_rating' => true, 'show_sku' => true, 'per_row' => 3], ['brand' => [$apple], 'items' => [(string) $b->id, (string) $a->id]]);
        $this->saveBlocks([$block]);
        $section = $this->sections()[0];
        self::assertSame([(string) $b->id, (string) $a->id], array_column($section['content']['items'], 'id'));
        self::assertSame('Apple', $section['content']['brand']['name']);
        self::assertTrue($section['content']['display']['show_rating']);
        self::assertSame(3, $section['content']['display']['per_row']);
        $this->get('/admin/layouts/homepage?kind=products&brand_id='.$apple)->assertInertia(fn (Assert $v) => $v->has('options.products', 2));
        $layout = app(LayoutStore::class)->read('homepage');
        $layout['placements'][0]['block']['selections']['items'] = [(string) $wrong->id];
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        $b->update(['brand_id' => $other]);
        self::assertSame([(string) $a->id], array_column($this->sections()[0]['content']['items'], 'id'));
        DB::table('brands')->where('id', $apple)->update(['status' => 'disabled']);
        self::assertSame([], $this->sections());
    }

    public function test_brands_block_can_show_one_brands_bestselling_products_and_return_to_logos(): void
    {
        $apple = $this->brand();
        $other = $this->brand('other');
        $a = $this->assignedProduct($apple);
        $b = $this->assignedProduct($apple);
        $o = $this->assignedProduct($other);
        $this->sale($a, 2);
        $this->sale($b, 5);
        $this->sale($o, 10);
        $this->sale($a, 20, false);
        $block = $this->block('brands', ['source' => 'bestsellers', 'limit' => 2], ['brand' => [$apple], 'items' => [$other, $apple]]);
        $this->saveBlocks([$block]);
        $section = $this->sections()[0];
        self::assertSame($apple, $section['content']['brand']['id']);
        self::assertSame([(string) $b->id, (string) $a->id], array_column($section['content']['items'], 'id'));
        self::assertArrayHasKey('price', $section['content']['items'][0]);
        self::assertFalse($section['content']['paid_ad']);
        self::assertTrue($section['content']['is_bestsellers']);
        $productDefaults = (new FirstPartyBlock('products'))->definition()['defaults'];
        foreach (['per_row', 'image_width', 'image_height', 'show_price', 'show_add_to_cart', 'show_favorites'] as $key) {
            self::assertSame($productDefaults[$key], $section['content']['display'][$key]);
        }
        $block['block']['settings']['paid_ad'] = true;
        $block['block']['settings']['product_image_height'] = 320;
        $this->saveBlocks([$block]);
        self::assertSame(320, $this->sections()[0]['content']['display']['image_height']);
        self::assertTrue($this->sections()[0]['content']['paid_ad']);
        $block['block']['settings']['source'] = 'manual';
        $this->saveBlocks([$block]);
        self::assertSame([$other, $apple], array_column($this->sections()[0]['content']['items'], 'id'));
        self::assertArrayNotHasKey('brand', $this->sections()[0]['content']);
        self::assertSame(100, $this->sections()[0]['content']['display']['image_height']);
        $block['block']['settings']['source'] = 'bestsellers';
        $this->saveBlocks([$block]);
        DB::table('brands')->where('id', $apple)->update(['status' => 'disabled']);
        self::assertSame([], $this->sections());
    }

    public function test_all_time_brand_and_product_rankings_exclude_unpaid_void_and_ineligible_products(): void
    {
        $apple = $this->brand();
        $other = $this->brand('other');
        $empty = $this->brand('empty');
        $a = $this->assignedProduct($apple);
        $b = $this->assignedProduct($apple);
        $o = $this->assignedProduct($other);
        $this->sale($a, 2);
        $this->sale($b, 5);
        $this->sale($o, 4);
        $this->sale($o, 10, false);
        $void = $this->sale($o, 12);
        DB::table('orders')->where('id', $void)->update(['status_id' => $this->orderStatus('cancelled')]);
        // Historical paid orders still count: ranking is explicitly all time.
        DB::table('order_payments')->update(['completed_at' => now()->subYears(2)]);
        $brands = $this->block('brands', ['source' => 'bestsellers']);
        $appleBlock = $this->block('brand_products', ['source' => 'bestsellers'], ['brand' => [$apple]], 2);
        $otherBlock = $this->block('brand_products', ['source' => 'bestsellers'], ['brand' => [$other]], 3);
        $this->saveBlocks([$brands, $appleBlock, $otherBlock]);
        $sections = $this->sections();
        self::assertSame([$apple, $other], array_column($sections[0]['content']['items'], 'id'));
        self::assertSame([(string) $b->id, (string) $a->id], array_column($sections[1]['content']['items'], 'id'));
        self::assertSame([(string) $o->id], array_column($sections[2]['content']['items'], 'id'));
        $this->sale($o, 5);
        DB::table('general_settings')->where('id', 1)->update(['bestsellers_days' => 90]);
        app()->forgetInstance(GeneralSettings::class);
        $recent = $this->sections();
        self::assertSame([$other], array_column($recent[0]['content']['items'], 'id'));
        self::assertCount(2, $recent); // Ranked brands and the other brand's products; old Apple sales disappear.
        DB::table('general_settings')->where('id', 1)->update(['bestsellers_days' => 0]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame([$other, $apple], array_column($this->sections()[0]['content']['items'], 'id'));
        $b->update(['status' => 'hidden']);
        self::assertSame([$other, $apple], array_column($this->sections()[0]['content']['items'], 'id'));
        DB::table('brands')->where('id', $other)->update(['status' => 'disabled']);
        self::assertSame([$apple], array_column($this->sections()[0]['content']['items'], 'id'));
        $a->delete();
        self::assertSame([], $this->sections());
        self::assertDatabaseHas('brands', ['id' => $empty]);
    }

    public function test_brand_blocks_handle_manual_order_missing_images_deleted_references_and_batch_queries(): void
    {
        $apple = $this->brand();
        $other = $this->brand('other');
        $a = $this->assignedProduct($apple);
        $o = $this->assignedProduct($other);
        $brands = $this->block('brands', ['source' => 'manual'], ['items' => [$other, $apple]]);
        $this->saveBlocks([$brands]);
        self::assertSame([$other, $apple], array_column($this->sections()[0]['content']['items'], 'id'));
        self::assertNull($this->sections()[0]['content']['items'][0]['image']);
        self::assertSame(['/other', '/apple'], array_column($this->sections()[0]['content']['items'], 'url'));
        $one = $this->block('brand_products', ['source' => 'newest'], ['brand' => [$apple]]);
        $this->saveBlocks([$one]);
        $this->sections();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->sections();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $two = $this->block('brand_products', ['source' => 'newest'], ['brand' => [$other]], 2);
        $this->saveBlocks([$one, $two]);
        $this->sections();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->sections();
        self::assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->saveBlocks([$brands]);
        DB::table('products')->where('id', $o->id)->update(['brand_id' => null]);
        $this->delete('/admin/brands/'.$other, ['version' => 1])->assertSessionHasNoErrors();
        self::assertSame([$apple], array_column($this->sections()[0]['content']['items'], 'id'));
        self::assertDatabaseMissing('storefront_block_selections', ['brand_id' => $other]);
    }

    public function test_brand_sale_source_uses_applicable_prices_and_private_products_do_not_rank(): void
    {
        $apple = $this->brand();
        $other = $this->brand('other');
        $a = $this->assignedProduct($apple);
        $b = $this->assignedProduct($other);
        foreach ([$a, $b] as $p) {
            app(ProductSales::class)->sync((string) $p->id, [['minimum_quantity' => 1, 'priority' => 1, 'kind' => 'percentage', 'amount' => '25']]);
        }
        $this->saveBlocks([$this->block('brand_products', ['source' => 'sale'], ['brand' => [$apple]]), $this->block('brand_products', ['source' => 'sale'], ['brand' => [$other]], 2)]);
        self::assertSame('75.00', $this->sections()[0]['content']['items'][0]['price']);
        self::assertSame([(string) $b->id], array_column($this->sections()[1]['content']['items'], 'id'));
        DB::table('product_sales')->where('product_id', $a->id)->update(['ends_on' => now()->subDays(1)->toDateString()]);
        self::assertSame($other, $this->sections()[0]['content']['brand']['id']);
        $this->sale($b, 5);
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('product_customer_groups')->insert(['product_id' => $b->id, 'group_id' => $group]);
        $this->saveBlocks([$this->block('brands', ['source' => 'bestsellers'])]);
        self::assertSame([], $this->sections());
    }

    public function test_brand_media_is_owner_checked_and_referenced_originals_cannot_be_removed(): void
    {
        $brand = $this->brand();
        $file = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'test/brand.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 1, 'height' => 1]);
        Storage::disk('local')->put('test/brand.png', 'pngbytes');
        DB::table('brands')->where('id', $brand)->update(['image_media_id' => $file->id]);
        $this->get('/storefront/media/brand/'.$brand.'/'.$file->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storefront/media/brand/999999/'.$file->id)->assertNotFound();
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $file->id));
        try {
            app(SharedMediaDeletion::class)->assertUnreferenced($file);
            self::fail('Referenced brand image deleted.');
        } catch (ValidationException) {
        }
        DB::table('brands')->where('id', $brand)->update(['status' => 'disabled']);
        $this->get('/storefront/media/brand/'.$brand.'/'.$file->id)->assertNotFound();
    }
}
